<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper around Gemini's generateContent REST API — the LLM behind
 * the AI Instructor (conversation, feedback, error extraction, mission
 * result decisions). See EOS-009 §8.
 */
class GeminiClient
{
    public function __construct(
        private readonly string $apiKey = '',
        private readonly string $model = 'gemini-2.5-flash',
    ) {
        $this->apiKey = $apiKey !== '' ? $apiKey : (string) config('services.gemini.key');
        $this->model = $model !== 'gemini-2.5-flash' ? $model : (string) config('services.gemini.model', 'gemini-2.5-flash');
    }

    /**
     * Sends a single-turn (or pre-built multi-turn) prompt and returns the
     * model's text reply.
     *
     * @param  array<int, array{role: string, text: string}>  $messages  Chat history, oldest first. role is 'user' or 'model'.
     */
    public function chat(array $messages, ?string $systemPrompt = null): string
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY is not set.');
        }

        $payload = [
            'contents' => collect($messages)->map(fn (array $m) => [
                'role' => $m['role'],
                'parts' => [['text' => $m['text']]],
            ])->all(),
        ];

        if ($systemPrompt) {
            $payload['systemInstruction'] = ['parts' => [['text' => $systemPrompt]]];
        }

        $response = Http::withHeaders(['x-goog-api-key' => $this->apiKey])
            ->post("https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent", $payload)
            ->throw();

        return data_get($response->json(), 'candidates.0.content.parts.0.text', '');
    }
}
