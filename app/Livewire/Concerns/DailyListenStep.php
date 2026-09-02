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

    public function mount(): void
    {
        if ($this->readOnly) {
            $this->listened = true;
        }
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

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => $this->phaseKey(),
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => '1',
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

    public function hook(): ?string
    {
        return $this->run->mission->stepContent($this->phaseKey())['hook'] ?? null;
    }

    abstract protected function phaseKey(): string;
}
