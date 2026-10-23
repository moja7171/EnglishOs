<?php

namespace App\Services;

use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;

/**
 * All 24 missions as photo cards, for the Missions page (/missions): each
 * card has the mission's cover and its four days, and every day holds its
 * three parts — the Lesson (the mission's steps), Listening and Daily
 * talk — each with its own tick and the page it opens (see
 * <x-mission-grid>'s day sheet). Past / today / future comes from
 * ListeningPicks::missionMap().
 *
 * The lesson of the open mission opens on home's day bar; any other
 * reached mission opens its runner on that day's first step. A finished
 * mission's photo opens its recap on the Progress page.
 */
class MissionGrid
{
    public function __construct(
        private ProgramPlanner $planner,
        private ListeningPicks $picks,
        private PexelsClient $pexels,
    ) {}

    /**
     * @return array{reachedDay: int, missions: list<array{code: string, title: string, coverUrl: ?string, reached: bool, current: bool, done: bool, photoUrl: ?string, days: list<array{number: int, state: string, lesson: bool, listened: bool, talked: bool, lessonUrl: ?string, listeningUrl: ?string, talkUrl: ?string}>}>}
     */
    public function for(User $learner): array
    {
        $today = $this->planner->plan($learner)['today'];
        $current = $this->picks->dayFor($today);
        $currentIndex = $current === null ? ProgramPlanner::TOTAL_DAYS - 1 : $this->picks->indexOf($current['missionCode'], $current['dayNumber']);
        $seeded = Mission::all()->keyBy('code');
        $runs = $learner->missionRuns()->with('mission')->get()->keyBy(fn (MissionRun $run) => $run->mission->code);
        $openCode = $today['kind'] === 'mission_day' ? $today['mission']->code : null;
        $listened = $learner->listenedDaysByMission();
        $talked = $learner->piPracticedDaysByMission();

        $lessonDays = $runs
            ->map(fn (MissionRun $run) => $this->isFinished($run)
                ? [1, 2, 3, 4]
                : collect($run->dayProgress())->filter(fn (array $day) => $day['done'])->keys()->map(fn (int $index) => $index + 1)->all())
            ->all();

        $missions = collect($this->picks->missionMap($currentIndex, $current === null ? null : $currentIndex, $lessonDays))
            ->map(function (array $mission) use ($seeded, $runs, $openCode, $listened, $talked) {
                $code = $mission['code'];
                $days = collect($mission['days'])
                    ->map(function (array $day) use ($code, $seeded, $openCode, $listened, $talked) {
                        $reached = $day['state'] !== 'future';

                        return [
                            'number' => $day['number'],
                            'state' => $day['state'],
                            'lesson' => $day['done'],
                            'listened' => in_array($day['number'], $listened[$code] ?? [], true),
                            'talked' => in_array($day['number'], $talked[$code] ?? [], true),
                            'lessonUrl' => $reached ? $this->lessonUrl($code, $day, $seeded->get($code), $openCode) : null,
                            'listeningUrl' => $reached ? route('listening.show', [$code, $day['number']]) : null,
                            'talkUrl' => $reached ? route('pi.practice', [$code, $day['number']]) : null,
                        ];
                    })
                    ->all();
                $run = $runs->get($code);
                $finished = $run !== null && $this->isFinished($run);

                return [
                    'code' => $code,
                    'title' => $seeded->get($code)->title ?? $mission['title'],
                    'coverUrl' => $this->coverUrl($code),
                    'reached' => $mission['reached'],
                    'current' => $mission['current'],
                    'done' => $finished,
                    'photoUrl' => $finished
                        ? route('progress.mission', $run->mission)
                        : collect($days)->whereNotNull('lessonUrl')->last()['lessonUrl'] ?? null,
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
     * @param  array{number: int, state: string}  $day
     */
    private function lessonUrl(string $code, array $day, ?Mission $mission, ?string $openCode): ?string
    {
        return match (true) {
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
