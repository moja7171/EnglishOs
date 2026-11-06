<?php

namespace App\Services;

use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use App\Models\VocabularyWord;

/**
 * Which mission a word the learner added themselves belongs to — what the
 * Words tab groups it under. A word added now carries the mission of the
 * page it was tapped on (else the one the learner is on); words saved
 * before that was kept are placed by when they were added: in the mission
 * the learner had started last by then.
 */
class WordMissions
{
    /**
     * The id of the mission for a word added now: the one named by $code
     * (the page it was tapped on) when it exists, else the highest mission
     * the learner has started. null when they have started none.
     */
    public function forNewWord(User $learner, ?string $code): ?int
    {
        if (filled($code) && ($id = Mission::where('code', strtoupper($code))->value('id')) !== null) {
            return $id;
        }

        return $learner->missionRuns()
            ->with('mission:id,code')
            ->get()
            ->sortByDesc(fn (MissionRun $run) => (int) preg_replace('/\D/', '', (string) $run->mission?->code))
            ->first()?->mission_id;
    }

    /**
     * Places every word the learner added before missions were kept: in the
     * mission they had started last at that moment, else the first they
     * started later. A learner with no mission yet leaves theirs unplaced.
     *
     * @return int How many words were placed.
     */
    public function backfill(): int
    {
        $placed = 0;
        $runs = [];

        VocabularyWord::query()
            ->whereNull('source_mission_run_id')
            ->whereNull('added_in_mission_id')
            ->orderBy('id')
            ->chunkById(200, function ($words) use (&$runs, &$placed) {
                foreach ($words as $word) {
                    $runs[$word->learner_id] ??= MissionRun::where('learner_id', $word->learner_id)
                        ->get(['mission_id', 'started_at', 'created_at'])
                        ->sortBy(fn (MissionRun $run) => ($run->started_at ?? $run->created_at)->getTimestamp())
                        ->values();

                    $at = $word->created_at?->getTimestamp() ?? 0;
                    $started = fn (MissionRun $run) => ($run->started_at ?? $run->created_at)->getTimestamp();
                    $run = $runs[$word->learner_id]->last(fn (MissionRun $run) => $started($run) <= $at)
                        ?? $runs[$word->learner_id]->first();

                    if ($run !== null) {
                        $word->forceFill(['added_in_mission_id' => $run->mission_id])->saveQuietly();
                        $placed++;
                    }
                }
            });

        return $placed;
    }
}
