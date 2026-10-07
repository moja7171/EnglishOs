<?php

namespace App\Services;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Shared bookkeeping for the ordered free-tier model chains behind
 * GeminiClient (and, later, GroqClient): which models are currently known
 * to be unusable and until when, plus per-day success/failure counters for
 * the admin status view — and the table that turns a provider failure into
 * "how long to skip this model".
 *
 * Free-tier quota is counted per model, so one exhausted model must not
 * cost every later request a failed round-trip: the first failure marks it
 * here, later requests skip it, and it heals by itself when the mark
 * expires. State lives in the cache (CACHE_STORE=database in production, so
 * it is shared across requests). Every cache access is best-effort — a
 * broken cache must never take an AI call down with it.
 */
class AiModelChain
{
    /** Daily quota is gone; skip until the provider says it resets. */
    public const KIND_DAILY_QUOTA = 'daily_quota';

    /** Per-minute style rate limit; skip for about the advertised delay. */
    public const KIND_RATE_LIMIT = 'rate_limit';

    /** The model no longer exists (404) — the configured list is stale. */
    public const KIND_RETIRED = 'retired';

    /** 5xx, timeouts, connection errors: skip for a few minutes. */
    public const KIND_TRANSIENT = 'transient';

    /** Our own bug (400, 401, 403, ...): not the model's fault, never walk the chain. */
    public const KIND_BAD_REQUEST = 'bad_request';

    private const RETIRED_SECONDS = 86400;

    private const TRANSIENT_SECONDS = 180;

    private const DEFAULT_RATE_LIMIT_SECONDS = 60;

    private const DEFAULT_DAILY_SECONDS = 21600;

    /**
     * Parses "model" / "model:thinkingLevel" entries (the level only means something to Gemini).
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
     * Walks a provider's ordered model chain for one request: a model known
     * to be down is skipped without a request; otherwise one attempt is
     * made, and a failure that is the model's own (quota, retired, 5xx,
     * timeout) marks it unavailable for a while and moves on to the next. A
     * failure that is ours (400, 401, 403) is thrown at once — another
     * model would fail the same way. Bounded by max_attempts and a total
     * time budget so a learner never waits long on a hard outage.
     *
     * @param  list<array{model: string}>  $chain  Best first; entries may carry extra keys the attempt reads.
     * @param  Closure(array, int): mixed  $attempt  Called with the chain entry and the timeout (seconds) to use.
     * @param  array{max_attempts: int, budget: int, attempt_timeout: int}  $limits
     * @param  bool  $tracked  False for pinned ad-hoc probes (diagnostics): they neither read nor write the shared memory.
     * @param  array<string, mixed>  $logContext  Extra fields for the failure log (never keys or request bodies).
     *
     * @throws Throwable The last attempt's error once the chain is exhausted.
     */
    public function walk(string $provider, string $logLabel, array $chain, Closure $attempt, array $limits, bool $tracked = true, array $logContext = []): mixed
    {
        $startedAt = microtime(true);
        $failures = [];
        $lastError = null;

        foreach ($this->candidates($provider, $chain, $tracked) as $entry) {
            if (count($failures) >= max(1, $limits['max_attempts'])) {
                break;
            }

            $remaining = max(1, $limits['budget']) - (microtime(true) - $startedAt);

            if ($failures !== [] && $remaining < 2) {
                break;
            }

            try {
                $result = $attempt($entry, (int) max(2, min(max(1, $limits['attempt_timeout']), ceil($remaining))));

                if ($tracked) {
                    $this->recordSuccess($provider, $entry['model']);
                }

                return $result;
            } catch (Throwable $e) {
                $lastError = $e;
                $verdict = $this->classify($e);
                $failures[] = [
                    'model' => $entry['model'],
                    'kind' => $verdict['kind'],
                    'error' => mb_substr($e->getMessage(), 0, 300),
                ];

                if ($tracked) {
                    $this->recordFailure($provider, $entry['model'], $verdict['kind']);
                }

                if ($verdict['kind'] === self::KIND_BAD_REQUEST) {
                    $this->logFailure($logLabel, $failures, $e, $logContext);

                    throw $e;
                }

                if ($tracked) {
                    $this->takeOutOfRotation($provider, $logLabel, $entry['model'], $verdict);
                }
            }
        }

        if ($lastError === null) {
            throw new RuntimeException("No {$provider} model is configured.");
        }

        $this->logFailure($logLabel, $failures, $lastError, $logContext);

        throw $lastError;
    }

