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

    /**
     * @var array<int, bool> keyed like wordsToTrack — true for a candidate this learner
     *                       already has in My Words (from an earlier step or mission), snapshotted
     *                       once in initWordsToTrack() so the UI doesn't re-offer it
     */
    public array $alreadyTracked = [];

    /** True once "Add to My Words" has been pressed at least once for this completion. */
    public bool $trackedWords = false;

    /**
     * The word list to offer, in the shape the checkbox UI and
     * addWordsToNotebook() both need — e.g. Vocabulary Builder's own
     * words() + their meanings, or Listening's target_phrases. pos,
     * example and user_sentence are optional: they only feed the review
     * card, so a step that has none of them just leaves them out.
     *
     * @return list<array{word: string, meaning: string, pos?: ?string, example?: ?string, user_sentence?: ?string}>
     */
    abstract protected function notebookCandidates(): array;

    /**
     * Call once, right when the step's completion/recap state is entered
     * — every candidate the learner doesn't have yet starts checked; the
     * ones already in My Words are flagged instead of offered again (the
     * same words routinely appear in Vocabulary Builder and then Listening).
     */
    protected function initWordsToTrack(): void
    {
        $candidates = $this->notebookCandidates();

        $existing = VocabularyWord::where('learner_id', $this->run->learner_id)
            ->whereIn('word', array_column($candidates, 'word'))
            ->pluck('word')
            ->map(fn (string $word) => mb_strtolower($word))
            ->all();

        $this->alreadyTracked = [];
        $this->wordsToTrack = [];

        foreach ($candidates as $index => $candidate) {
            $isTracked = in_array(mb_strtolower($candidate['word']), $existing, true);

            $this->alreadyTracked[$index] = $isTracked;
            $this->wordsToTrack[$index] = ! $isTracked;
        }
    }

    /**
     * True when there is something to offer and every bit of it is already
     * in My Words — the step then skips the checklist and the Add button.
     */
    public function allWordsAlreadyTracked(): bool
    {
        return $this->alreadyTracked !== [] && ! in_array(false, $this->alreadyTracked, true);
    }

    /**
     * firstOrCreate per checked word, same as before — re-adding an
     * already-tracked word (from this mission or a past one) never resets
     * its review progress. A new word's first review is tomorrow: the
     * learner has just practiced it in this very step.
     */
    public function addWordsToNotebook(): void
    {
        foreach ($this->notebookCandidates() as $index => $candidate) {
            if (($this->alreadyTracked[$index] ?? false) || ! ($this->wordsToTrack[$index] ?? false)) {
                continue;
            }

            VocabularyWord::firstOrCreate(
                ['learner_id' => $this->run->learner_id, 'word' => $candidate['word']],
                [
                    'source_mission_run_id' => $this->run->id,
                    'meaning' => $candidate['meaning'],
                    'pos' => filled($candidate['pos'] ?? null) ? $candidate['pos'] : null,
                    'example' => filled($candidate['example'] ?? null) ? $candidate['example'] : null,
                    'user_sentence' => filled($candidate['user_sentence'] ?? null) ? $candidate['user_sentence'] : null,
                    'next_review_at' => now()->addDay(),
                ],
            );
        }

        $this->trackedWords = true;
    }
}
