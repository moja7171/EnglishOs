<?php

namespace App\Services\Concerns;

use Illuminate\Http\Client\PendingRequest;

/**
 * Routes an outbound Http call through services.ai_proxy.url when it's
 * set (empty by default — every call connects directly, unchanged from
 * before this existed). For AI providers (Gemini, Groq, Pexels) that are
 * filtered on the server's own network: the relay at that URL forwards
 * whatever it's told to (via the X-Relay-Url header) verbatim to the
 * real destination and hands back the real response untouched, so every
 * caller's existing ->json()/->throw() handling keeps working unchanged
 * — only the request's URL and one extra header change. Shared instead
 * of repeated per client since all three need the exact same two lines.
 */
trait UsesOutboundProxy
{
    protected function withOutboundProxy(PendingRequest $request, string $realUrl): PendingRequest
    {
        $relayUrl = (string) config('services.ai_proxy.url');

        if ($relayUrl === '') {
            return $request;
        }

        return $request->withHeaders([
            'X-Relay-Url' => $realUrl,
            'X-Relay-Auth' => (string) config('services.ai_proxy.secret'),
        ]);
    }

    /**
     * The URL a caller should actually send the request to — $realUrl
     * itself when no relay is configured, or the relay's own URL (which
     * reads the real destination back out of the X-Relay-Url header set
     * by withOutboundProxy() above) when one is.
     */
    protected function outboundUrl(string $realUrl): string
    {
        $relayUrl = (string) config('services.ai_proxy.url');

        return $relayUrl === '' ? $realUrl : $relayUrl;
    }
}
