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
    private readonly string $apiKey;

    private readonly string $model;

    public function __construct(?string $apiKey = null, ?string $model = null)
    {
        $this->apiKey = $apiKey ?? (string) config('services.gemini.key');
        $this->model = $model ?? (string) config('services.gemini.model', 'gemini-3.5-flash-lite');
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

        // Gemini's flash tier occasionally returns 503 ("high demand") or
        // hangs outright under load — a short timeout + 2 retries clears
        // most of these transient blips without leaving the learner
        // waiting the full round-trip 3 times over on a hard failure.
        $response = Http::withHeaders(['x-goog-api-key' => $this->apiKey])
            ->timeout(20)
            ->retry(2, 500, throw: false)
            ->post("https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent", $payload)
            ->throw();

        return data_get($response->json(), 'candidates.0.content.parts.0.text', '');
    }
}
