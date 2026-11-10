<?php

namespace App\Services;

use App\Models\DayActivity;
use App\Models\MissionRun;
use App\Models\PairGame;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What friends see of each other on the Board and a friend's page: which
 * days someone practised and what kind of thing they did — a mission day
 * (and which one), listening, daily talk, review, pair practice, a game.
 * Counts, names and dates only, never an answer. Built on the streak
 * ring's day slices (DayActivity), which are already the learner's own
 * local days. The week runs Saturday to Friday.
 */
class FriendActivity
{
    public const WEEK_STARTS = Carbon::SATURDAY;

    /** @var array<int, Collection<int, DayActivity>> */
    private array $slices = [];

    /** @var array<int, MissionRun|null> */
    private array $runs = [];

    /**
     * The first day (Saturday) of the week $weeksBack weeks before this one.
     */
    public function weekStart(User $viewer, int $weeksBack = 0): Carbon
    {
        return $viewer->localToday()->startOfWeek(self::WEEK_STARTS)->subWeeks($weeksBack);
    }

    /**
     * What $user earned in the competitive games (Ask & Answer, Two Truths,
     * Just a Minute, Who am I) they finished in the week from $start, in
     * half points — the number the Board ranks on its Games tab. Games
     * played inside a pair practice round do not count.
     */
    public function gamePoints(User $user, Carbon $start): int
    {
        return (int) PairGame::query()
            ->involving($user)
            ->standalone()
            ->where('stage', PairGame::STAGE_DONE)
            ->whereIn('game', PairGame::COMPETITIVE)
            ->where('finished_at', '>=', $start->copy()->startOfDay())
            ->where('finished_at', '<', $start->copy()->addDays(7)->startOfDay())
            ->get()
            ->sum(fn (PairGame $game) => $game->personalPoints()[$user->id] ?? 0);
    }

    /**
     * $user's seven days from $start: whether each was practised, its tag
     * ("D2" for a mission day, "·" for other practice) and the day's place
     * against today.
     *
     * @return list<array{date: string, label: string, day: int, practised: bool, missionDay: bool, tag: string, today: bool, future: bool}>
     */
    public function week(User $user, Carbon $start, Carbon $today): array
    {
        $byDate = $this->byDate($user);

        return collect(range(0, 6))->map(function (int $offset) use ($start, $today, $byDate) {
            $date = $start->copy()->addDays($offset);

            return $this->dayCell($date, $byDate->get($date->toDateString()), $today);
        })->all();
    }

    /**
     * One month as whole weeks (Saturday first), each day with what kind
     * of practice it had and whether a game with $viewer was played.
     *
     * @return list<array{date: string, label: string, day: int, practised: bool, missionDay: bool, tag: string, today: bool, future: bool, inMonth: bool, withViewer: bool}>
     */
    public function month(User $user, User $viewer, Carbon $firstOfMonth, Carbon $today): array
    {
        $byDate = $this->byDate($user);
        $gameDays = $this->gameDaysWith($user, $viewer);
        $start = $firstOfMonth->copy()->startOfWeek(self::WEEK_STARTS);
        $end = $firstOfMonth->copy()->endOfMonth()->startOfDay()->endOfWeek(Carbon::FRIDAY)->startOfDay();
        $days = [];

        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $days[] = [
                ...$this->dayCell($date->copy(), $byDate->get($date->toDateString()), $today),
                'inMonth' => $date->month === $firstOfMonth->month,
                'withViewer' => $gameDays->has($date->toDateString()),
            ];
        }

