<?php

namespace App\Console\Commands;

use App\Services\GeminiClient;
use App\Services\GroqClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('ai:check')]
#[Description('Sanity-checks connectivity to Gemini (LLM) and Groq (Whisper STT)')]
class AiCheck extends Command
{
    public function handle(): int
    {
        $ok = true;

        $ok = $this->checkGemini() && $ok;
        $ok = $this->checkGroq() && $ok;

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function checkGemini(): bool
    {
        if (! config('services.gemini.key')) {
            $this->warn('Gemini — skipped (GEMINI_API_KEY not set)');

            return true;
        }

        try {
            $reply = (new GeminiClient)->chat([
                ['role' => 'user', 'text' => 'Reply with exactly one word: pong'],
            ]);

            $this->info("Gemini — OK (replied: \"{$reply}\")");

            return true;
        } catch (Throwable $e) {
            $this->error('Gemini — FAILED: '.$e->getMessage());

            return false;
        }
    }

    private function checkGroq(): bool
    {
        if (! config('services.groq.key')) {
            $this->warn('Groq — skipped (GROQ_API_KEY not set)');

            return true;
        }

        $sample = base_path('document/M01/BBC Learning English - Real Easy English Talking about mornings.mp3');

        if (! file_exists($sample)) {
            $this->warn('Groq — skipped (sample audio not found at '.$sample.')');

            return true;
        }

        try {
            $text = (new GroqClient)->transcribe($sample);
            $preview = str($text)->limit(80);

            $this->info("Groq — OK (transcribed: \"{$preview}\")");

            return true;
        } catch (Throwable $e) {
            $this->error('Groq — FAILED: '.$e->getMessage());

            return false;
        }
    }
}
