<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Thin wrapper around Groq's OpenAI-compatible Whisper transcription API —
 * turns a learner's recorded Speaking Evidence into text. See EOS-009 §11.
 */
class GroqClient
{
    private readonly string $apiKey;

    private readonly string $whisperModel;

    private readonly string $fallbackModel;

    public function __construct(?string $apiKey = null, ?string $whisperModel = null, ?string $fallbackModel = null)
    {
        $this->apiKey = $apiKey ?? (string) config('services.groq.key');
        $this->whisperModel = $whisperModel ?? (string) config('services.groq.whisper_model', 'whisper-large-v3-turbo');
        $this->fallbackModel = $fallbackModel ?? (string) config('services.groq.fallback_model', 'whisper-large-v3');
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
            'response_format' => $verbose ? 'verbose_json' : 'json',
        ];

        if ($segments) {
            $payload['timestamp_granularities[]'] = 'segment';
        }

        // file_get_contents() reads the audio into an in-memory string (not
        // a stream handle), so the same bytes are safe to resend both across
        // a single model's attempt() AND, if that model is the one that's
        // down, across the fallback attempt below.
        $fileBody = file_get_contents($audioPath);
        $filename = basename($audioPath);

        // Same treatment as GeminiClient::chat() — Groq's Whisper endpoint
        // occasionally 503s or hangs outright under load too, and this
        // backs every transcription call site (Activation, Story Sequence,
        // Picture Description, both AI Conversation steps, partner
        // sessions, friends conversation). Once the primary model's own
        // attempt is exhausted, fall back to a single attempt against a
        // second, genuinely available Whisper variant before giving up.
        // Each model gets exactly 1 attempt (see attempt()'s retry(1, ...))
        // — the fallback model IS the retry, keeping the worst case at ~40s
        // (2 models × 1 attempt × 20s) instead of doubling again per model.
        try {
            return $this->attempt($this->whisperModel, $payload, $fileBody, $filename);
        } catch (Throwable $e) {
            if ($this->fallbackModel === '' || $this->fallbackModel === $this->whisperModel) {
                throw $e;
            }

            Log::warning('GroqClient: primary whisper model failed, retrying with fallback model.', [
                'primary_model' => $this->whisperModel,
                'fallback_model' => $this->fallbackModel,
                'error' => $e->getMessage(),
            ]);

            return $this->attempt($this->fallbackModel, $payload, $fileBody, $filename);
        }
    }

    /**
     * One full timeout+retry attempt against a single Whisper model. Left
     * to throw on failure — request() decides whether that's the end of
     * the road or a cue to try the fallback model.
     *
     * @return array{text: string, duration: float, segments: array}
     */
    private function attempt(string $model, array $payload, string $fileBody, string $filename): array
    {
        $payload['model'] = $model;

        // 1 attempt per model — see the worst-case-latency note in
        // request() above.
        $response = Http::withToken($this->apiKey)
            ->timeout(20)
            ->retry(1, 500, throw: false)
            ->attach('file', $fileBody, $filename)
            ->post('https://api.groq.com/openai/v1/audio/transcriptions', $payload)
            ->throw();

        return [
            'text' => (string) data_get($response->json(), 'text', ''),
            'duration' => (float) data_get($response->json(), 'duration', 0.0),
            'segments' => (array) data_get($response->json(), 'segments', []),
        ];
    }
}
