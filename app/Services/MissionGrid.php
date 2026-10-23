<?php

namespace App\Services;

use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;

/**
 * All 24 missions as photo cards, for the Missions page (/missions) and
 * the Progress page's Path tab: each card has the mission's cover, its
 * four days as circles that open that day in one tap, and whether it is
 * reached, the current one or finished (see ListeningPicks::missionMap()
 * for the past / today / future of each day).
 *
 * The same grid serves every place that switches missions, so where a day
 * opens and what ticks it depends on where the learner came from:
 *  - home: the mission itself, ticked by finished mission days; a day of
 *    the open mission opens on home's day bar
 *  - progress: as home, but a finished mission's photo opens its recap
 *  - listening: that day's listening, ticked by its listens
 *  - talk: that day's Daily talk, ticked by its practice
 */
class MissionGrid
{
    public const CONTEXTS = ['home', 'progress', 'listening', 'talk'];

    public function __construct(
        private ProgramPlanner $planner,
        private ListeningPicks $picks,
        private PiPractice $piPractice,
        private PexelsClient $pexels,
    ) {}

    /**
     * @param  'home'|'progress'|'listening'|'talk'  $context
     * @return array{reachedDay: int, missions: list<array{code: string, title: string, coverUrl: ?string, reached: bool, current: bool, done: bool, photoUrl: ?string, days: list<array{number: int, state: string, done: bool, url: ?string}>}>}
     */
    public function for(User $learner, string $context): array
    {
        $today = $this->planner->plan($learner)['today'];
        $current = $this->picks->dayFor($today);
        $currentIndex = $current === null ? ProgramPlanner::TOTAL_DAYS - 1 : $this->picks->indexOf($current['missionCode'], $current['dayNumber']);
        $seeded = Mission::all()->keyBy('code');
        $runs = $learner->missionRuns()->with('mission')->get()->keyBy(fn (MissionRun $run) => $run->mission->code);

        $todayIndex = match ($context) {
            'talk' => ($target = $this->piPractice->targetFor($learner, $today)) === null ? null : $this->picks->indexOf($target['missionCode'], $target['dayNumber']),
            default => $current === null ? null : $currentIndex,
        };

        $doneDays = match ($context) {
            'listening' => $learner->listenedDaysByMission(),
            'talk' => $learner->piPracticedDaysByMission(),
            default => $runs->map(fn (MissionRun $run) => $this->isFinished($run)
                ? [1, 2, 3, 4]
                : collect($run->dayProgress())->filter(fn (array $day) => $day['done'])->keys()->map(fn (int $index) => $index + 1)->all())
                ->all(),
        };

        $openCode = $today['kind'] === 'mission_day' ? $today['mission']->code : null;

        $missions = collect($this->picks->missionMap($currentIndex, $todayIndex, $doneDays))
            ->map(function (array $mission) use ($context, $seeded, $runs, $openCode) {
                $days = collect($mission['days'])
                    ->map(fn (array $day) => $day + [
                        'url' => $day['state'] === 'future' ? null : $this->dayUrl($context, $mission['code'], $day, $seeded->get($mission['code']), $openCode),
                    ])
                    ->all();
                $run = $runs->get($mission['code']);
                $finished = in_array($context, ['home', 'progress'], true)
                    ? $run !== null && $this->isFinished($run)
                    : collect($days)->every(fn (array $day) => $day['done']);

                return [
                    'code' => $mission['code'],
                    'title' => $seeded->get($mission['code'])->title ?? $mission['title'],
                    'coverUrl' => $this->pexels->cachedImageUrl($mission['code'].'-brief') ?? $this->pexels->cachedImageUrl($mission['code'].'-roadmap'),
                    'reached' => $mission['reached'],
                    'current' => $mission['current'],
                    'done' => $finished,
                    'photoUrl' => $context === 'progress' && $finished
                        ? route('progress.mission', $run->mission)
                        : collect($days)->whereNotNull('url')->last()['url'] ?? null,
                    'days' => $days,
                ];
            })
            ->all();

        return ['reachedDay' => $currentIndex + 1, 'missions' => $missions];
    }

    /**
     * The page a reached day opens, by where the grid was opened from;
     * null when there is nothing to open (a mission not built yet).
     *
     * @param  array{number: int, state: string}  $day
     */
    private function dayUrl(string $context, string $code, array $day, ?Mission $mission, ?string $openCode): ?string
    {
        return match (true) {
            $context === 'listening' => $day['state'] === 'today' ? route('listening.show') : route('listening.show', [$code, $day['number']]),
            $context === 'talk' => $day['state'] === 'today' ? route('pi.practice') : route('pi.practice', [$code, $day['number']]),
            $code === $openCode => route('home', $day['state'] === 'today' ? [] : ['day' => $day['number']]),
            $mission === null => null,
            default => route('missions.show', [$mission, $this->firstStepOfDay($mission, $day['number'])]),
        };
    }

    private function firstStepOfDay(Mission $mission, int $dayNumber): ?string
    {
        $step = $mission->phases[$dayNumber - 1]['steps'][0] ?? null;

        return is_array($step) ? $step['key'] : $step;
    }

    private function isFinished(MissionRun $run): bool
    {
        return in_array($run->status, [MissionRun::STATUS_COMPLETE, MissionRun::STATUS_NEEDS_REVIEW], true);
    }
}
