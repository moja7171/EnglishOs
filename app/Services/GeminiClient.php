<?php

namespace App\Services;

use App\Services\Concerns\UsesOutboundProxy;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Thin wrapper around Gemini's generateContent REST API — the LLM behind
 * the AI Instructor (conversation, feedback, error extraction, mission
 * result decisions). See EOS-009 §8.
 */
class GeminiClient
{
    use UsesOutboundProxy;

    /** Sage and conversation replies: short, high volume, cheap models first. */
    public const PROFILE_CHAT = 'chat';

    /** Anything that grades the learner: quality first, lower volume. */
    public const PROFILE_JUDGE = 'judge';

    private const PROVIDER = 'gemini';

    private readonly string $apiKey;

    /**
     * Set only when the caller pinned models in the constructor (ad-hoc
     * probes such as the diagnostics report). A pinned chain ignores and
     * never touches the shared "unavailable" memory.
     *
     * @var list<array{model: string, thinking_level: ?string}>|null
     */
    private readonly ?array $pinnedChain;

    private readonly AiModelChain $chain;

    public function __construct(?string $apiKey = null, ?string $model = null, ?string $fallbackModel = null)
    {
        $this->apiKey = $apiKey ?? (string) config('services.gemini.key');
        $this->chain = new AiModelChain;

        $this->pinnedChain = ($model !== null || $fallbackModel !== null)
            ? self::parseChain([
                $model ?? (string) config('services.gemini.model', 'gemini-3.5-flash-lite'),
                $fallbackModel ?? (string) config('services.gemini.fallback_model', 'gemini-3.1-flash-lite'),
            ])
            : null;
    }