    /**
     * The models this request may try, in order. Models marked unavailable
     * are skipped; when every one is marked, the one that heals soonest is
     * probed anyway so a stale mark can never turn a short blip into a
     * longer outage.
     *
     * @param  list<array{model: string}>  $chain
     * @return list<array{model: string}>
     */
    private function candidates(string $provider, array $chain, bool $tracked): array
    {
        if (! $tracked) {
            return $chain;
        }

        $usable = array_values(array_filter(
            $chain,
            fn (array $entry) => $this->unavailableState($provider, $entry['model']) === null,
        ));

        if ($usable !== [] || $chain === []) {
            return $usable;
        }

        $healsAt = fn (array $entry): int => $this->unavailableState($provider, $entry['model'])['until'] ?? 0;

        usort($chain, fn (array $a, array $b) => $healsAt($a) <=> $healsAt($b));

        return [$chain[0]];
    }

    /**
     * @param  array{kind: string, seconds: int}  $verdict
     */
    private function takeOutOfRotation(string $provider, string $logLabel, string $model, array $verdict): void
    {
        if (! $this->markUnavailable($provider, $model, $verdict['kind'], $verdict['seconds'])) {
            return;
        }

        // Production runs at LOG_LEVEL=error, which drops warnings — and a
        // model leaving the rotation is exactly what must stay visible.
        Log::error("{$logLabel}: model taken out of rotation.", [
            'model' => $model,
            'reason' => $verdict['kind'],
            'skipped_for_seconds' => $verdict['seconds'],
        ]);
    }

    /**
     * Callers catch these failures and show the learner a generic "couldn't
     * reach the AI service" line, and production runs at LOG_LEVEL=error —
     * so the real cause per model (relay down, 403/429/5xx, cURL timeout)
     * is recorded here, once per request that exhausted its chain. Never
     * logs request bodies or keys.
     *
     * @param  list<array{model: string, kind: string, error: string}>  $failures
     * @param  array<string, mixed>  $logContext
     */
    private function logFailure(string $logLabel, array $failures, Throwable $finalError, array $logContext): void
    {
        Log::error("{$logLabel}: request failed on every model.", $logContext + [
            'attempts' => $failures,
            'final_error_class' => $finalError::class,
            'relay' => (string) config('services.ai_proxy.url'),
        ]);
    }

    /**
     * Turns a failed provider call into a kind plus how many seconds the
     * model should be skipped. Reads the structured error details Google
     * sends with a 429 (QuotaFailure.quotaId, RetryInfo.retryDelay) rather
     * than matching the human-readable message, which is truncated in logs.
     *
     * @return array{kind: string, seconds: int}
     */
    public function classify(Throwable $error): array
    {
        if ($error instanceof ConnectionException) {
            return ['kind' => self::KIND_TRANSIENT, 'seconds' => self::TRANSIENT_SECONDS];
        }

        if (! $error instanceof RequestException) {
            return ['kind' => self::KIND_BAD_REQUEST, 'seconds' => 0];
        }

        $status = $error->response->status();

        if ($status === 429) {
            return $this->classifyQuota($error);
        }

        if ($status === 404) {
            return ['kind' => self::KIND_RETIRED, 'seconds' => self::RETIRED_SECONDS];
        }

        if ($status >= 500 || $status === 408) {
            return ['kind' => self::KIND_TRANSIENT, 'seconds' => self::TRANSIENT_SECONDS];
        }

        return ['kind' => self::KIND_BAD_REQUEST, 'seconds' => 0];
    }

    /**
     * @return array{kind: string, seconds: int}
     */
    private function classifyQuota(RequestException $error): array
    {
        $details = (array) $error->response->json('error.details', []);
        $quotaIds = [];
        $retryDelay = null;

        foreach ($details as $detail) {
            foreach ((array) ($detail['violations'] ?? []) as $violation) {
                $quotaIds[] = (string) ($violation['quotaId'] ?? '');
            }

            if (isset($detail['retryDelay'])) {
                $retryDelay = (int) $detail['retryDelay'];
            }
        }

        $retryDelay ??= $this->retryAfterHeader($error) ?? $this->retryDelayFromMessage((string) $error->response->json('error.message', ''));

        $isDaily = collect($quotaIds)->contains(fn (string $id) => str_contains($id, 'PerDay'))
            || ($retryDelay !== null && $retryDelay >= 3600);

        if ($isDaily) {
            return [
                'kind' => self::KIND_DAILY_QUOTA,
                'seconds' => max(60, min($retryDelay ?? self::DEFAULT_DAILY_SECONDS, 86400)),
            ];
        }

        return [
            'kind' => self::KIND_RATE_LIMIT,
            'seconds' => max(5, min($retryDelay ?? self::DEFAULT_RATE_LIMIT_SECONDS, 3600)),
        ];
    }

