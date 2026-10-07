<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
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

        $retryDelay ??= $this->retryAfterHeader($error);

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