    /**
     * Sends a single-turn (or pre-built multi-turn) prompt and returns the
     * model's text reply.
     *
     * Walks the profile's ordered model chain: a model known to be
     * exhausted or down is skipped without a request; otherwise one attempt
     * is made, and a failure that is the model's own (quota, retired, 5xx,
     * timeout) marks it unavailable for a while and moves on to the next.
     * A failure that is ours (400, 401, 403) is thrown at once — another
     * model would fail the same way. The walk is bounded by the
     * services.gemini.max_attempts and total_budget settings so a learner
     * never waits long on a hard outage.
     *
     * @param  array<int, array{role: string, text: string}>  $messages  Chat history, oldest first. role is 'user' or 'model'.
     * @param  int|null  $maxOutputTokens  A hard cap on reply length, e.g. for a chat persona that
     *                                     must stay short (see ⚡ask-instructor's Sage prompt) — prompt wording alone doesn't
     *                                     reliably stop the model from drifting long. Omit for callers that need their full,
     *                                     uncapped answer (grading feedback, mission recaps, ...).
     * @param  string  $profile  PROFILE_CHAT (default) or PROFILE_JUDGE — which model chain to walk.
     */
    public function chat(array $messages, ?string $systemPrompt = null, ?int $maxOutputTokens = null, string $profile = self::PROFILE_CHAT): string
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY is not set.');
        }

        $payload = [
            'contents' => collect($messages)->map(fn (array $m) => [
                'role' => $m['role'],
                'parts' => [['text' => $m['text']]],
            ])->all(),
        ];

        if ($systemPrompt) {
            $payload['systemInstruction'] = ['parts' => [['text' => $systemPrompt]]];
        }

        if ($maxOutputTokens !== null) {
            $payload['generationConfig'] = ['maxOutputTokens' => $maxOutputTokens];
        }

        $maxAttempts = max(1, (int) config('services.gemini.max_attempts', 3));
        $budget = max(1, (int) config('services.gemini.total_budget', 20));
        $attemptTimeout = max(1, (int) config('services.gemini.attempt_timeout', 10));
        $startedAt = microtime(true);

        $failures = [];
        $lastError = null;

        foreach ($this->candidates($profile) as $entry) {
            if (count($failures) >= $maxAttempts) {
                break;
            }

            $remaining = $budget - (microtime(true) - $startedAt);

            if ($failures !== [] && $remaining < 2) {
                break;
            }

            try {
                $text = $this->attempt($entry, $payload, (int) max(2, min($attemptTimeout, ceil($remaining))));

                if ($this->pinnedChain === null) {
                    $this->chain->recordSuccess(self::PROVIDER, $entry['model']);
                }

                return $text;
            } catch (Throwable $e) {
                $lastError = $e;
                $verdict = $this->chain->classify($e);
                $failures[] = [
                    'model' => $entry['model'],
                    'kind' => $verdict['kind'],
                    'error' => mb_substr($e->getMessage(), 0, 300),
                ];

                if ($this->pinnedChain === null) {
                    $this->chain->recordFailure(self::PROVIDER, $entry['model'], $verdict['kind']);
                }

                if ($verdict['kind'] === AiModelChain::KIND_BAD_REQUEST) {
                    $this->logFailure($profile, $failures, $e);

                    throw $e;
                }

                $this->markUnavailable($entry['model'], $verdict);
            }
        }

        if ($lastError === null) {
            throw new RuntimeException("No Gemini model is configured for the \"{$profile}\" profile.");
        }

        $this->logFailure($profile, $failures, $lastError);

        throw $lastError;
    }

    /**
     * Parses "model" / "model:thinkingLevel" entries.
     *
     * @param  list<string>  $entries
     * @return list<array{model: string, thinking_level: ?string}>
     */
    public static function parseChain(array $entries): array
    {
        $chain = [];

        foreach ($entries as $entry) {
            $entry = trim($entry);

            if ($entry === '') {
                continue;
            }

            [$model, $level] = array_pad(explode(':', $entry, 2), 2, null);
            $model = trim((string) $model);

            if ($model === '' || collect($chain)->contains('model', $model)) {
                continue;
            }

            $chain[] = ['model' => $model, 'thinking_level' => $level !== null && trim($level) !== '' ? trim($level) : null];
        }

        return $chain;
    }

    /**
     * The profile's full configured chain, best first, with the legacy
     * model + fallback pair standing in when no list is configured.
     *
     * @return list<array{model: string, thinking_level: ?string}>
     */
    public function configuredChain(string $profile): array
    {
        if ($this->pinnedChain !== null) {
            return $this->pinnedChain;
        }

        $configured = self::parseChain((array) config("services.gemini.{$profile}_models", []));

        if ($configured !== []) {
            return $configured;
        }

        return self::parseChain([
            (string) config('services.gemini.model', 'gemini-3.5-flash-lite'),
            (string) config('services.gemini.fallback_model', 'gemini-3.1-flash-lite'),
        ]);
    }

    /**
     * The models this request may try, in order. Models marked unavailable
     * are skipped; when every one is marked, the one that heals soonest is
     * probed anyway so a stale mark can never turn a short blip into a
     * longer outage.
     *
     * @return list<array{model: string, thinking_level: ?string}>
     */
    private function candidates(string $profile): array
    {
        $chain = $this->configuredChain($profile);

        if ($this->pinnedChain !== null) {
            return $chain;
        }

        $usable = array_values(array_filter(
            $chain,
            fn (array $entry) => $this->chain->unavailableState(self::PROVIDER, $entry['model']) === null,
        ));

        if ($usable !== [] || $chain === []) {
            return $usable;
        }

        $healsAt = fn (array $entry): int => $this->chain->unavailableState(self::PROVIDER, $entry['model'])['until'] ?? 0;

        usort($chain, fn (array $a, array $b) => $healsAt($a) <=> $healsAt($b));

        return [$chain[0]];
    }

    /**
     * @param  array{kind: string, seconds: int}  $verdict
     */
    private function markUnavailable(string $model, array $verdict): void
    {
        if ($this->pinnedChain !== null) {
            return;
        }

        $isFreshMark = $this->chain->markUnavailable(self::PROVIDER, $model, $verdict['kind'], $verdict['seconds']);

        if ($isFreshMark) {
            // Production runs at LOG_LEVEL=error, which drops warnings — and
            // a model leaving the rotation is exactly what must stay visible.
            Log::error('GeminiClient: model taken out of rotation.', [
                'model' => $model,
                'reason' => $verdict['kind'],
                'skipped_for_seconds' => $verdict['seconds'],
            ]);
        }
    }

    /**
     * Callers catch these failures and show the learner a generic "couldn't
     * reach the AI service" line, and production runs at LOG_LEVEL=error —
     * so the real cause per model (relay down, 403/429/5xx, cURL timeout)
     * is recorded here, once per request that exhausted its chain. Never
     * logs request bodies or keys.
     *
     * @param  list<array{model: string, kind: string, error: string}>  $failures
     */
    private function logFailure(string $profile, array $failures, Throwable $finalError): void
    {
        Log::error('GeminiClient: request failed on every model.', [
            'profile' => $profile,
            'attempts' => $failures,
            'final_error_class' => $finalError::class,
            'relay' => (string) config('services.ai_proxy.url'),
        ]);
    }

    /**
     * One timeout-bounded attempt against a single model. Left to throw on
     * failure — chat() decides whether to try the next model.
     *
     * @param  array{model: string, thinking_level: ?string}  $entry
     */
    private function attempt(array $entry, array $payload, int $timeoutSeconds): string
    {
        if ($entry['thinking_level'] !== null) {
            $payload['generationConfig']['thinkingConfig'] = ['thinkingLevel' => $entry['thinking_level']];
        }

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$entry['model']}:generateContent";

        // 1 attempt per model: the next model in the chain IS the retry.
        $response = $this->withOutboundProxy(
            Http::withHeaders(['x-goog-api-key' => $this->apiKey])
                ->timeout($timeoutSeconds)
                ->retry(1, 500, throw: false),
            $url,
        )
            ->post($this->outboundUrl($url), $payload)
            ->throw();

        return data_get($response->json(), 'candidates.0.content.parts.0.text', '');
    }
}
