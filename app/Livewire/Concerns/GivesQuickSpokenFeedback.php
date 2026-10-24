<?php

namespace App\Livewire\Concerns;

use App\Services\GroqClient;
use App\Services\SpokenAnswerChecker;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * One line of AI feedback on a spoken answer to a speaking prompt — Daily
 * Review's speaking cards and the Speaking Recall page. Never a grade and
 * never gating anything: the self-grade buttons work the same without it.
 */
trait GivesQuickSpokenFeedback
{
    /**
     * Transcribes the fresh recording and asks the judge for one line of
     * feedback. Any failure — transcription, a dead AI chain, an empty
     * transcript — just means no feedback line.
     */
    protected function quickSpokenFeedback(UploadedFile $recording, string $prompt): ?string
    {
        try {
            $transcript = trim(app(GroqClient::class)->transcribe($recording->getRealPath()));

            if ($transcript === '') {
                return null;
            }

            $feedback = app(SpokenAnswerChecker::class)->quickFeedback($prompt, $transcript, auth()->user()->levelDescription());

            return $feedback !== '' ? $feedback : null;
        } catch (Throwable) {
            return null;
        }
    }
}
