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
     * @return array{text: string, duration: float}
     */
    private function request(string $audioPath, bool $verbose = false): array
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('GROQ_API_KEY is not set.');
        }

        $response = Http::withToken($this->apiKey)
            ->attach('file', file_get_contents($audioPath), basename($audioPath))
            ->post('https://api.groq.com/openai/v1/audio/transcriptions', [
                'model' => $this->whisperModel,
                'response_format' => $verbose ? 'verbose_json' : 'json',
            ])
            ->throw();

        return [
            'text' => (string) data_get($response->json(), 'text', ''),
            'duration' => (float) data_get($response->json(), 'duration', 0.0),
        ];
    }
}
