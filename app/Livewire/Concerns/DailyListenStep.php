<?php

namespace App\Livewire\Concerns;

use App\Models\Evidence;
use App\Models\MissionRun;

/**
 * Shared logic for the "Daily Listening" gate that opens Day 2/3/4 of a
 * mission — one file per day (⚡daily-listen-2/3/4.blade.php) so each has
 * its own real, distinct step key (Evidence Before Progress requires a
 * FRESH row per day; reusing one key across days would let listening once
 * satisfy every later day too). Deliberately mandatory, by explicit
 * product decision — the one step in the app that blocks on "did you
 * listen", not an AI judgment.
 */
trait DailyListenStep
{
    public MissionRun $run;

    public bool $readOnly = false;

    public bool $listened = false;

    /**
     * A one-line recall prompt, required but never graded/AI-checked —
     * the point is turning passive re-listening into one small act of
     * active retrieval, at zero AI cost. Whatever the learner writes is
     * accepted; there is no wrong answer.
     */
    public string $recall = '';

    public function mount(): void
    {
        if (! $this->readOnly) {
            return;
        }

        $this->listened = true;

        $data = json_decode($this->run->latestEvidence($this->phaseKey())?->content_ref ?? '{}', true);
        $this->recall = is_array($data) ? ($data['recall'] ?? '') : '';
    }

    /**
     * Called the moment the audio finishes playing once — real completed
     * listens only (the same "audio-ended" signal the real Listening step
     * uses for ITS gate), not just pressing play.
     */
    public function markListened(): void
    {
        $this->listened = true;
    }

    public function save(): void
    {
        if (! $this->listened) {
            return;
        }

        if (trim($this->recall) === '') {
            $this->addError('recall', 'Write at least a word or two before continuing.');

            return;
        }

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => $this->phaseKey(),
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode(['listened' => true, 'recall' => trim($this->recall)]),
        ]);

        $this->redirect(route('missions.show', $this->run->mission), navigate: true);
    }

    /**
     * Reuses Day 1's real Listening content (audio + transcript) — this
     * mission only has one real listening episode; the point is repeated
     * exposure to the same audio, not fresh content every day.
     */
    protected function listeningContent(): array
    {
        return $this->run->mission->stepContent('listening');
    }

    /**
     * Lowercased target phrases from the real Listening episode, for the
     * template's client-side "that's one of the key phrases!" reaction as
     * the learner types their recall — a plain string-contains match, no
     * AI call, and never marks a non-matching answer wrong (any genuine
     * recall is a valid one).
     *
     * @return list<string>
     */
    public function targetPhrasesForRecall(): array
    {
        return collect($this->listeningContent()['target_phrases'] ?? [])
            ->pluck('phrase')
            ->map(fn ($phrase) => strtolower($phrase))
            ->values()
            ->all();
    }

    public function hook(): ?string
    {
        return $this->run->mission->stepContent($this->phaseKey())['hook'] ?? null;
    }

    public function recallPrompt(): string
    {
        return $this->run->mission->stepContent($this->phaseKey())['recall_prompt']
            ?? 'Write one word or phrase you remember hearing.';
    }

    abstract protected function phaseKey(): string;
}
