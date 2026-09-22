<?php

namespace App\Services;

use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\PlacementTest as PlacementTestResult;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The 100-day program (24 missions × 4 days = 96 real days — "100" is the
 * rounded, friendly name; TOTAL_DAYS below is the honest count the actual
 * progress math and "Day N of ..." counter use). Each mission is its own
 * 4 phases/days; there is no separate day after a mission finishes —
 * practicing with an external AI companion like Pi happens as optional
 * cards inside a mission's own steps, not a dedicated day (see the Pi
 * integration in the mission structure redesign notes). This turns the
 * roadmap into a concrete "what do I do today", which nothing in the app
 * answered before.
 *
 * Progress-based, never calendar-enforced (EOS-004 §9 — a day unlocks
 * when the previous day's Evidence is in, whatever the date): the
 * learner's PROGRAM day is where they actually are in the 96, the
 * CALENDAR day is how many days have passed since they started, and the
 * difference is shown as "on track" / "N days behind" — a nudge, not a
 * lock. Nothing here gates anything.
 */
class ProgramPlanner
{
    public const TOTAL_DAYS = 96;

    public const DAYS_PER_MISSION = 4;

    /**
     * Missions that also carry a "your voice, N months in" checkpoint —
     * evenly spaced quarters of the roadmap. Offered once a checkpoint
     * mission's run is done and its checkpoint hasn't been taken yet
     * (see today()'s 'checkpoint' kind) — never its own day or gate. See
     * [[project_growth_without_discouragement_stories]] S3.
     */
    public const CHECKPOINT_MISSIONS = ['M06', 'M12', 'M18', 'M24'];

    /**
     * @return array{
     *   programDay: int, totalDays: int, calendarDay: int, daysDelta: int,
     *   missionNumber: int, totalMissions: int, started: bool,
     *   today: array{kind: string, mission: ?Mission, run: ?MissionRun, dayNumber: ?int, dayLabel: ?string, steps: list<array{key: string, label: string, minutes: int, done: bool, current: bool}>, estimatedMinutes: int, nextMission: ?Mission, nextMissionCode: ?string, speaking: ?array{title: string, focus: ?string, questions: list<string>, prompt: string}}
     * }
     */
    public function plan(User $learner): array
    {
        $runs = $learner->missionRuns()->with('mission')->get();
        $completed = $runs->filter(fn (MissionRun $run) => in_array($run->status, [MissionRun::STATUS_COMPLETE, MissionRun::STATUS_NEEDS_REVIEW], true));
        $completedCount = $completed->count();

        $today = $this->today($learner, $runs, $completed);

        // Mission days count on top of the fully-closed missions before
        // them. Between missions (kind 'checkpoint' or 'start_next') this
        // previews the next mission's day 1 — the same number it'll show
        // once that next run actually starts.
        $programDay = $today['kind'] === 'finished'
            ? self::TOTAL_DAYS
            : $completedCount * self::DAYS_PER_MISSION + ($today['dayNumber'] ?? 1);
        $programDay = max(1, min(self::TOTAL_DAYS, $programDay));

        $calendarDay = $learner->program_started_at
            ? (int) $learner->program_started_at->copy()->startOfDay()->diffInDays(now()->startOfDay()) + 1
            : 1;

        return [
            'programDay' => $programDay,
            'totalDays' => self::TOTAL_DAYS,
            'calendarDay' => $calendarDay,
            'daysDelta' => $programDay - $calendarDay,
            'missionNumber' => min(Mission::TOTAL_ROADMAP_MISSIONS, $completedCount + ($today['kind'] === 'finished' ? 0 : 1)),
            'totalMissions' => Mission::TOTAL_ROADMAP_MISSIONS,
            'started' => $learner->program_started_at !== null,
            'today' => $today,
        ];
    }

    /**
     * @param  Collection<int, MissionRun>  $runs
     * @param  Collection<int, MissionRun>  $completed
     */
    private function today(User $learner, Collection $runs, Collection $completed): array
    {
        $base = [
            'mission' => null, 'run' => null, 'dayNumber' => null, 'dayLabel' => null,
            'steps' => [], 'estimatedMinutes' => 0, 'nextMission' => null, 'nextMissionCode' => null,
            'checkpointAvailable' => false,
        ];

        // An open run (in progress, or sent back for more evidence) always
        // wins: the learner is mid-mission, today is that mission's day.
        $open = $runs
            ->filter(fn (MissionRun $run) => ! in_array($run->status, [MissionRun::STATUS_COMPLETE, MissionRun::STATUS_NEEDS_REVIEW], true))
            ->sortByDesc('started_at')
            ->first();

        if ($open) {
            return $this->missionDay($open) + ['kind' => 'mission_day'] + $base;
        }

        if ($completed->count() >= Mission::TOTAL_ROADMAP_MISSIONS) {
            return ['kind' => 'finished'] + $base;
        }

        $latest = $completed->sortByDesc('started_at')->first();
        $nextCode = sprintf('M%02d', $completed->count() + 1);
        $nextMission = Mission::where('code', $nextCode)->first();

        // Just finished a checkpoint mission and hasn't taken that
        // checkpoint yet: offer it before nudging toward the next
        // mission (never a gate — see checkpointAvailable()'s docblock).
        if ($latest && $this->checkpointAvailable($learner, $latest->mission->code)) {
            return [
                'kind' => 'checkpoint',
                'mission' => $latest->mission,
                'dayNumber' => 1,
                'nextMission' => $nextMission,
                'nextMissionCode' => $nextCode,
                'checkpointAvailable' => true,
            ] + $base;
        }

        return [
            'kind' => 'start_next',
            'dayNumber' => 1,
            'nextMission' => $nextMission,
            'nextMissionCode' => $nextCode,
        ] + $base;
    }

    /**
     * True only right after finishing a checkpoint mission, and only
     * until the learner has actually taken that specific checkpoint —
     * checked directly against the placement_tests table rather than
     * any in-memory state, so revisiting the page after taking it
     * doesn't keep re-offering it. Never used to gate anything; see
     * <x-checkpoint-card> and PlacementTest::KIND_CHECKPOINT.
     */
    private function checkpointAvailable(User $learner, string $missionCode): bool
    {
        if (! in_array($missionCode, self::CHECKPOINT_MISSIONS, true)) {
            return false;
        }

        return ! PlacementTestResult::where('learner_id', $learner->id)
            ->where('kind', PlacementTestResult::KIND_CHECKPOINT)
            ->where('checkpoint_mission_code', $missionCode)
            ->exists();
    }

    private function missionDay(MissionRun $run): array
    {
        $days = $run->dayProgress();
        $index = collect($days)->search(fn ($day) => $day['current']);

        // Every step has Evidence but the run isn't closed (e.g. sent back
        // for more evidence from Mission Result): point at the last day.
        if ($index === false) {
            $index = max(0, count($days) - 1);
        }

        $day = $days[$index] ?? null;
        $recorded = $run->evidence()->pluck('phase')->unique()->all();
        $currentKey = $run->currentStepKey();

        return [
            'mission' => $run->mission,
            'run' => $run,
            'dayNumber' => $index + 1,
            'dayLabel' => $day['label'] ?? null,
            'steps' => collect($day['stepKeys'] ?? [])->map(fn (string $key) => [
                'key' => $key,
                'label' => $run->mission->stepContent($key)['label'] ?? ucwords(str_replace('_', ' ', $key)),
                'minutes' => $run->mission->stepDuration($key),
                'done' => in_array($key, $recorded, true),
                'current' => $key === $currentKey,
            ])->all(),
            'estimatedMinutes' => $day['estimatedMinutes'] ?? 0,
        ];
    }

}
