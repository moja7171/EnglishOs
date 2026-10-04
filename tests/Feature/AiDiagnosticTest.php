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
        $response->assertSee('Sage-style chat', false);
        $response->assertSee('SentenceChecker::check()', false);
        $response->assertDontSee('secret-relay-value', false);
        $response->assertDontSee('secret-gemini-key', false);
    }
}
