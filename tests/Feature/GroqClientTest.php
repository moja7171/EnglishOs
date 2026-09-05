<?php

namespace Tests\Feature;

use App\Services\GroqClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class GroqClientTest extends TestCase
{
    private function fakeVerboseResponse(array $segments): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response([
                'text' => collect($segments)->pluck('text')->implode(' '),
                'duration' => 12.5,
                'segments' => $segments,
            ]),
        ]);
    }

    /**
     * A real (tiny, empty-content is fine — Http::fake() intercepts before
     * any actual network call) local file — file_get_contents() needs a
     * path that genuinely exists.
     */
    private function fakeAudioPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'groq-test-audio');
        file_put_contents($path, 'fake-audio-bytes');

        return $path;
    }

    public function test_transcribe_with_confidence_tags_each_segment_by_its_avg_logprob(): void
    {
        $this->fakeVerboseResponse([
            ['text' => 'This part was clear.', 'avg_logprob' => -0.1],
            ['text' => 'This part was mumbled.', 'avg_logprob' => -0.5],
            ['text' => 'This part was unintelligible.', 'avg_logprob' => -1.2],
        ]);

        $result = (new GroqClient('test-key'))->transcribeWithConfidence($this->fakeAudioPath());

        $this->assertSame('high', $result['segments'][0]['confidence']);
        $this->assertSame('medium', $result['segments'][1]['confidence']);
        $this->assertSame('low', $result['segments'][2]['confidence']);
    }

    public function test_the_high_medium_low_thresholds(): void
    {
        $this->fakeVerboseResponse([
            ['text' => 'Exactly at the high boundary.', 'avg_logprob' => -0.35],
            ['text' => 'Just below the high boundary.', 'avg_logprob' => -0.36],
            ['text' => 'Exactly at the medium boundary.', 'avg_logprob' => -0.7],
            ['text' => 'Just below the medium boundary.', 'avg_logprob' => -0.71],
        ]);

        $result = (new GroqClient('test-key'))->transcribeWithConfidence($this->fakeAudioPath());

        $this->assertSame('high', $result['segments'][0]['confidence']);
        $this->assertSame('medium', $result['segments'][1]['confidence']);
        $this->assertSame('medium', $result['segments'][2]['confidence']);
        $this->assertSame('low', $result['segments'][3]['confidence']);
    }

    public function test_empty_or_whitespace_only_segments_are_dropped(): void
    {
        $this->fakeVerboseResponse([
            ['text' => 'A real segment.', 'avg_logprob' => -0.1],
            ['text' => '   ', 'avg_logprob' => -0.1],
            ['text' => '', 'avg_logprob' => -0.1],
        ]);

        $result = (new GroqClient('test-key'))->transcribeWithConfidence($this->fakeAudioPath());

        $this->assertCount(1, $result['segments']);
    }

    /**
     * Regression test for the missing-timeout/retry fix from earlier today.
     * Originally this exercised Laravel's own retry(2, ...) recovering a
     * transient 503 within a single model's attempt (2 requests, both
     * against the primary model, fallback never invoked). Since the
     * retry-count was reduced to retry(1, ...) — a second model to try IS
     * the retry now, so 2 attempts per model would double the worst-case
     * latency of the fallback chain — a single 503 is enough to exhaust the
     * primary model's one attempt, and this now recovers via the automatic
     * fallback instead. The request count (2) is unchanged, but for a
     * different reason: 1 failed primary attempt + 1 successful fallback
     * attempt, not 2 attempts against the same model. Uses the default
     * constructor deliberately, so this also confirms config('services.
     * groq.fallback_model') resolves correctly end-to-end, not just the
     * explicit-constructor-arg path the other fallback tests below cover.
     *
     * Request count alone (2) can't distinguish "fell back to a second
     * model" from "the old in-place retry against the same model" — both
     * shapes produce exactly 2 requests. So, like the sibling fallback
     * tests below, this asserts the SECOND request actually carried the
     * fallback model's name (via bodyUsedModel()'s exact-value match, not
     * a plain str_contains — "whisper-large-v3" is itself a substring of
     * "whisper-large-v3-turbo").
     */
    public function test_a_transient_503_on_the_primary_model_recovers_via_the_automatic_fallback(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::sequence()
                ->push(['error' => 'service unavailable'], 503)
                ->push(['text' => 'Recovered via fallback.', 'duration' => 3.0, 'segments' => []]),
        ]);

        $result = (new GroqClient('test-key'))->transcribe($this->fakeAudioPath());

        $this->assertSame('Recovered via fallback.', $result);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $this->bodyUsedModel((string) $request->body(), 'whisper-large-v3'));
    }

    public function test_the_request_asks_for_segment_level_timestamp_granularity(): void
    {
        $this->fakeVerboseResponse([['text' => 'Hi.', 'avg_logprob' => -0.1]]);

        (new GroqClient('test-key'))->transcribeWithConfidence($this->fakeAudioPath());

        Http::assertSent(function ($request) {
            $body = (string) $request->body();

            return str_contains($body, 'timestamp_granularities[]')
                && str_contains($body, 'segment');
        });
    }

    /**
     * The multipart "model" field's value ends in CRLF, so an exact-value
     * check (not a plain str_contains, since "whisper-large-v3" is itself a
     * substring of "whisper-large-v3-turbo") is needed to tell which model
     * a given request actually used.
     */
    private function bodyUsedModel(string $body, string $model): bool
    {
        return str_contains($body, "\r\n\r\n{$model}\r\n");
    }

    public function test_primary_whisper_model_succeeds_and_fallback_is_never_used(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response(['text' => 'Transcribed by primary.', 'duration' => 3.0, 'segments' => []]),
        ]);

        $client = new GroqClient('test-key', 'whisper-large-v3-turbo', 'whisper-large-v3');
        $result = $client->transcribe($this->fakeAudioPath());

        $this->assertSame('Transcribed by primary.', $result);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => $this->bodyUsedModel((string) $request->body(), 'whisper-large-v3'));
    }

    public function test_primary_whisper_model_fails_after_its_retries_and_fallback_model_succeeds(): void
    {
        Log::spy();

        // retry(1, ...) is 1 total attempt per model (Laravel's retry()
        // helper treats its $times argument as the total attempt count, not
        // additional retries beyond the first — confirmed against
        // vendor/laravel/framework's retry() helper), so a single 503 is
        // enough to exhaust the primary model here.
        Http::fake([
            'api.groq.com/*' => Http::sequence()
                ->push(['error' => 'service unavailable'], 503)
                ->push(['text' => 'Recovered via fallback.', 'duration' => 4.0, 'segments' => []]),
        ]);

        $client = new GroqClient('test-key', 'whisper-large-v3-turbo', 'whisper-large-v3');
        $result = $client->transcribe($this->fakeAudioPath());

        $this->assertSame('Recovered via fallback.', $result);

        // 1 exhausted attempt against the primary model, then 1 successful
        // attempt against the fallback model.
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $this->bodyUsedModel((string) $request->body(), 'whisper-large-v3'));

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context) => str_contains($message, 'fallback')
                && $context['primary_model'] === 'whisper-large-v3-turbo'
                && $context['fallback_model'] === 'whisper-large-v3');
    }

    public function test_both_primary_and_fallback_whisper_models_failing_still_throws(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::sequence()
                ->push(['error' => 'service unavailable'], 503)
                ->push(['error' => 'service unavailable'], 503),
        ]);

        $client = new GroqClient('test-key', 'whisper-large-v3-turbo', 'whisper-large-v3');

        try {
            $client->transcribe($this->fakeAudioPath());
            $this->fail('Expected transcribe() to throw once both models are exhausted.');
        } catch (\Throwable $e) {
            $this->assertNotInstanceOf(RuntimeException::class, $e, 'Should surface the HTTP failure, not swallow it silently.');
        }

        // 1 attempt against each model — no infinite loop, no third model.
        Http::assertSentCount(2);
    }
}
