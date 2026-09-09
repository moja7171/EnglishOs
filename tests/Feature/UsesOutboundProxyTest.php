<?php

namespace Tests\Feature;

use App\Services\Concerns\UsesOutboundProxy;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Covers the shared relay-routing mechanism GeminiClient/GroqClient/
 * PexelsClient all use to reach filtered providers — see
 * App\Services\Concerns\UsesOutboundProxy and config('services.ai_proxy').
 * Not a real forward proxy (that needs CONNECT over a raw TCP tunnel);
 * this speaks plain HTTP, so it works over a simple HTTP tunnel to
 * scripts/ai-relay.py.
 */
class UsesOutboundProxyTest extends TestCase
{
    private const REAL_URL = 'https://generativelanguage.googleapis.com/v1beta/models/x:generateContent';

    private function subject(): object
    {
        return new class
        {
            use UsesOutboundProxy;

            public function request(PendingRequest $request, string $realUrl): PendingRequest
            {
                return $this->withOutboundProxy($request, $realUrl);
            }

            public function url(string $realUrl): string
            {
                return $this->outboundUrl($realUrl);
            }
        };
    }

    public function test_the_real_url_is_used_unchanged_when_no_relay_is_configured(): void
    {
        config(['services.ai_proxy.url' => '']);

        $subject = $this->subject();
        $request = $subject->request(Http::timeout(20), self::REAL_URL);

        $this->assertSame(self::REAL_URL, $subject->url(self::REAL_URL));
        $this->assertArrayNotHasKey('X-Relay-Url', $request->getOptions()['headers'] ?? []);
    }

    public function test_the_relay_url_and_real_destination_header_are_used_when_a_relay_is_configured(): void
    {
        config([
            'services.ai_proxy.url' => 'https://my-tunnel.example/',
            'services.ai_proxy.secret' => 'the-secret',
        ]);

        $subject = $this->subject();
        $request = $subject->request(Http::timeout(20), self::REAL_URL);

        $this->assertSame('https://my-tunnel.example/', $subject->url(self::REAL_URL));
        $this->assertSame(self::REAL_URL, $request->getOptions()['headers']['X-Relay-Url']);
        $this->assertSame('the-secret', $request->getOptions()['headers']['X-Relay-Auth']);
    }
}
