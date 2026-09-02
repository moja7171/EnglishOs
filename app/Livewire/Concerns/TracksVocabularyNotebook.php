<?php

namespace App\Livewire\Concerns;

use App\Models\VocabularyWord;

/**
 * Shared "let the learner choose which words join the review notebook"
 * behavior for every step that surfaces a finite word list at the moment
 * it's completed (Vocabulary Builder, Listening — see EOS-009 §8). Every
 * word is pre-checked by default (one click adds everything), but nothing
 * is ever silently enrolled — Article 12, Independence: the app offers,
 * the learner decides.
 *
 * Every class using this trait is a mission step component and so already
 * has `public MissionRun $run;` in scope — addWordsToNotebook() relies on
 * it for source_mission_run_id.
 */
trait TracksVocabularyNotebook
{
    /** @var array<int, bool> keyed by index into notebookCandidates() — pre-checked by default */
    public array $wordsToTrack = [];

    /** True once "Add to My Words" has been pressed at least once for this completion. */
    public bool $trackedWords = false;

    /**
     * The word list to offer, in the shape the checkbox UI and
     * addWordsToNotebook() both need — e.g. Vocabulary Builder's
     * selectedWords + their meanings, or Listening's target_phrases.
     *
     * @return list<array{word: string, meaning: string}>
     */
    abstract protected function notebookCandidates(): array;

    /**
     * Call once, right when the step's completion/recap state is entered
     * — every candidate starts checked.
     */
    protected function initWordsToTrack(): void
    {
        $this->wordsToTrack = array_fill(0, count($this->notebookCandidates()), true);
    }

    /**
     * firstOrCreate per checked word, same as before — re-adding an
     * already-tracked word (from this mission or a past one) never resets
     * its review progress.
     */
    public function addWordsToNotebook(): void
    {
        foreach ($this->notebookCandidates() as $index => $candidate) {
            if (! ($this->wordsToTrack[$index] ?? false)) {
                continue;
            }

            VocabularyWord::firstOrCreate(
                ['learner_id' => $this->run->learner_id, 'word' => $candidate['word']],
                [
                    'source_mission_run_id' => $this->run->id,
                    'meaning' => $candidate['meaning'],
                    'next_review_at' => now(),
                ],
            );
        }

        $this->trackedWords = true;
    }
}
