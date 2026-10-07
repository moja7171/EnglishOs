<?php

namespace App\Services;

use App\Services\Concerns\UsesOutboundProxy;
use Illuminate\Support\Facades\Http;
use RuntimeException;

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
            ? AiModelChain::parseChain([
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

        return $this->chain->walk(
            provider: self::PROVIDER,
            logLabel: 'GeminiClient',
            chain: $this->configuredChain($profile),
            attempt: fn (array $entry, int $timeout) => $this->attempt($entry, $payload, $timeout),
            limits: [
                'max_attempts' => (int) config('services.gemini.max_attempts', 3),
                'budget' => (int) config('services.gemini.total_budget', 20),
                'attempt_timeout' => (int) config('services.gemini.attempt_timeout', 10),
            ],
            tracked: $this->pinnedChain === null,
            logContext: ['profile' => $profile],
        );
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

        $configured = AiModelChain::parseChain((array) config("services.gemini.{$profile}_models", []));

        if ($configured !== []) {
            return $configured;
        }

        return AiModelChain::parseChain([
            (string) config('services.gemini.model', 'gemini-3.5-flash-lite'),
            (string) config('services.gemini.fallback_model', 'gemini-3.1-flash-lite'),
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
