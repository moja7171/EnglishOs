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
    public function __construct(
        private readonly string $apiKey = '',
        private readonly string $whisperModel = 'whisper-large-v3-turbo',
    ) {
        $this->apiKey = $apiKey !== '' ? $apiKey : (string) config('services.groq.key');
        $this->whisperModel = $whisperModel !== 'whisper-large-v3-turbo'
            ? $whisperModel
            : (string) config('services.groq.whisper_model', 'whisper-large-v3-turbo');
    }

    /**
     * Transcribes a local audio file and returns the plain-text result.
     */
    public function transcribe(string $audioPath): string
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('GROQ_API_KEY is not set.');
        }

        $response = Http::withToken($this->apiKey)
            ->attach('file', file_get_contents($audioPath), basename($audioPath))
            ->post('https://api.groq.com/openai/v1/audio/transcriptions', [
                'model' => $this->whisperModel,
            ])
            ->throw();

        return (string) data_get($response->json(), 'text', '');
    }
}
