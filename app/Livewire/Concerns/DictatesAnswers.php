<?php

namespace App\Livewire\Concerns;

use App\Services\AiFailure;
use App\Services\GroqClient;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * "Type it or say it" for a written answer: the learner may tap the mic in
 * the answer box instead of typing, and the take comes back as text in
 * that same box (see <x-type-or-say>), checked exactly like a typed answer.
 *
 * Every class using this trait is a mission step component that also uses
 * TracksAiUsage (the Groq counter) and Livewire's WithFileUploads.
 */
trait DictatesAnswers
{
    /** The latest dictated take, for whichever box's mic was tapped. */
    public ?UploadedFile $dictation = null;

    /** Error under the box whose take could not be turned into text, keyed like the boxes. */
    public array $dictationErrors = [];

    /**
     * Turns the uploaded take into text. Null (with a short message under
     * that box) when nothing came back.
     */
    protected function dictatedText(int|string $key): ?string
    {
        unset($this->dictationErrors[$key]);

        $this->validate([
            'dictation' => ['required', 'file', 'extensions:webm,ogg,mp3,wav,m4a', 'max:20480'],
        ]);

        try {
            $text = trim(app(GroqClient::class)->transcribe($this->dictation->getRealPath()));
            $this->recordGroqCall();
        } catch (Throwable $e) {
            $this->dictationErrors[$key] = AiFailure::message($e, "Couldn't hear that");

            return null;
        } finally {
            $this->dictation = null;
        }

        if ($text === '') {
            $this->dictationErrors[$key] = "Couldn't hear that — try again or type it.";

            return null;
        }

        return $text;
    }
}