        return $days;
    }

    /**
     * @param  Collection<int, DayActivity>|null  $slices
     * @return array{date: string, label: string, day: int, practised: bool, missionDay: bool, tag: string, today: bool, future: bool}
     */
    private function dayCell(Carbon $date, ?Collection $slices, Carbon $today): array
    {
        $missionDays = $this->missionDaysIn($slices ?? collect(), collect());

        return [
            'date' => $date->toDateString(),
            'label' => substr($date->format('D'), 0, 2),
            'day' => $date->day,
            'practised' => ($slices?->count() ?? 0) > 0,
            'missionDay' => $missionDays !== [],
            'tag' => match (true) {
                $missionDays !== [] => 'D'.max(array_column($missionDays, 'day')),
                ($slices?->count() ?? 0) > 0 => '·',
                default => '',
            },
            'today' => $date->equalTo($today),
            'future' => $date->gt($today),
        ];
    }

    /**
     * The Board's numbers for one week: practice days, how much was done
     * (the tie-breaker) and the seven dots.
     *
     * @return array{days: int, amount: int, week: list<array<string, mixed>>}
     */
    public function weekRow(User $user, Carbon $start, Carbon $today): array
    {
        $week = $this->week($user, $start, $today);
        $amount = $this->byDate($user)
            ->filter(fn (Collection $slices, string $date) => $date >= $start->toDateString() && $date <= $start->copy()->addDays(6)->toDateString())
            ->sum(fn (Collection $slices) => $slices->count());

        return [
            'days' => collect($week)->where('practised', true)->count(),
            'amount' => $amount,
            'week' => $week,
        ];
    }

    /**
     * Everything $user did on $date, as the day sheet lists it.
     *
     * @return list<array{kind: string, title: string, line: string}>
     */
    public function dayItems(User $user, User $viewer, string $date): array
    {
        $slices = $this->byDate($user)->get($date, collect());
        $items = [];

        $this->byDate($user);

        foreach ($this->missionDaysIn($slices, $this->slices[$user->id]) as $missionDay) {
            $items[] = [
                'kind' => 'mission',
                'title' => $missionDay['code'].' · Day '.$missionDay['day'].($missionDay['finished'] ? ' finished' : ''),
                'line' => implode(' · ', $missionDay['steps']),
            ];
        }

        $labels = [
            'listening' => ['Listening', 'A daily listening'],
            'pi' => ['Daily talk', 'A speaking talk'],
            'review' => ['Review', 'Words and sentences'],
            'pair' => ['Pair practice', '12 minutes with a friend'],
            'ai-practice' => ['Practice with Teddy', 'Your AI classmate'],
        ];

        foreach ($labels as $activity => [$title, $line]) {
            if ($slices->contains('activity', $activity)) {
                $items[] = ['kind' => $activity, 'title' => $title, 'line' => $line];
            }
        }

        $games = PairGame::forPair($user, $viewer)->standalone()->where('stage', PairGame::STAGE_DONE)->get()
            ->filter(fn (PairGame $game) => $game->finished_at->copy()->setTimezone($viewer->reminderTimezone())->toDateString() === $date);

        foreach ($games as $game) {
            $items[] = ['kind' => 'game', 'title' => $user->is($viewer) ? PairGame::info($game->game)['name'] : 'Game with you', 'line' => PairGame::info($game->game)['name'].' · team '.PairGame::formatPoints($game->score)];
        }

        if ($games->isEmpty() && $slices->contains('activity', 'game')) {
            $items[] = ['kind' => 'game', 'title' => 'A game', 'line' => 'On the Games page'];
        }

        return $items;
    }

    /**
     * The mission days $user worked on during $date (Y-m-d): which mission
     * and day, the steps done (their keys too) and whether the day was
     * finished on that date.
     *
     * @return list<array{code: string, day: int, steps: list<string>, stepKeys: list<string>, finished: bool}>
     */
    public function missionDaysOn(User $user, string $date): array
    {
        $slices = $this->byDate($user)->get($date, collect());

        return $this->missionDaysIn($slices, $this->slices[$user->id]);
    }

    /**
     * What kind of things $user did on each of $days days from $start:
     * "mission" for any mission step, otherwise the slice's own kind
     * (listening, pi, review, pair, game, ai-practice).
     *
     * @return array<string, list<string>> keyed by date (Y-m-d)
     */
    public function kindsByDate(User $user, Carbon $start, int $days = 7): array
    {
        $byDate = $this->byDate($user);
        $kinds = [];

        for ($offset = 0; $offset < $days; $offset++) {
            $date = $start->copy()->addDays($offset)->toDateString();
            $kinds[$date] = $byDate->get($date, collect())
                ->map(fn (DayActivity $slice) => str_starts_with($slice->activity, 'step:') ? 'mission' : $slice->activity)
                ->unique()
                ->values()
                ->all();
        }

        return $kinds;
    }

    /**
     * The last local day $user did anything, or null if never.
     */
    public function lastPracticedOn(User $user): ?Carbon
    {
        $latest = $this->byDate($user)->keys()->max();

        return $latest === null ? null : Carbon::parse($latest);
    }

    /**
     * The mission days $slices (one date's) touched: which mission and
     * day, the steps done, and whether the day was finished on that date —
     * every step of it done ($everything holds all the learner's slices),
     * the last of them on this date.
     *
     * @param  Collection<int, DayActivity>  $slices
     * @param  Collection<int, DayActivity>  $everything
     * @return list<array{code: string, day: int, steps: list<string>, stepKeys: list<string>, finished: bool}>
     */
    private function missionDaysIn(Collection $slices, Collection $everything): array
    {
        $days = [];

        foreach ($slices as $slice) {
            if (! preg_match('/^step:(\d+):(.+)$/', $slice->activity, $match)) {
                continue;
            }

            $run = $this->runs[(int) $match[1]] ??= MissionRun::with('mission')->find((int) $match[1]);

            if (! $run?->mission) {
                continue;
            }

            foreach ($run->mission->phases ?? [] as $index => $phase) {
                $keys = collect($phase['steps'] ?? [])->map(fn ($step) => is_array($step) ? $step['key'] : $step);

                if ($keys->contains($match[2])) {
                    $key = $run->id.'-'.$index;
                    $days[$key] ??= ['code' => $run->mission->code, 'day' => $index + 1, 'steps' => [], 'stepKeys' => [], 'finished' => false, 'keys' => $keys->map(fn (string $stepKey) => 'step:'.$run->id.':'.$stepKey)->all(), 'date' => $slice->on_date->toDateString()];
                    $days[$key]['steps'][] = $run->mission->stepLabel($match[2]);
                    $days[$key]['stepKeys'][] = $match[2];
                }
            }
        }

        $doneOn = $everything->mapWithKeys(fn (DayActivity $slice) => [$slice->activity => $slice->on_date->toDateString()]);

        return collect($days)->map(function (array $day) use ($doneOn) {
            $dates = collect($day['keys'])->map(fn (string $key) => $doneOn->get($key));
            $day['steps'] = array_values(array_unique($day['steps']));
            $day['stepKeys'] = array_values(array_unique($day['stepKeys']));
            $day['finished'] = $dates->every(fn (?string $date) => $date !== null) && $dates->max() === $day['date'];
            unset($day['keys'], $day['date']);

            return $day;
        })->values()->all();
    }

    /**
     * @return Collection<string, Collection<int, DayActivity>>
     */
    private function byDate(User $user): Collection
    {
        $this->slices[$user->id] ??= DayActivity::where('learner_id', $user->id)
            ->where('on_date', '>=', now()->subMonths(13)->toDateString())
            ->get(['on_date', 'activity']);

        return $this->slices[$user->id]->groupBy(fn (DayActivity $slice) => $slice->on_date->toDateString());
    }

    /**
     * Local days (the viewer's) on which the two finished a game together.
     *
     * @return Collection<string, true>
     */
    private function gameDaysWith(User $user, User $viewer): Collection
    {
        if ($user->is($viewer)) {
            return collect();
        }

        return PairGame::forPair($user, $viewer)->standalone()->where('stage', PairGame::STAGE_DONE)->pluck('finished_at')
            ->mapWithKeys(fn ($finishedAt) => [Carbon::parse($finishedAt)->setTimezone($viewer->reminderTimezone())->toDateString() => true]);
    }

    /**
     * "You and Sara": games finished together, days in a row with a game
     * (ending today or yesterday) and the best Taboo team score.
     *
     * @return array{games: int, daysInARow: int, bestTaboo: ?int}
     */
    public function together(User $viewer, User $friend): array
    {
        $games = PairGame::forPair($viewer, $friend)->standalone()->where('stage', PairGame::STAGE_DONE)->get();
        $days = $this->gameDaysWith($friend, $viewer);
        $run = 0;
        $day = $viewer->localToday();

        if (! $days->has($day->toDateString())) {
            $day->subDay();
        }

        while ($days->has($day->toDateString())) {
            $run++;
            $day->subDay();
        }

        $bestTaboo = $games->where('game', PairGame::GAME_TABOO)->max('score');

        return ['games' => $games->count(), 'daysInARow' => $run, 'bestTaboo' => $bestTaboo === null ? null : (int) $bestTaboo];
    }
}
