<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Thin wrapper around Gemini's generateContent REST API — the LLM behind
 * the AI Instructor (conversation, feedback, error extraction, mission
 * result decisions). See EOS-009 §8.
 */
class GeminiClient
{
    private readonly string $apiKey;

    private readonly string $model;

    private readonly string $fallbackModel;

    public function __construct(?string $apiKey = null, ?string $model = null, ?string $fallbackModel = null)
    {
        $this->apiKey = $apiKey ?? (string) config('services.gemini.key');
        $this->model = $model ?? (string) config('services.gemini.model', 'gemini-3.5-flash-lite');
        $this->fallbackModel = $fallbackModel ?? (string) config('services.gemini.fallback_model', 'gemini-flash-latest');
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
        // hangs outright under load — a short timeout clears most transient
        // blips without leaving the learner waiting too long on a hard
        // failure.
        //
        // But that's useless when the configured model itself is the thing
        // that's down (2026-09-04: gemini-3.5-flash-lite hung outright on
        // every request while gemini-flash-latest, same key, responded
        // fine). So once the primary model's own attempt is exhausted, fall
        // back to a single attempt against a second, deliberately "-latest"
        // (moving, not pinned) model before giving up for real. Each model
        // gets exactly 1 attempt (see attempt()'s retry(1, ...)) — the
        // fallback model IS the retry, so this stays a worst case of ~40s
        // (2 models × 1 attempt × 20s) instead of doubling again per model.
        try {
            return $this->attempt($this->model, $payload);
        } catch (Throwable $e) {
            if ($this->fallbackModel === '' || $this->fallbackModel === $this->model) {
                throw $e;
            }

            Log::warning('GeminiClient: primary model failed, retrying with fallback model.', [
                'primary_model' => $this->model,
                'fallback_model' => $this->fallbackModel,
                'error' => $e->getMessage(),
            ]);

            return $this->attempt($this->fallbackModel, $payload);
        }
    }

    /**
     * One full timeout+retry attempt against a single model. Left to throw
     * on failure — chat() decides whether that's the end of the road or a
     * cue to try the fallback model.
     */
    private function attempt(string $model, array $payload): string
    {
        // 1 attempt per model, not 2 — with the primary-then-fallback chain
        // above, retry(2,...) here would mean a worst case of 2 models × 2
        // attempts × 20s ≈ 81s before a learner sees a hard failure. A
        // second model to try IS the retry now, so 1 attempt per model
        // keeps the same "give the request two real chances" behavior
        // while halving the worst case to ~40s.
        $response = Http::withHeaders(['x-goog-api-key' => $this->apiKey])
            ->timeout(20)
            ->retry(1, 500, throw: false)
            ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", $payload)
            ->throw();

        return data_get($response->json(), 'candidates.0.content.parts.0.text', '');
    }
}
