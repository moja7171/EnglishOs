<?php

namespace App\Services;

use App\Models\GrammarPoint;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;

/**
 * The Notebook's Grammar tab: the grammar point of every mission in the
 * course, in order. A mission the learner has started or finished is open,
 * with its lesson's rules to read again; the rest show only their name
 * (greyed) so the learner can see what is coming.
 */
class GrammarShelf
{
    /**
     * @return list<array{code: string, title: string, coverUrl: ?string, focus: string, focusFa: ?string, built: bool, open: bool, current: bool, done: bool, learned: bool, canPractice: bool, intro: ?string, sections: list<array<string, mixed>>}>
     */
    public function for(User $learner): array
    {
        $seeded = Mission::all()->keyBy('code');
        $runs = $learner->missionRuns()->with('mission:id,code')->get()->keyBy(fn (MissionRun $run) => $run->mission->code);
        $points = $learner->grammarPoints()->get()->keyBy('mission_code');
        $grid = app(MissionGrid::class);
        $names = config('grammar_names', []);

        return collect(Mission::roadmapCatalog())
            ->map(function (array $planned, string $code) use ($seeded, $runs, $points, $grid, $names) {
                $mission = $seeded->get($code);
                $lesson = $mission?->stepContent('grammar_in_context') ?? [];
                $run = $runs->get($code);
                $open = $run !== null && $lesson !== [];
                $done = $run !== null && in_array($run->status, [MissionRun::STATUS_COMPLETE, MissionRun::STATUS_NEEDS_REVIEW], true);
                $point = $points->get($code);

                return [
                    'code' => $code,
                    'title' => $mission->title ?? $planned['title'],
                    'coverUrl' => $grid->coverUrl($code),
                    'focus' => $lesson['focus'] ?? $planned['grammar'],
                    'focusFa' => $names[$code] ?? null,
                    'built' => $mission !== null,
                    'open' => $open,
                    'current' => $open && ! $done,
                    'done' => $done,
                    'learned' => $point instanceof GrammarPoint && $point->repetitions >= User::MASTERED_GRAMMAR_REPETITIONS,
                    'canPractice' => $open && app(GrammarPractice::class)->isAvailable($mission),
                    'intro' => $open ? ($lesson['lesson']['intro'] ?? null) : null,
                    'sections' => $open ? ($lesson['lesson']['sections'] ?? []) : [],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * One mission's grammar point name, for the lines on a mission's cards:
     * the seeded lesson's own name when the mission is built, else the
     * roadmap's.
     *
     * @return array{focus: string, focusFa: ?string}|null
     */
    public function nameFor(string $missionCode): ?array
    {
        $planned = Mission::roadmapCatalog()[$missionCode]['grammar'] ?? null;
        $focus = Mission::where('code', $missionCode)->first()?->stepContent('grammar_in_context')['focus'] ?? $planned;

        return $focus === null ? null : ['focus' => $focus, 'focusFa' => config('grammar_names')[$missionCode] ?? null];
    }
}
