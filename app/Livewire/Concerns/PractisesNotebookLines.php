<?php

namespace App\Livewire\Concerns;

use App\Models\NotebookEntry;
use App\Services\FixChecker;
use App\Services\GroqClient;
use App\Services\NotebookDrill;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * The practice card for one notebook line (<x-notebook-drill>, built by
 * NotebookDrill) — shared by Daily Review and the notebook page's own
 * practice, so a line is practised the same way in both. The class must
 * also use WithFileUploads (the "say it" rung records).
 *
 * The line moves on its ladder only when the learner taps Next, so the
 * card on screen never changes under them.
 */
trait PractisesNotebookLines
{
    /** The word or option the learner tapped, null until they do. */
    public ?int $drillAnswer = null;

    /** Null until the card is answered (or its fix shown). */
    public ?bool $drillCorrect = null;

    /** The "say it" rung, typed instead of spoken. */
    public bool $drillTyping = false;

    public string $drillTyped = '';

    /** What the learner said or typed on the "say it" rung. */
    public ?string $drillHeard = null;

    /** The last recording came back empty — say it again. */
    public bool $drillMissedAudio = false;

    public ?UploadedFile $drillRecording = null;

    /**
     * The line on screen, or null when there isn't one.
     */
    abstract protected function drillEntry(): ?NotebookEntry;

    /**
     * What the page does once the learner moves on (the line's rung has
     * already moved).
     */
    abstract protected function afterDrill(NotebookEntry $entry, bool $correct): void;

    /**
     * A tap on a word (spot) or an option (pick, choose).
     */
    public function drillTap(int $index): void
    {
        $entry = $this->drillEntry();

        if (! $entry || $this->drillCorrect !== null) {
            return;
        }

        $card = NotebookDrill::card($entry);
        $tappable = $card['mode'] === 'spot' ? $card['words'] : ($card['options'] ?? []);

        if (! isset($tappable[$index])) {
            return;
        }

        $this->drillAnswer = $index;
        $this->drillCorrect = $card['mode'] === 'spot' ? in_array($index, $card['wrong'], true) : $index === $card['correct'];
    }

    /**
     * Fired once a "say it" recording uploads. An empty transcript or a
     * failed one asks for another try instead of marking it wrong.
     */
    public function drillHeardRecording(): void
    {
        $entry = $this->drillEntry();

        if (! $this->drillRecording || ! $entry || $this->drillCorrect !== null) {
            return;
        }

        try {
            $heard = trim(app(GroqClient::class)->transcribe($this->drillRecording->getRealPath()));
        } catch (Throwable) {
            $heard = '';
        }

        $this->drillRecording = null;
        $this->drillMissedAudio = $heard === '';

        if ($heard !== '') {
            $this->judgeSaid($entry, $heard);
        }
    }

    public function drillCheckTyped(): void
    {
        $entry = $this->drillEntry();

        if (! $entry || $this->drillCorrect !== null || trim($this->drillTyped) === '') {
            return;
        }

        $this->judgeSaid($entry, trim($this->drillTyped));
    }

    public function drillShowFix(): void
    {
        if ($this->drillEntry() && $this->drillCorrect === null) {
            $this->drillCorrect = false;
        }
    }

    public function drillNext(): void
    {
        $entry = $this->drillEntry();

        if (! $entry || $this->drillCorrect === null) {
            return;
        }

        $correct = $this->drillCorrect;
        $entry->practised($correct);
        $this->resetDrill();
        $this->afterDrill($entry, $correct);
    }

    protected function resetDrill(): void
    {
        $this->drillAnswer = null;
        $this->drillCorrect = null;
        $this->drillTyping = false;
        $this->drillTyped = '';
        $this->drillHeard = null;
        $this->drillMissedAudio = false;
        $this->drillRecording = null;
    }

    /**
     * Another right sentence counts too — see FixChecker.
     */
    private function judgeSaid(NotebookEntry $entry, string $said): void
    {
        $this->drillHeard = $said;
        $this->drillCorrect = app(FixChecker::class)->accepts((string) $entry->original, $said, $entry->better);
    }
}
