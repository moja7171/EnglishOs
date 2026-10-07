<?php

namespace Tests\Feature;

use App\Console\Commands\AiRelayUse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Covers App\Console\Commands\AiRelaySyncLocalUrl: production pulling the
 * localhost.run tunnel's current URL from the vps relay (the VPS can't push
 * it — see the command's docblock). Binds a throwaway .env like
 * AiRelayUseTest so the project's real .env is never touched.
 */
class AiRelaySyncLocalUrlTest extends TestCase
{
    private const OLD_ENV = "AI_PROXY_URL_LOCAL=https://old.lhr.life\nAI_PROXY_TARGET=vps\n";

    private string $envPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->envPath = tempnam(sys_get_temp_dir(), 'ai-relay-sync-env-test-');
        file_put_contents($this->envPath, self::OLD_ENV);

        $this->app->when(AiRelayUse::class)->needs('$envPath')->give($this->envPath);

        config([
            'services.ai_proxy.slots.vps.url' => 'https://relay.example',
            'services.ai_proxy.slots.vps.secret' => 's3cret',
            'services.ai_proxy.slots.local.url' => 'https://old.lhr.life',
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->envPath);

        parent::tearDown();
    }

    public function test_a_changed_tunnel_url_is_verified_then_written_to_env(): void
    {
        Http::fake([
            'relay.example/_tunnel-url' => Http::response("https://new456.lhr.life\n"),
            'new456.lhr.life*' => Http::response('bad auth', 401),
        ]);

        $this->artisan('ai:relay-sync-local-url')
            ->expectsOutputToContain('AI_PROXY_URL_LOCAL set to "https://new456.lhr.life"')
            ->assertExitCode(0);

        $contents = file_get_contents($this->envPath);
        $this->assertStringContainsString("AI_PROXY_URL_LOCAL=https://new456.lhr.life\n", $contents);
        $this->assertStringNotContainsString('old.lhr.life', $contents);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://relay.example/_tunnel-url'
            && $request->header('X-Relay-Auth') === ['s3cret']);
    }

    public function test_an_unchanged_tunnel_url_leaves_env_alone_and_skips_the_tunnel_probe(): void
    {
        Http::fake(['relay.example/_tunnel-url' => Http::response('https://old.lhr.life')]);

        $this->artisan('ai:relay-sync-local-url')->assertExitCode(0);

        $this->assertSame(self::OLD_ENV, file_get_contents($this->envPath));
        Http::assertSentCount(1);
    }

    public function test_a_tunnel_that_does_not_reach_the_relay_is_not_adopted(): void
    {
        Http::fake([
            'relay.example/_tunnel-url' => Http::response('https://new456.lhr.life'),
            'new456.lhr.life*' => Http::response('', 502),
        ]);

        $this->artisan('ai:relay-sync-local-url')->assertExitCode(1);

        $this->assertSame(self::OLD_ENV, file_get_contents($this->envPath));
    }

    public function test_a_response_that_is_not_a_tunnel_url_is_rejected_without_requesting_it(): void
    {
        Http::fake(['relay.example/_tunnel-url' => Http::response('https://evil.example')]);

        $this->artisan('ai:relay-sync-local-url')->assertExitCode(1);

        $this->assertSame(self::OLD_ENV, file_get_contents($this->envPath));
        Http::assertSentCount(1);
    }

    public function test_a_rejected_secret_fails_without_touching_env(): void
    {
        Http::fake(['relay.example/_tunnel-url' => Http::response('bad auth', 401)]);

        $this->artisan('ai:relay-sync-local-url')->assertExitCode(1);

        $this->assertSame(self::OLD_ENV, file_get_contents($this->envPath));
    }

    public function test_an_unreachable_relay_fails_without_touching_env(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $this->artisan('ai:relay-sync-local-url')->assertExitCode(1);

        $this->assertSame(self::OLD_ENV, file_get_contents($this->envPath));
    }

    public function test_it_does_nothing_when_the_vps_slot_is_not_configured(): void
    {
        config(['services.ai_proxy.slots.vps.url' => null, 'services.ai_proxy.slots.vps.secret' => null]);
        Http::fake();

        $this->artisan('ai:relay-sync-local-url')->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertSame(self::OLD_ENV, file_get_contents($this->envPath));
    }
}
