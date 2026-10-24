<?php

namespace App\Livewire\Concerns;

use App\Services\GroqClient;
use App\Services\SpokenAnswerChecker;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The lenient AI check on each shadowing recording — Whisper transcribes
 * the take, then SpokenAnswerChecker::checkShadowing() judges whether the
 * learner really said the line. The verdict is kept on the line's
 * ShadowDraft, so it comes back with the recording.
 *
 * The using component also uses KeepsShadowDrafts, TracksAiUsage and
 * TracksCheckAttempts, has `public MissionRun $run;` and a shadowLines()
 * list, and wires <x-voice-recorder>'s on-recorded to checkShadowLine.
 */
trait ChecksShadowLines
{
    /** @var array<string, array{severity: string, hint: string, unchecked?: bool}> keyed by "shadow_{index}" */
    public array $feedback = [];

    /** @var array<string, string> keyed by "shadow_{index}" — per-line check failure message */
    public array $checkErrors = [];

    /**
     * @return list<string>
     */
    abstract public function shadowLines(): array;

    /**
     * Same as restoreShadowDrafts(), plus each kept line's verdict.
     */
    protected function restoreShadowDraftsWithFeedback(): void
    {
        foreach ($this->restoreShadowDrafts() as $draft) {
            if ($draft->feedback) {
                $this->feedback["shadow_{$draft->line_index}"] = $draft->feedback;
            }
        }
    }

    /**
     * Fires automatically once a shadow recording finishes uploading (see
     * <x-voice-recorder>'s onRecorded).
     */
    public function checkShadowLine(int $index): void
    {
        $draft = $this->shadowDraft($index);
        $target = $this->shadowLines()[$index] ?? null;

        if (! $draft || ! $target) {
            return;
        }

        $key = "shadow_{$index}";
        unset($this->checkErrors[$key]);

        try {
            $transcript = trim(app(GroqClient::class)->transcribe(Storage::disk('public')->path($draft->path)));
            $this->recordGroqCall();

            $data = app(SpokenAnswerChecker::class)->checkShadowing(
                strip_tags(str_replace('**', '', $target)),
                $transcript,
                $this->run->learner->levelDescription(),
            );
            $this->recordGeminiCall();

            $this->feedback[$key] = $data;
            $this->trackCheckAttempt($key, $data['severity']);
        } catch (Throwable $e) {
            // The AI being unreachable says nothing about the learner's
            // shadowing, so the saved recording counts rather than blocking
            // the step on a service they can't fix.
            report($e);

            $this->feedback[$key] = ['severity' => 'none', 'hint' => '', 'unchecked' => true];
        }

        // Kept with the recording, so the verdict comes back with it too.
        $draft->update(['feedback' => $this->feedback[$key]]);
    }
}
