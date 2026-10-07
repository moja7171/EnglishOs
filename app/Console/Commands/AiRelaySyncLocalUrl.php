<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Keeps AI_PROXY_URL_LOCAL pointed at the live localhost.run tunnel without
 * anything pushing to this host. A free tunnel gets a new random URL every
 * time it reconnects (it changed within 20 minutes with no restart), and the
 * relay VPS can't publish it here because this host can't talk to that
 * VPS's IP — but this host CAN make small requests to the VPS relay's
 * Cloudflare-fronted URL (AI_PROXY_URL_VPS). So this pulls instead: it asks
 * that relay (GET /_tunnel-url, see scripts/ai-relay.py) for the tunnel's
 * current URL and, when it differs from the one in .env, hands it to
 * ai:relay-use after checking the new tunnel really reaches the relay.
 *
 * Scheduled every minute from routes/console.php. A no-op when the vps slot
 * isn't configured.
 */
class AiRelaySyncLocalUrl extends Command
{
    protected $signature = 'ai:relay-sync-local-url';

    protected $description = 'Pulls the local tunnel\'s current URL from the vps relay and updates AI_PROXY_URL_LOCAL when it changed';

    public function handle(): int
    {
        $relayUrl = (string) config('services.ai_proxy.slots.vps.url');
        $secret = (string) config('services.ai_proxy.slots.vps.secret');

        if ($relayUrl === '' || $secret === '') {
            $this->info('The vps relay slot is not configured — nothing to sync.');

            return self::SUCCESS;
        }

        $tunnelUrl = $this->fetchTunnelUrl($relayUrl, $secret);

        if ($tunnelUrl === null) {
            return self::FAILURE;
        }

        if ($tunnelUrl === rtrim((string) config('services.ai_proxy.slots.local.url'), '/')) {
            $this->info("AI_PROXY_URL_LOCAL is already \"{$tunnelUrl}\".");

            return self::SUCCESS;
        }

        if (! $this->tunnelReachesRelay($tunnelUrl)) {
            return $this->reportFailure("The tunnel {$tunnelUrl} does not reach the relay yet — keeping the current URL.");
        }

        return $this->call('ai:relay-use', ['--local-url' => $tunnelUrl]);
    }

    private function fetchTunnelUrl(string $relayUrl, string $secret): ?string
    {
        try {
            $response = Http::withHeaders(['X-Relay-Auth' => $secret])
                ->connectTimeout(3)
                ->timeout(10)
                ->get(rtrim($relayUrl, '/').'/_tunnel-url');
        } catch (ConnectionException $e) {
            $this->reportFailure('Could not reach the vps relay: '.$e->getMessage());

            return null;
        }

        $tunnelUrl = trim($response->body());

        if (! $response->successful() || ! preg_match(AiRelayUse::TUNNEL_URL_PATTERN, $tunnelUrl)) {
            $this->reportFailure("The vps relay did not return a tunnel URL (HTTP {$response->status()}).");

            return null;
        }

        return $tunnelUrl;
    }

    /**
     * Same probe the old laptop publisher used: a bare GET to a healthy
     * tunnel comes back as the relay's own 401 "bad auth".
     */
    private function tunnelReachesRelay(string $tunnelUrl): bool
    {
        try {
            $response = Http::connectTimeout(3)->timeout(10)->get($tunnelUrl);
        } catch (ConnectionException) {
            return false;
        }

        return $response->status() === 401 && str_contains($response->body(), 'bad auth');
    }

    /**
     * This runs every minute, so a persistent failure is logged at most once
     * per half hour instead of once per run.
     */
    private function reportFailure(string $message): int
    {
        $this->error($message);

        if (Cache::add('ai-relay-sync:failure-logged', true, now()->addMinutes(30))) {
            Log::error('AiRelaySyncLocalUrl: '.$message);
        }

        return self::FAILURE;
    }
}
