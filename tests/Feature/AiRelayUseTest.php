<?php

namespace Tests\Feature;

use App\Console\Commands\AiRelayUse;
use Tests\TestCase;

/**
 * Covers the vps/local relay switch — App\Console\Commands\AiRelayUse and
 * its token-gated web wrapper App\Http\Controllers\AiRelayUseController
 * (see config('services.ai_proxy') and .env.example's AI_PROXY_TARGET).
 * Binds a throwaway .env file for every test so nothing here ever touches
 * this project's real .env.
 */
class AiRelayUseTest extends TestCase
{
    private string $envPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->envPath = tempnam(sys_get_temp_dir(), 'ai-relay-env-test-');
        file_put_contents($this->envPath, "APP_NAME=Test\nAI_PROXY_TARGET=vps\n");

        $this->app->when(AiRelayUse::class)->needs('$envPath')->give($this->envPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->envPath);

        parent::tearDown();
    }

    public function test_with_no_argument_it_shows_the_current_target_without_changing_anything(): void
    {
        config(['services.ai_proxy.target' => 'vps', 'services.ai_proxy.url' => 'https://vps.example/']);

        $this->artisan('ai:relay-use')
            ->expectsOutputToContain('Current target: vps')
            ->expectsOutputToContain('https://vps.example/')
            ->assertExitCode(0);

        $this->assertStringContainsString('AI_PROXY_TARGET=vps', file_get_contents($this->envPath));
    }

    public function test_an_invalid_target_fails_without_touching_env(): void
    {
        $this->artisan('ai:relay-use', ['target' => 'worker'])
            ->expectsOutputToContain('Target must be "vps" or "local"')
            ->assertExitCode(1);

        $this->assertStringContainsString('AI_PROXY_TARGET=vps', file_get_contents($this->envPath));
    }

    public function test_switching_rewrites_an_existing_target_line(): void
    {
        $this->artisan('ai:relay-use', ['target' => 'local'])->assertExitCode(0);

        $contents = file_get_contents($this->envPath);
        $this->assertStringContainsString('AI_PROXY_TARGET=local', $contents);
        $this->assertStringNotContainsString('AI_PROXY_TARGET=vps', $contents);
    }

    public function test_switching_appends_the_target_line_when_it_was_missing(): void
    {
        file_put_contents($this->envPath, "APP_NAME=Test\n");

        $this->artisan('ai:relay-use', ['target' => 'local'])->assertExitCode(0);

        $this->assertStringContainsString('AI_PROXY_TARGET=local', file_get_contents($this->envPath));
    }

    public function test_the_web_wrapper_404s_without_the_right_token(): void
    {
        config(['services.diagnostics.token' => 'diag-token']);

        $this->get('/_diag/ai-relay-use?target=local')->assertNotFound();
        $this->get('/_diag/ai-relay-use?token=nope&target=local')->assertNotFound();
    }

    public function test_the_web_wrapper_switches_the_target_with_a_valid_token(): void
    {
        config(['services.diagnostics.token' => 'diag-token']);

        $response = $this->get('/_diag/ai-relay-use?token=diag-token&target=local');

        $response->assertOk();
        $response->assertSee('AI_PROXY_TARGET set to "local"', false);
        $this->assertStringContainsString('AI_PROXY_TARGET=local', file_get_contents($this->envPath));
    }
}
