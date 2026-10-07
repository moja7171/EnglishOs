<?php

namespace Tests\Feature;

use App\Console\Commands\AiRelayUse;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Covers App\Console\Commands\AiRelayFailover: the scheduled health probe
 * that moves AI_PROXY_TARGET between the vps (Cloudflare) relay path and the
 * localhost.run tunnel. Binds a throwaway .env like AiRelayUseTest so the
 * project's real .env is never touched.
 */
class AiRelayFailoverTest extends TestCase
{
    private string $envPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->envPath = tempnam(sys_get_temp_dir(), 'ai-relay-failover-env-test-');
        $this->app->when(AiRelayUse::class)->needs('$envPath')->give($this->envPath);

        Cache::flush();
        $this->useTarget('vps');
    }

    protected function tearDown(): void
    {
        @unlink($this->envPath);

        parent::tearDown();
    }

    private function useTarget(string $target): void
    {
        file_put_contents($this->envPath, "AI_PROXY_TARGET={$target}\n");

        config([
            'services.ai_proxy.target' => $target,
            'services.ai_proxy.slots.vps.url' => 'https://relay.example',
            'services.ai_proxy.slots.local.url' => 'https://tunnel.lhr.life',
        ]);
    }

    private function envTarget(): string
    {
        preg_match('/^AI_PROXY_TARGET=(.*)$/m', file_get_contents($this->envPath), $match);

        return $match[1];
    }

    public function test_a_healthy_vps_path_changes_nothing_and_does_not_probe_the_tunnel(): void
    {
        Http::fake(['relay.example*' => Http::response('bad auth', 401)]);

        $this->artisan('ai:relay-failover')->assertExitCode(0);

        $this->assertSame('vps', $this->envTarget());
        Http::assertSentCount(1);
    }

    public function test_the_probe_is_a_big_post_so_paths_that_only_drop_large_bodies_are_caught(): void
    {
        Http::fake(['relay.example*' => Http::response('bad auth', 401)]);

        $this->artisan('ai:relay-failover');

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && strlen($request->body()) >= 3000);
    }

    public function test_one_failed_probe_does_not_switch(): void
    {
        Http::fake(['relay.example*' => Http::response('', 502), 'tunnel.lhr.life*' => Http::response('bad auth', 401)]);

        $this->artisan('ai:relay-failover')->assertExitCode(0);

        $this->assertSame('vps', $this->envTarget());
    }

    public function test_two_failed_probes_in_a_row_switch_to_the_tunnel_when_it_is_healthy(): void
    {
        Http::fake(['relay.example*' => Http::response('', 502), 'tunnel.lhr.life*' => Http::response('bad auth', 401)]);

        $this->artisan('ai:relay-failover');
        $this->artisan('ai:relay-failover')->assertExitCode(0);

        $this->assertSame('local', $this->envTarget());
    }

    public function test_a_success_between_failures_resets_the_count(): void
    {
        Http::fake([
            'relay.example*' => Http::sequence()->push('', 502)->push('bad auth', 401)->push('', 502),
            'tunnel.lhr.life*' => Http::response('bad auth', 401),
        ]);

        $this->artisan('ai:relay-failover');
        $this->artisan('ai:relay-failover');
        $this->artisan('ai:relay-failover');

        $this->assertSame('vps', $this->envTarget());
    }

    public function test_it_stays_on_vps_when_the_tunnel_is_down_too(): void
    {
        Http::fake(['relay.example*' => Http::response('', 502), 'tunnel.lhr.life*' => Http::response('', 502)]);

        $this->artisan('ai:relay-failover');
        $this->artisan('ai:relay-failover')->assertExitCode(0);

        $this->assertSame('vps', $this->envTarget());
    }

    public function test_it_returns_to_vps_only_after_it_stays_healthy_for_several_runs(): void
    {
        $this->useTarget('local');
        Http::fake(['relay.example*' => Http::response('bad auth', 401), 'tunnel.lhr.life*' => Http::response('bad auth', 401)]);

        $this->artisan('ai:relay-failover');
        $this->artisan('ai:relay-failover');
        $this->assertSame('local', $this->envTarget());

        $this->artisan('ai:relay-failover')->assertExitCode(0);
        $this->assertSame('vps', $this->envTarget());
    }

    public function test_it_stays_on_the_tunnel_while_the_vps_path_is_still_down(): void
    {
        $this->useTarget('local');
        Http::fake(['relay.example*' => Http::response('', 502)]);

        $this->artisan('ai:relay-failover');
        $this->artisan('ai:relay-failover');
        $this->artisan('ai:relay-failover')->assertExitCode(0);

        $this->assertSame('local', $this->envTarget());
    }

    public function test_a_broken_tunnel_is_left_at_once_when_the_vps_path_is_fine(): void
    {
        $this->useTarget('local');
        Http::fake(['relay.example*' => Http::response('bad auth', 401), 'tunnel.lhr.life*' => Http::response('', 502)]);

        $this->artisan('ai:relay-failover')->assertExitCode(0);

        $this->assertSame('vps', $this->envTarget());
    }

    public function test_it_does_nothing_unless_both_slots_have_a_url(): void
    {
        config(['services.ai_proxy.slots.local.url' => null]);
        Http::fake();

        $this->artisan('ai:relay-failover')->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertSame('vps', $this->envTarget());
    }
}
