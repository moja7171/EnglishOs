<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper around Groq's OpenAI-compatible Whisper transcription API —
 * turns a learner's recorded Speaking Evidence into text. See EOS-009 §11.
 */
class GroqClient
{
    private readonly string $apiKey;

    private readonly string $whisperModel;

    public function __construct(?string $apiKey = null, ?string $whisperModel = null)
    {
        $this->apiKey = $apiKey ?? (string) config('services.groq.key');
        $this->whisperModel = $whisperModel ?? (string) config('services.groq.whisper_model', 'whisper-large-v3-turbo');
    }

    /**
     * Transcribes a local audio file and returns the plain-text result.
     */
    public function transcribe(string $audioPath): string
    {
        return $this->request($audioPath)['text'];
    }

    /**
     * Same transcription, but also returns the recording's real duration
     * in seconds (Whisper's own verbose_json output, not guessed) — lets a
     * caller derive a genuine speaking-pace signal (words per minute) for
     * AI feedback on HOW something was said, not just what was said. See
     * ⚡activation.blade.php's transcribeAndReflect().
     *
     * @return array{text: string, duration: float}
     */
    public function transcribeWithDuration(string $audioPath): array
    {
        return $this->request($audioPath, verbose: true);
    }

    /**
     * Same transcription, split into Whisper's own segments (sentence/
     * phrase-length chunks — never individual words: neither Groq nor
     * OpenAI's Whisper API exposes true per-word confidence, only word
     * TIMESTAMPS with no probability attached), each tagged with a rough
     * confidence tier derived from that segment's avg_logprob. This is an
     * approximation — how sure Whisper was it heard the words right, not a
     * calibrated phonetic pronunciation score — good enough to flag "this
     * stretch is worth listening back to", not a precise measurement.
     * Thresholds are a starting heuristic, not derived from any dataset.
     *
     * @return array{text: string, duration: float, segments: list<array{text: string, confidence: string}>}
     */
    public function transcribeWithConfidence(string $audioPath): array
    {
        $data = $this->request($audioPath, verbose: true, segments: true);

        $segments = collect($data['segments'])
            ->map(fn (array $segment) => [
                'text' => trim((string) ($segment['text'] ?? '')),
                'confidence' => self::confidenceTier((float) ($segment['avg_logprob'] ?? 0.0)),
            ])
            ->filter(fn (array $segment) => $segment['text'] !== '')
            ->values()
            ->all();

        return ['text' => $data['text'], 'duration' => $data['duration'], 'segments' => $segments];
    }

    private static function confidenceTier(float $avgLogprob): string
    {
        return match (true) {
            $avgLogprob >= -0.35 => 'high',
            $avgLogprob >= -0.7 => 'medium',
            default => 'low',
        };
    }

    /**
     * @return array{text: string, duration: float, segments: array}
     */
    private function request(string $audioPath, bool $verbose = false, bool $segments = false): array
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('GROQ_API_KEY is not set.');
        }

        $payload = [
            'model' => $this->whisperModel,
            'response_format' => $verbose ? 'verbose_json' : 'json',
        ];

        if ($segments) {
            $payload['timestamp_granularities[]'] = 'segment';
        }

        // Same treatment as GeminiClient::chat() — Groq's Whisper endpoint
        // occasionally 503s or hangs outright under load too, and this
        // backs every transcription call site (Activation, Story Sequence,
        // Picture Description, both AI Conversation steps, partner
        // sessions, friends conversation). file_get_contents() above reads
        // the audio into an in-memory string (not a stream handle), so the
        // multipart body is safe to resend on retry.
        $response = Http::withToken($this->apiKey)
            ->timeout(20)
            ->retry(2, 500, throw: false)
            ->attach('file', file_get_contents($audioPath), basename($audioPath))
            ->post('https://api.groq.com/openai/v1/audio/transcriptions', $payload)
            ->throw();

        return [
            'text' => (string) data_get($response->json(), 'text', ''),
            'duration' => (float) data_get($response->json(), 'duration', 0.0),
            'segments' => (array) data_get($response->json(), 'segments', []),
        ];
    }
}
