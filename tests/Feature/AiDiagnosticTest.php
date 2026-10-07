<?php

namespace Tests\Feature;

use App\Services\AiModelChain;
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
        $response->assertSee('Relay upload sweep', false);
        $response->assertSee('body ~256000 bytes, no auth', false);
        $response->assertSee('GroqClient::transcribe()', false);
        $response->assertDontSee('secret-relay-value', false);
        $response->assertDontSee('secret-gemini-key', false);
    }

    public function test_the_chain_report_flags_a_retired_model_and_a_model_in_the_penalty_box(): void
    {
        config([
            'services.gemini.chat_models' => ['retired-model', 'tired-model'],
            'services.gemini.judge_models' => ['tired-model'],
            'services.groq.key' => 'secret-groq-key',
            'services.groq.whisper_models' => ['whisper-large-v3-turbo'],
        ]);
        (new AiModelChain)->markUnavailable('gemini', 'tired-model', AiModelChain::KIND_DAILY_QUOTA, 3600);

        Http::fake(function ($request) {
            $target = $request->header('X-Relay-Url')[0] ?? '';

            return match (true) {
                str_ends_with($target, '/v1beta/models/retired-model') => Http::response(['error' => 'gone'], 404),
                str_ends_with($target, '/v1beta/models/tired-model') => Http::response(['name' => 'models/tired-model']),
                str_ends_with($target, '/openai/v1/models') => Http::response(['data' => [['id' => 'whisper-large-v3-turbo']]]),
                default => Http::response('blocked', 403),
            };
        });

        $response = $this->get('/_diag/ai?token=diag-token');

        $response->assertSee('1. retired-model — GONE', false);
        $response->assertSee('2. tired-model — exists; SKIPPED (daily_quota)', false);
        $response->assertSee('1. whisper-large-v3-turbo — exists; in rotation', false);
        $response->assertSee('gemini chat chain: retired-model → tired-model', false);
        $response->assertDontSee('secret-groq-key', false);
    }

    public function test_the_transcription_probe_sends_a_real_wav_through_the_relay_and_reports_what_whisper_said(): void
    {
        config(['services.groq.key' => 'secret-groq-key']);
        Http::fake([
            'relay.test/*' => Http::response(['text' => ' hello tone'], 200),
            '*' => Http::response('blocked', 403),
        ]);

        $this->get('/_diag/ai?token=diag-token')
            ->assertOk()
            ->assertSee('Whisper returned: "hello tone"', false)
            ->assertDontSee('secret-groq-key', false);

        Http::assertSent(fn ($request) => $request->hasHeader('X-Relay-Url', 'https://api.groq.com/openai/v1/audio/transcriptions')
            && str_contains($request->body(), 'RIFF')
            && str_contains($request->body(), 'WAVEfmt '));
    }

    public function test_the_upload_sweep_posts_big_bodies_to_the_relay_without_its_secret(): void
    {
        Http::fake(['relay.test/*' => Http::response('bad auth', 401), '*' => Http::response('x', 403)]);

        $this->get('/_diag/ai?token=diag-token')->assertOk();

        Http::assertSent(fn ($request) => $request->url() === 'https://relay.test/'
            && strlen($request->body()) === 256_000
            && ! $request->hasHeader('X-Relay-Auth'));
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