    /**
     * Last-resort parse of "Please retry in 11h8m5s" / "try again in 4m5.2s"
     * when the provider sent no structured retry delay (Groq puts it in the
     * message and the Retry-After header).
     */
    private function retryDelayFromMessage(string $message): ?int
    {
        if (! preg_match('/(?:retry|try again) in ((?:\d+h)?(?:\d+m(?!s))?(?:[\d.]+s)?)/i', $message, $match) || $match[1] === '') {
            return null;
        }

        preg_match('/(?:(\d+)h)?(?:(\d+)m(?!s))?(?:([\d.]+)s)?/', $match[1], $parts);

        return (int) ceil(((int) ($parts[1] ?? 0)) * 3600 + ((int) ($parts[2] ?? 0)) * 60 + (float) ($parts[3] ?? 0));
    }

    private function retryAfterHeader(RequestException $error): ?int
    {
        $header = $error->response->header('Retry-After');

        return is_numeric($header) ? (int) $header : null;
    }

    /**
     * Marks a model unusable for $seconds. Returns false when this model
     * was already marked (the caller logs the switch only on a fresh mark,
     * so a busy site logs one line per outage, not one per request).
     */
    public function markUnavailable(string $provider, string $model, string $kind, int $seconds): bool
    {
        try {
            $alreadyMarked = $this->unavailableState($provider, $model) !== null;

            Cache::put($this->stateKey($provider, $model), [
                'until' => now()->addSeconds($seconds)->getTimestamp(),
                'reason' => $kind,
                'since' => now()->getTimestamp(),
            ], $seconds);

            return ! $alreadyMarked;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array{until: int, reason: string, since: int}|null Null when the model is usable.
     */
    public function unavailableState(string $provider, string $model): ?array
    {
        try {
            $state = Cache::get($this->stateKey($provider, $model));
        } catch (Throwable) {
            return null;
        }

        if (! is_array($state) || ($state['until'] ?? 0) <= now()->getTimestamp()) {
            return null;
        }

        return $state;
    }

    public function recordSuccess(string $provider, string $model): void
    {
        $this->bumpStats($provider, $model, 'ok', null);
    }

    public function recordFailure(string $provider, string $model, string $kind): void
    {
        $this->bumpStats($provider, $model, 'fail', $kind);
    }

    /**
     * Today's counters for the status view.
     *
     * @return array{ok: int, fail: int, last_ok_at: ?int, last_fail_at: ?int, last_fail_reason: ?string}
     */
    public function todayStats(string $provider, string $model): array
    {
        try {
            $stats = Cache::get($this->statsKey($provider, $model));
        } catch (Throwable) {
            $stats = null;
        }

        return [
            'ok' => (int) ($stats['ok'] ?? 0),
            'fail' => (int) ($stats['fail'] ?? 0),
            'last_ok_at' => $stats['last_ok_at'] ?? null,
            'last_fail_at' => $stats['last_fail_at'] ?? null,
            'last_fail_reason' => $stats['last_fail_reason'] ?? null,
        ];
    }

    private function bumpStats(string $provider, string $model, string $outcome, ?string $kind): void
    {
        try {
            $key = $this->statsKey($provider, $model);
            $stats = Cache::get($key, []);

            $stats[$outcome] = (int) ($stats[$outcome] ?? 0) + 1;
            $stats["last_{$outcome}_at"] = now()->getTimestamp();

            if ($kind !== null) {
                $stats['last_fail_reason'] = $kind;
            }

            Cache::put($key, $stats, now()->addDays(2));
        } catch (Throwable) {
            // Counters are informational; losing one is fine.
        }
    }

    private function stateKey(string $provider, string $model): string
    {
        return "ai-chain:{$provider}:{$model}";
    }

    private function statsKey(string $provider, string $model): string
    {
        return "ai-chain-stats:{$provider}:{$model}:".now()->toDateString();
    }
}
