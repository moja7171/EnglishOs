<?php

namespace App\Services;

use App\Services\Concerns\UsesOutboundProxy;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper around Groq's OpenAI-compatible Whisper transcription API —
 * turns a learner's recorded Speaking Evidence into text. See EOS-009 §11.
 */
class GroqClient
{
    use UsesOutboundProxy;

    private const PROVIDER = 'groq';

    private readonly string $apiKey;

    /**
     * Set only when the caller pinned models in the constructor (ad-hoc
     * probes such as the diagnostics report); a pinned chain never touches
     * the shared "unavailable" memory.
     *
     * @var list<array{model: string, thinking_level: ?string}>|null
     */
    private readonly ?array $pinnedChain;

    private readonly AiModelChain $chain;

    public function __construct(?string $apiKey = null, ?string $whisperModel = null, ?string $fallbackModel = null)
    {
        $this->apiKey = $apiKey ?? (string) config('services.groq.key');
        $this->chain = new AiModelChain;

        $this->pinnedChain = ($whisperModel !== null || $fallbackModel !== null)
            ? AiModelChain::parseChain([
                $whisperModel ?? (string) config('services.groq.whisper_model', 'whisper-large-v3-turbo'),
                $fallbackModel ?? (string) config('services.groq.fallback_model', 'whisper-large-v3'),
            ])
            : null;
    }

    /**
     * The configured Whisper chain, best first, with the legacy model +
     * fallback pair standing in when no list is configured.
     *
     * @return list<array{model: string, thinking_level: ?string}>
     */
    public function configuredChain(): array
    {
        if ($this->pinnedChain !== null) {
            return $this->pinnedChain;
        }

        $configured = AiModelChain::parseChain((array) config('services.groq.whisper_models', []));

        if ($configured !== []) {
            return $configured;
        }

        return AiModelChain::parseChain([
            (string) config('services.groq.whisper_model', 'whisper-large-v3-turbo'),
            (string) config('services.groq.fallback_model', 'whisper-large-v3'),
        ]);
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
     * ⚡ai-conversation1.blade.php's transcribeAndReflect().
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

    /**
     * Same transcription, but keeping Whisper's own segment start/end
     * times (seconds) instead of collapsing them into a confidence tier
     * — the real timing a shadowing player pauses on. Meant to be called
     * once per mission audio file, offline (see
     * missions:cache-shadow-timestamps), never live on a page view.
     *
     * @return list<array{text: string, start: float, end: float}>
     */
    public function transcribeSegmentsWithTimestamps(string $audioPath): array
    {
        $data = $this->request($audioPath, verbose: true, segments: true);

        return collect($data['segments'])
            ->map(fn (array $segment) => [
                'text' => trim((string) ($segment['text'] ?? '')),
                'start' => (float) ($segment['start'] ?? 0.0),
                'end' => (float) ($segment['end'] ?? 0.0),
            ])
            ->filter(fn (array $segment) => $segment['text'] !== '')
            ->values()
            ->all();
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

        // Same chain walk as GeminiClient::chat() — Groq's Whisper endpoint
        // occasionally 503s, hangs, or runs out of its per-day audio
        // allowance too, and this backs every transcription call site
        // (Activation, Story Sequence, Picture Description, both AI
        // Conversation steps, partner sessions, friends conversation, Sage
        // voice). The big upload is read once into a string above, so the
        // same bytes are safe to resend to the next model.
        return $this->chain->walk(
            provider: self::PROVIDER,
            logLabel: 'GroqClient',
            chain: $this->configuredChain(),
            attempt: fn (array $entry, int $timeout) => $this->attempt($entry['model'], $payload, $fileBody, $filename, $timeout),
            limits: [
                'max_attempts' => (int) config('services.groq.max_attempts', 3),
                'budget' => (int) config('services.groq.total_budget', 25),
                'attempt_timeout' => (int) config('services.groq.attempt_timeout', 12),
            ],
            tracked: $this->pinnedChain === null,
            // The upload size is what failed on the Iranian host's network
            // path for big bodies, so it is part of every failure line.
            logContext: ['audio_bytes' => strlen($fileBody)],
        );
    }

    /**
     * One full timeout+retry attempt against a single Whisper model. Left
     * to throw on failure — request() decides whether that's the end of
     * the road or a cue to try the fallback model.
     *
     * @return array{text: string, duration: float, segments: array}
     */
    private function attempt(string $model, array $payload, string $fileBody, string $filename, int $timeoutSeconds): array
    {
        $payload['model'] = $model;

        // 1 attempt per model: the next model in the chain IS the retry.
        $url = 'https://api.groq.com/openai/v1/audio/transcriptions';

        $response = $this->withOutboundProxy(
            Http::withToken($this->apiKey)
                ->timeout($timeoutSeconds)
                ->retry(1, 500, throw: false),
            $url,
        )
            ->attach('file', $fileBody, $filename)
            ->post($this->outboundUrl($url), $payload)
            ->throw();

        return [
            'text' => (string) data_get($response->json(), 'text', ''),
            'duration' => (float) data_get($response->json(), 'duration', 0.0),
            'segments' => (array) data_get($response->json(), 'segments', []),
        ];
    }
}
