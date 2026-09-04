<?php

namespace Tests\Feature;

use App\Services\GroqClient;
use Illuminate\Support\Facades\Http;
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
     * Regression test for the missing-timeout/retry fix — same treatment
     * GeminiClient::chat() already has. A transient 503 (Groq's Whisper
     * tier occasionally returns this under load, same as Gemini's flash
     * tier) should be retried and recover, not surface as a hard failure
     * on the first blip.
     */
    public function test_a_transient_503_is_retried_and_recovers(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::sequence()
                ->push(['error' => 'service unavailable'], 503)
                ->push(['text' => 'Recovered after retry.', 'duration' => 3.0, 'segments' => []]),
        ]);

        $result = (new GroqClient('test-key'))->transcribe($this->fakeAudioPath());

        $this->assertSame('Recovered after retry.', $result);
        Http::assertSentCount(2);
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
}
