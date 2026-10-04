<?php

namespace App\Models;

use LogicException;

/**
 * A stand-in run for looking at a mission the learner hasn't unlocked yet.
 * It is never saved: a real MissionRun row would count as "already started"
 * in MissionRun::gatingMission() and permanently unlock the mission, so the
 * preview works on a throwaway in-memory run instead — no Evidence, so
 * every step reads as "not reached", and the step screens render against it
 * exactly as they would for a brand-new run. See PreviewMissionRunSynth for
 * how it survives Livewire's round trips.
 */
class PreviewMissionRun extends MissionRun
{
    protected $table = 'mission_runs';

    public static function for(User $learner, Mission $mission): static
    {
        $run = new static;
        $run->forceFill([
            'learner_id' => $learner->id,
            'mission_id' => $mission->id,
            'status' => self::STATUS_IN_PROGRESS,
        ]);
        $run->setRelation('learner', $learner);
        $run->setRelation('mission', $mission);

        return $run;
    }

    /**
     * Relations (evidence, reflection, ...) key off the parent's class
     * name, which would otherwise become preview_mission_run_id.
     */
    public function getForeignKey(): string
    {
        return 'mission_run_id';
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        throw new LogicException('A preview run is look-only and is never saved.');
    }
}
