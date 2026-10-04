<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiDiagnosticTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.diagnostics.token' => 'diag-token',
            'services.gemini.key' => 'secret-gemini-key',
            'services.ai_proxy.url' => 'https://relay.test/',
            'services.ai_proxy.secret' => 'secret-relay-value',
        ]);
    }

    public function test_missing_or_wrong_token_is_a_404(): void
    {
        $this->get('/_diag/ai')->assertNotFound();
        $this->get('/_diag/ai?token=nope')->assertNotFound();
    }

    public function test_route_is_disabled_when_no_token_is_configured(): void
    {
        config(['services.diagnostics.token' => '']);

        $this->get('/_diag/ai?token=')->assertNotFound();
    }

    public function test_report_shows_the_real_failure_and_never_leaks_secrets(): void
    {
        Http::fake([
            'relay.test/*' => Http::response('bad auth secret-relay-value', 401),
            '*' => Http::response('blocked', 403),
        ]);

        $response = $this->get('/_diag/ai?token=diag-token');

        $response->assertOk();
        $response->assertSee('HTTP 401', false);
        $response->assertSee('FAILED', false);
        $response->assertSee('response status: 401', false);
        $response->assertSee('body-size sweep', false);
        $response->assertSee('Control: big POST bodies', false);
        $response->assertSee('relay VPS itself', false);
        $response->assertDontSee('secret-relay-value', false);
        $response->assertDontSee('secret-gemini-key', false);
    }

    public function test_clear_log_only_empties_the_log_when_asked(): void
    {
        Http::fake(['*' => Http::response('blocked', 403)]);
        $log = storage_path('logs/laravel.log');
        $original = is_file($log) ? file_get_contents($log) : null;

        try {
            file_put_contents($log, 'old noisy log');

            $this->get('/_diag/ai?token=diag-token')->assertOk();
            $this->assertStringContainsString('old noisy log', file_get_contents($log));

            $this->get('/_diag/ai?token=diag-token&clear_log=1')->assertOk()->assertSee('cleared: freed', false);
            $this->assertSame('', file_get_contents($log));
        } finally {
            $original === null ? @unlink($log) : file_put_contents($log, $original);
        }
    }
}
