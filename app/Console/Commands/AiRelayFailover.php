<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Switches AI_PROXY_TARGET between the two relay paths by itself: "vps" (the
 * relay VPS behind Cloudflare — the preferred, stable path) and "local" (the
 * same relay through a localhost.run tunnel — the backup). Scheduled every
 * minute from routes/console.php.
 *
 * A path is healthy when a bare POST with a body of PROBE_BODY_BYTES comes
 * back as the relay's own 401 "bad auth": no Gemini call is spent, and the
 * body is as large as the ones that used to hang on a bad route (see
 * AiDiagnosticController's body-size sweep), so a path that only fails on
 * big requests is caught too. It does not see errors from the providers
 * themselves (quota, region blocks).
 *
 * Switching needs consecutive results so one dropped probe doesn't flip the
 * live path: the vps path must fail FAILURES_BEFORE_SWITCH runs in a row
 * (and the tunnel must be healthy) to move to the tunnel, and must be healthy
 * SUCCESSES_BEFORE_RETURN runs in a row to move back. A broken tunnel is
 * left immediately when the vps path is fine.
 */
class AiRelayFailover extends Command
{
    private const PROBE_BODY_BYTES = 3000;

    private const FAILURES_BEFORE_SWITCH = 2;

    private const SUCCESSES_BEFORE_RETURN = 3;

    private const FAILURES_KEY = 'ai-relay-failover:primary-failures';

    private const SUCCESSES_KEY = 'ai-relay-failover:primary-successes';

    protected $signature = 'ai:relay-failover';

    protected $description = 'Moves AI_PROXY_TARGET to the backup relay path when the vps path is down, and back when it recovers';

    public function handle(): int
    {
        $primaryUrl = (string) config('services.ai_proxy.slots.vps.url');
        $backupUrl = (string) config('services.ai_proxy.slots.local.url');

        if ($primaryUrl === '' || $backupUrl === '') {
            $this->info('Both relay slots need a URL for failover — nothing to do.');

            return self::SUCCESS;
        }

        $primaryHealthy = $this->relayAnswers($primaryUrl);

        if (config('services.ai_proxy.target') === 'local') {
            $this->handleOnBackup($primaryHealthy, $backupUrl);
        } else {
            $this->handleOnPrimary($primaryHealthy, $backupUrl);
        }

        return self::SUCCESS;
    }

    private function handleOnPrimary(bool $primaryHealthy, string $backupUrl): void
    {
        if ($primaryHealthy) {
            Cache::forget(self::FAILURES_KEY);
            $this->info('The vps relay path is healthy.');

            return;
        }

        $failures = (int) Cache::get(self::FAILURES_KEY, 0) + 1;
        Cache::put(self::FAILURES_KEY, $failures, now()->addHour());

        if ($failures < self::FAILURES_BEFORE_SWITCH) {
            $this->warn("The vps relay path failed its probe ({$failures}/".self::FAILURES_BEFORE_SWITCH.').');

            return;
        }

        if (! $this->relayAnswers($backupUrl)) {
            $this->error('The vps relay path is down and so is the tunnel — staying on vps.');

            return;
        }

        $this->switchTo('local', 'the vps relay path failed '.$failures.' probes in a row');
    }

    private function handleOnBackup(bool $primaryHealthy, string $backupUrl): void
    {
        if (! $primaryHealthy) {
            Cache::forget(self::SUCCESSES_KEY);
            $this->info('Staying on the tunnel — the vps relay path is still down.');

            return;
        }

        $successes = (int) Cache::get(self::SUCCESSES_KEY, 0) + 1;
        Cache::put(self::SUCCESSES_KEY, $successes, now()->addHour());

        if ($successes >= self::SUCCESSES_BEFORE_RETURN) {
            $this->switchTo('vps', 'the vps relay path is healthy again');

            return;
        }

        if (! $this->relayAnswers($backupUrl)) {
            $this->switchTo('vps', 'the tunnel stopped answering and the vps relay path is healthy');

            return;
        }

        $this->info("The vps relay path is healthy again ({$successes}/".self::SUCCESSES_BEFORE_RETURN.') — waiting before moving back.');
    }

    private function switchTo(string $target, string $reason): void
    {
        Cache::forget(self::FAILURES_KEY);
        Cache::forget(self::SUCCESSES_KEY);

        $this->call('ai:relay-use', ['target' => $target]);

        Log::error("AiRelayFailover: switched AI_PROXY_TARGET to \"{$target}\" — {$reason}.");
    }

    private function relayAnswers(string $relayUrl): bool
    {
        try {
            $response = Http::connectTimeout(3)
                ->timeout(6)
                ->withBody(str_repeat('a', self::PROBE_BODY_BYTES), 'text/plain')
                ->post($relayUrl);
        } catch (ConnectionException) {
            return false;
        }

        return $response->status() === 401 && str_contains($response->body(), 'bad auth');
    }
}
