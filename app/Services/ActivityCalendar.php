<?php

namespace App\Services;

use App\Models\DayActivity;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The learner's last twelve weeks for the Progress page: one cell per day
 * (weeks run Saturday to Friday), coloured by how many kinds of thing they
 * did that day, with the kinds named for a tap, and a mark under each week
 * whose goal of days was met. Built on the streak ring's day slices
 * (DayActivity), so lessons, reviews, Vocab in Use and Extra English all count.
 */
class ActivityCalendar
{
    public const WEEKS = 12;

    public const WEEK_STARTS = Carbon::SATURDAY;

    /** What each slice kind is called when a day is opened. */
    private const KIND_NAMES = [
        'listening' => 'Listening',
        'pi' => 'Daily talk',
        'review' => 'Review',
        'vocab' => 'Vocab in Use',
        'extra' => 'Extra English',
        'pair' => 'Pair practice',
        'ai-practice' => 'Practice with Teddy',
        'game' => 'A game',
    ];

    /**
     * @return array{weeks: list<array{days: list<array{date: string, label: string, level: int, future: bool, today: bool, chain: bool, kinds: list<string>}>, month: string, count: int, met: bool, current: bool}>, total: int, todayIndex: int|null, thisWeekDays: int}
     */
    public function for(User $user, ?int $goal): array
    {
        $today = $user->localToday();
        $start = $today->copy()->startOfWeek(self::WEEK_STARTS)->subWeeks(self::WEEKS - 1);

        $slices = DayActivity::query()
            ->where('learner_id', $user->id)
            ->where('on_date', '>=', $start->toDateString())
            ->get(['on_date', 'activity'])
            ->groupBy(fn (DayActivity $slice): string => $slice->on_date->toDateString());

        $streak = $user->currentStreak();
        $weeks = [];
        $total = 0;
        $chainLeft = $streak;
        $todayIndex = null;
        $cells = [];

        for ($offset = self::WEEKS * 7 - 1; $offset >= 0; $offset--) {
            $date = $start->copy()->addDays($offset);
            $kinds = $this->kinds($slices->get($date->toDateString(), collect()));
            $future = $date->gt($today);
            $level = $future ? 0 : min(3, count($kinds));
            $chain = ! $future && $level > 0 && $chainLeft > 0 && ($date->eq($today) || $date->eq($today->copy()->subDay()) || ($cells[$offset + 1]['chain'] ?? false));

            if ($chain) {
                $chainLeft--;
            }

            $cells[$offset] = [
                'date' => $date->toDateString(),
                'label' => $date->format('D j M'),
                'level' => $level,
                'future' => $future,
                'today' => $date->eq($today),
                'chain' => $chain,
                'kinds' => $kinds,
            ];

            $total += $level > 0 ? 1 : 0;
        }

        ksort($cells);
        $cells = array_values($cells);

        foreach (array_chunk($cells, 7) as $index => $days) {
            $count = count(array_filter($days, fn (array $day): bool => $day['level'] > 0));
            $month = '';

            foreach ($days as $day) {
                if (Carbon::parse($day['date'])->day === 1) {
                    $month = Carbon::parse($day['date'])->format('M');
                }
            }

            $weeks[] = [
                'days' => $days,
                'month' => $month,
                'count' => $count,
                'met' => $goal !== null && $count >= $goal,
                'current' => $index === self::WEEKS - 1,
            ];

            foreach ($days as $position => $day) {
                if ($day['today']) {
                    $todayIndex = $index * 7 + $position;
                }
            }
        }

        return [
            'weeks' => $weeks,
            'total' => $total,
            'todayIndex' => $todayIndex,
            'thisWeekDays' => $weeks[self::WEEKS - 1]['count'],
        ];
    }

    /**
     * The kinds of thing a day held, missions first.
     *
     * @param  Collection<int, DayActivity>  $slices
     * @return list<string>
     */
    private function kinds($slices): array
    {
        $names = $slices->pluck('activity')->map(fn (string $activity): string => str_starts_with($activity, 'step:') ? 'Mission' : (self::KIND_NAMES[$activity] ?? $activity))->unique();

        return $names->sortBy(fn (string $name): int => $name === 'Mission' ? 0 : 1)->values()->all();
    }
}
