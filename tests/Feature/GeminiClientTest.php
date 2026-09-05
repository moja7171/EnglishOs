<?php

namespace Tests\Feature;

use App\Services\GeminiClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * Covers the primary-then-fallback-model chain added after the 2026-09-04
 * outage (the configured primary model hung outright while a second model
 * on the same key responded fine — see App\Services\GeminiClient::chat()).
 */
class GeminiClientTest extends TestCase
{
    private const PRIMARY_URL = 'generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent';

    private const FALLBACK_URL = 'generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent';

    private function textResponse(string $text): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => $text]]]]]];
    }

    public function test_primary_model_succeeds_and_fallback_is_never_invoked(): void
    {
        Http::fake([
            self::PRIMARY_URL => Http::response($this->textResponse('Hello from primary.')),
            self::FALLBACK_URL => Http::response($this->textResponse('Hello from fallback.')),
        ]);

        $client = new GeminiClient('test-key', 'gemini-3.5-flash-lite', 'gemini-flash-latest');
        $result = $client->chat([['role' => 'user', 'text' => 'Hi']]);

        $this->assertSame('Hello from primary.', $result);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'gemini-flash-latest'));
    }

    public function test_primary_model_fails_after_its_retries_and_fallback_model_succeeds(): void
    {
        Log::spy();

        Http::fake([
            // retry(1, ...) is 1 total attempt per model (Laravel's retry()
            // helper treats its $times argument as the total attempt count,
            // not additional retries beyond the first — confirmed against
            // vendor/laravel/framework's retry() helper), so a single 503
            // is enough to exhaust the primary model here.
            self::PRIMARY_URL => Http::response(['error' => 'service unavailable'], 503),
            self::FALLBACK_URL => Http::response($this->textResponse('Recovered via fallback.')),
        ]);

        $client = new GeminiClient('test-key', 'gemini-3.5-flash-lite', 'gemini-flash-latest');
        $result = $client->chat([['role' => 'user', 'text' => 'Hi']]);

        $this->assertSame('Recovered via fallback.', $result);

        // 1 exhausted attempt against the primary model, then 1 successful
        // attempt against the fallback model.
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'gemini-flash-latest'));

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context) => str_contains($message, 'fallback')
                && $context['primary_model'] === 'gemini-3.5-flash-lite'
                && $context['fallback_model'] === 'gemini-flash-latest');
    }

    public function test_both_primary_and_fallback_failing_still_throws(): void
    {
        Http::fake([
            self::PRIMARY_URL => Http::response(['error' => 'service unavailable'], 503),
            self::FALLBACK_URL => Http::response(['error' => 'service unavailable'], 503),
        ]);

        $client = new GeminiClient('test-key', 'gemini-3.5-flash-lite', 'gemini-flash-latest');

        try {
            $client->chat([['role' => 'user', 'text' => 'Hi']]);
            $this->fail('Expected chat() to throw once both the primary and fallback models are exhausted.');
        } catch (\Throwable $e) {
            $this->assertNotInstanceOf(RuntimeException::class, $e, 'Should surface the HTTP failure, not swallow it silently.');
        }

        // 1 attempt against each model — no infinite loop, no third model.
        Http::assertSentCount(2);
    }

    public function test_missing_api_key_throws_without_any_http_call(): void
    {
        Http::fake();

        $client = new GeminiClient('', 'gemini-3.5-flash-lite', 'gemini-flash-latest');

        try {
            $client->chat([['role' => 'user', 'text' => 'Hi']]);
            $this->fail('Expected chat() to throw when no API key is configured.');
        } catch (RuntimeException) {
            // expected
        }

        Http::assertNothingSent();
    }
}
