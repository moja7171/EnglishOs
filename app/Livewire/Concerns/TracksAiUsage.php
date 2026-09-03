<?php

namespace App\Livewire\Concerns;

/**
 * A raw per-provider call counter on MissionRun — no cost estimation, no
 * per-call metadata, just "how many real AI requests did this run make".
 * A real end-to-end walkthrough of M01 (2026-09-03) made ~40 real Gemini/
 * Groq calls for one mission completion; this exists so that number is
 * queryable directly (MissionRun::sum('gemini_calls') etc.) rather than
 * guessed at when reasoning about cost as the curriculum scales to 24
 * missions. No admin surface built for it — direct DB queries only.
 *
 * Every class using this trait is a mission step component and so already
 * has `public MissionRun $run;` in scope, same assumption TracksCheckAttempts
 * makes about recordStruggleSignal().
 */
trait TracksAiUsage
{
    /** Call right after any real, successful call to GeminiClient::chat() — directly or via SentenceChecker/SpokenAnswerChecker. */
    protected function recordGeminiCall(): void
    {
        $this->run->increment('gemini_calls');
    }

    /** Call right after any real, successful call to GroqClient's transcribe methods. */
    protected function recordGroqCall(): void
    {
        $this->run->increment('groq_calls');
    }
}
