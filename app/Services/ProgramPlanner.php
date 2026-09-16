<?php

namespace App\Services;

use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\PlacementTest as PlacementTestResult;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The 120-day program: 24 missions × 5 days — each mission's own 4 days
 * (its 4 phases) plus one "consolidation" day (Daily Review of whatever
 * is due, a 10-15 minute spoken conversation with an external AI
 * companion like Pi about that mission's topic, a glance at the Error
 * Log) before the next mission starts. This turns the roadmap into a
 * concrete "what do I do today", which nothing in the app answered
 * before.
 *
 * Progress-based, never calendar-enforced (EOS-004 §9 — a day unlocks
 * when the previous day's Evidence is in, whatever the date): the
 * learner's PROGRAM day is where they actually are in the 120, the
 * CALENDAR day is how many days have passed since they started, and the
 * difference is shown as "on track" / "N days behind" — a nudge, not a
 * lock. Nothing here gates anything; the only writes are the
 * consolidation day's own Evidence (see logConsolidationSpeaking in
 * ⚡overview.blade.php), which also counts as an active day for the
 * streak like any other Evidence.
 */
class ProgramPlanner
{
    public const TOTAL_DAYS = 120;

    public const DAYS_PER_MISSION = 5;

    /** Evidence phase recorded on a mission's run when its consolidation day's speaking was logged. */
    public const CONSOLIDATION_PHASE = 'consolidation_speaking';

    /**
     * Consolidation days that also carry a "your voice, N months in"
     * checkpoint — evenly spaced quarters of the roadmap, on days that
     * already exist and are already light, so a checkpoint never needs
     * its own new day or gate. See
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
        // them; a consolidation day belongs to the mission just completed
        // (already in $completedCount), so it's that block's own day 5.
        $programDay = match ($today['kind']) {
            'finished' => self::TOTAL_DAYS,
            'consolidation' => $completedCount * self::DAYS_PER_MISSION,
            default => $completedCount * self::DAYS_PER_MISSION + ($today['dayNumber'] ?? 1),
        };
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
            'steps' => [], 'estimatedMinutes' => 0, 'nextMission' => null, 'nextMissionCode' => null, 'speaking' => null,
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

        // Just finished a mission and haven't logged its consolidation
        // day yet: today is that day.
        if ($latest && ! $latest->evidence()->where('phase', self::CONSOLIDATION_PHASE)->exists()) {
            return [
                'kind' => 'consolidation',
                'mission' => $latest->mission,
                'run' => $latest,
                'dayNumber' => self::DAYS_PER_MISSION,
                'dayLabel' => 'Consolidation',
                'nextMission' => $nextMission,
                'nextMissionCode' => $nextCode,
                'speaking' => $this->speaking($latest->mission),
                'checkpointAvailable' => $this->checkpointAvailable($learner, $latest->mission->code),
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
     * True only on a checkpoint mission's consolidation day, and only
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

    /**
     * What to talk about with an external AI companion on the
     * consolidation day — built from the mission's own seeded content so
     * the conversation reuses exactly what was just learned: its topic,
     * its grammar point, and its Final Challenge questions. The prompt is
     * meant to be pasted as-is into Pi (or any voice-capable assistant).
     *
     * @return array{title: string, focus: ?string, questions: list<string>, prompt: string}
     */
    public function speaking(Mission $mission): array
    {
        $focus = $mission->stepContent('grammar_in_context')['focus'] ?? null;
        $questions = collect($mission->stepContent('ai_conversation_2')['rounds'] ?? [])
            ->filter(fn ($q) => is_string($q) && $q !== '')
            ->take(3)
            ->values()
            ->all();

        $lines = [
            "I'm learning English (around B1 level). Let's have a 10-minute spoken conversation about \"{$mission->title}\".",
            'Ask me one question at a time and wait for my answer. Start with these:',
        ];
        foreach ($questions as $i => $question) {
            $lines[] = ($i + 1).'. '.$question;
        }
        if ($focus) {
            $lines[] = "I'm practising \"{$focus}\" — please notice when I use it.";
        }
        $lines[] = 'At the end, tell me my 3 most useful corrections in simple English.';

        return [
            'title' => $mission->title,
            'focus' => $focus,
            'questions' => $questions,
            'prompt' => implode("\n", $lines),
        ];
    }

    /**
     * Records the consolidation day's speaking practice on the mission's
     * run — Evidence like any other, so it counts as an active day for
     * the streak and moves the program on to the next mission's day 1.
     */
    public function logConsolidationSpeaking(MissionRun $run, string $sentence): Evidence
    {
        return Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => self::CONSOLIDATION_PHASE,
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => $sentence,
        ]);
    }
}
