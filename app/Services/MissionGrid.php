<?php

namespace App\Services;

use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;

/**
 * All 24 missions as photo cards, for the Missions page (/missions): each
 * card has the mission's cover and its four days, and every day holds its
 * three parts — the Lesson (the mission's steps), Listening and Daily
 * talk — each with its own tick. Past / today / future comes from
 * ListeningPicks::missionMap().
 *
 * A card opens the mission's own page (see pageUrl()), where every day's
 * three parts are one tap away; a day's circle opens it on that day. Every
 * built mission looks open here, reached or not — the lock is on its own
 * page (see MissionRun::gatingMission()). A finished mission's page is
 * its recap too.
 */
class MissionGrid
{
    public function __construct(
        private ProgramPlanner $planner,
        private ListeningPicks $picks,
        private PexelsClient $pexels,
    ) {}

    /**
     * @return array{reachedDay: int, missions: list<array{code: string, title: string, coverUrl: ?string, reached: bool, built: bool, current: bool, done: bool, url: ?string, days: list<array{number: int, state: string, lesson: bool, listened: bool, talked: bool, url: ?string}>}>}
     */
    public function for(User $learner): array
    {
        $today = $this->planner->plan($learner)['today'];
        $current = $this->picks->dayFor($today);
        $currentIndex = $current === null ? ProgramPlanner::TOTAL_DAYS - 1 : $this->picks->indexOf($current['missionCode'], $current['dayNumber']);
        $seeded = Mission::all()->keyBy('code');
        $runs = $learner->missionRuns()->with('mission')->get()->keyBy(fn (MissionRun $run) => $run->mission->code);
        $listened = $learner->listenedDaysByMission();
        $talked = $learner->piPracticedDaysByMission();

        $lessonDays = $runs
            ->map(fn (MissionRun $run) => $this->isFinished($run)
                ? [1, 2, 3, 4]
                : collect($run->dayProgress())->filter(fn (array $day) => $day['done'])->keys()->map(fn (int $index) => $index + 1)->all())
            ->all();

        $missions = collect($this->picks->missionMap($currentIndex, $current === null ? null : $currentIndex, $lessonDays))
            ->map(function (array $mission) use ($seeded, $runs, $listened, $talked) {
                $code = $mission['code'];
                $isSeeded = $seeded->has($code);
                $days = collect($mission['days'])
                    ->map(fn (array $day) => [
                        'number' => $day['number'],
                        'state' => $day['state'],
                        'lesson' => $day['done'],
                        'listened' => in_array($day['number'], $listened[$code] ?? [], true),
                        'talked' => in_array($day['number'], $talked[$code] ?? [], true),
                        'url' => $isSeeded ? route('missions.show', [$code, 'overview', 'day' => $day['number']]) : null,
                    ])
                    ->all();
                $run = $runs->get($code);
                $finished = $run !== null && $this->isFinished($run);

                return [
                    'code' => $code,
                    'title' => $seeded->get($code)->title ?? $mission['title'],
                    'coverUrl' => $this->coverUrl($code),
                    'reached' => $mission['reached'],
                    'built' => $isSeeded,
                    'current' => $mission['current'],
                    'done' => $finished,
                    'url' => $isSeeded ? route('missions.show', [$code, 'overview']) : null,
                    'days' => $days,
                ];
            })
            ->all();

        return ['reachedDay' => $currentIndex + 1, 'missions' => $missions];
    }

    /**
     * The mission's photo, already cached on the server (never fetched
     * live): a built mission's Mission Brief cover, else its roadmap image.
     */
    public function coverUrl(string $missionCode): ?string
    {
        return $this->pexels->cachedImageUrl($missionCode.'-brief') ?? $this->pexels->cachedImageUrl($missionCode.'-roadmap');
    }

    /**
     * A mission's own page, opened on one day — the way back from that
     * day's Listening or Daily talk. A mission not built yet has no page,
     * so it falls back to the Missions page.
     */
    public function pageUrl(string $missionCode, ?int $dayNumber = null): string
    {
        return Mission::where('code', $missionCode)->exists()
            ? route('missions.show', [$missionCode, 'overview', 'day' => $dayNumber])
            : route('missions.index');
    }

    private function isFinished(MissionRun $run): bool
    {
        return in_array($run->status, [MissionRun::STATUS_COMPLETE, MissionRun::STATUS_NEEDS_REVIEW], true);
    }
}
