<?php

namespace App\Services;

use App\Models\User;

/**
 * The daily voice practice with Pi, outside the app: which program day's
 * practice (App\Services\PiPrompts::dayTask()) the learner is pointed at.
 *
 * It is meant as the last 10 minutes of a learner's day, but a program day
 * is progress-based, not calendar-based (see ProgramPlanner). Finishing a
 * mid-mission day keeps Today on that day until the date turns, but
 * finishing Day 4 closes the whole mission, so the practice of the day just
 * finished would never be shown at the very moment it is meant for. This
 * class covers that: a day the learner finished earlier today, and hasn't
 * ticked "I practiced" for yet, stays the target until they tick (or the
 * calendar day ends). Only that one day carries over — nothing piles up
 * into a backlog.
 */
class PiPractice
{
    public function __construct(private ListeningPicks $picks) {}

    /**
     * The day the practice entry points should open: the day the learner
     * finished today and hasn't practiced yet, otherwise their current
     * program day. Null when neither exists (every mission done, nothing
     * finished today).
     *
     * @param  array<string, mixed>  $today  ProgramPlanner::plan()['today']
     * @return array{missionCode: string, dayNumber: int}|null
     */
    public function targetFor(User $learner, array $today): ?array
    {
        if (! $learner->hasPracticedWithPiToday()) {
            $finished = $this->dayFinishedToday($learner);

            if ($finished !== null) {
                return $finished;
            }
        }

        return $this->picks->dayFor($today);
    }

    /**
     * The most recently finished day among the learner's latest two runs
     * (the second one covers a mission finished today whose successor was
     * already opened), if it was finished today.
     *
     * @return array{missionCode: string, dayNumber: int}|null
     */
    private function dayFinishedToday(User $learner): ?array
    {
        $latest = null;

        foreach ($learner->missionRuns()->with('mission')->latest('started_at')->limit(2)->get() as $run) {
            foreach ($run->dayProgress() as $index => $day) {
                $finishedAt = $day['completedAt'];

                if ($finishedAt === null || ! $finishedAt->isToday()) {
                    continue;
                }

                if ($latest === null || $finishedAt->gte($latest['finishedAt'])) {
                    $latest = [
                        'missionCode' => $run->mission->code,
                        'dayNumber' => $index + 1,
                        'finishedAt' => $finishedAt,
                    ];
                }
            }
        }

        return $latest === null ? null : [
            'missionCode' => $latest['missionCode'],
            'dayNumber' => $latest['dayNumber'],
        ];
    }
}
