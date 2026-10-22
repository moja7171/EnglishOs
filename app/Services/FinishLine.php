<?php

namespace App\Services;

use App\Models\MissionRun;
use App\Models\User;
use App\Notifications\Concerns\WritesPersianNumbers;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use IntlCalendar;
use IntlDateFormatter;

/**
 * When the learner reaches the end of the 96 program days, as two dates:
 * at their real pace (program days finished over the last two weeks) and
 * if they do one day a day from now on — the one that's always reachable.
 * Told to them by FinishLineAlerts, and shown on the Progress page.
 */
class FinishLine
{
    use WritesPersianNumbers;

    /** How far back the real pace is measured, in days. */
    public const PACE_WINDOW_DAYS = 14;

    /** A new finish date is only news once it has moved this many days. */
    public const SHIFT_DAYS = 3;

    /** What the finish line means, said at the start and at each quarter. */
    public const OUTCOME = 'روزی که می‌تونی انگلیسی بحث کنی و نظرت رو بگی';

    /**
     * Null before the program has started.
     *
     * @return array{daysDone: int, daysLeft: int, steadyDate: Carbon, paceDate: ?Carbon}|null
     */
    public function forecast(User $learner): ?array
    {
        if ($learner->program_started_at === null) {
            return null;
        }

        $timezone = $learner->reminderTimezone();
        $today = now($timezone)->startOfDay();
        $calendarDays = (int) $learner->program_started_at->copy()->setTimezone($timezone)->startOfDay()->diffInDays($today) + 1;
        $windowDays = max(1, min(self::PACE_WINDOW_DAYS, $calendarDays));
        $windowStart = $today->copy()->subDays($windowDays - 1);

        $daysDone = 0;
        $doneInWindow = 0;
        $finishedToday = false;

        foreach ($learner->missionRuns()->with('mission')->get() as $run) {
            $closed = in_array($run->status, [MissionRun::STATUS_COMPLETE, MissionRun::STATUS_NEEDS_REVIEW], true);

            if ($closed && $run->completed_at !== null && $run->completed_at->copy()->setTimezone($timezone)->lt($windowStart)) {
                $daysDone += count($run->mission->phases ?? []);

                continue;
            }

            foreach ($run->dayProgress() as $day) {
                if (! $day['done'] && ! $closed) {
                    continue;
                }

                $daysDone++;
                $completedOn = $day['completedAt']?->copy()->setTimezone($timezone)->startOfDay();

                if ($completedOn !== null && $completedOn->gte($windowStart)) {
                    $doneInWindow++;
                    $finishedToday = $finishedToday || $completedOn->equalTo($today);
                }
            }
        }

        $daysDone = min(ProgramPlanner::TOTAL_DAYS, $daysDone);
        $daysLeft = ProgramPlanner::TOTAL_DAYS - $daysDone;

        // The next day can start today, unless one was already finished today.
        $start = $finishedToday ? $today->copy()->addDay() : $today->copy();
        $steadyDate = $start->copy()->addDays(max(0, $daysLeft - 1));
        $paceDate = null;

        if ($doneInWindow > 0) {
            $pace = $doneInWindow / $windowDays;
            $paceDate = $start->copy()->addDays(max(0, (int) ceil($daysLeft / $pace) - 1))->max($steadyDate);
        }

        return ['daysDone' => $daysDone, 'daysLeft' => $daysLeft, 'steadyDate' => $steadyDate, 'paceDate' => $paceDate];
    }

    /**
     * The date to judge "has it moved" by: the real pace, or one day a day
     * when there is no pace yet.
     *
     * @param  array{steadyDate: Carbon, paceDate: ?Carbon}  $forecast
     */
    public function headlineDate(array $forecast): Carbon
    {
        return $forecast['paceDate'] ?? $forecast['steadyDate'];
    }

    /**
     * Both dates in one short line — "با این روال ۲۸ بهمن · روزی یه روز ۲۰
     * بهمن" — or just "خط پایان: ۲۰ بهمن" when they're the same day, plus
     * "before Nowruz / Yalda" when the date lands just before one.
     *
     * @param  array{steadyDate: Carbon, paceDate: ?Carbon}  $forecast
     */
    public function datesLine(array $forecast): string
    {
        $steady = $forecast['steadyDate'];
        $pace = $forecast['paceDate'];

        $line = $pace === null || $pace->isSameDay($steady)
            ? 'خط پایان: '.$this->persianDate($steady)
            : 'با این روال '.$this->persianDate($pace).' · روزی یه روز '.$this->persianDate($steady);

        $occasion = $this->occasionBefore($this->headlineDate($forecast));

        return $occasion === null ? $line : "{$line} — {$occasion}";
    }

    /** "۲۵ بهمن", in the Persian calendar. */
    public function persianDate(CarbonInterface $date): string
    {
        $formatter = new IntlDateFormatter('fa_IR@calendar=persian', IntlDateFormatter::NONE, IntlDateFormatter::NONE, $date->getTimezone(), IntlDateFormatter::TRADITIONAL, 'd MMMM');

        return (string) $formatter->format($date);
    }

    /** Days between two told dates, either way. */
    public function daysMoved(CarbonInterface $from, CarbonInterface $to): int
    {
        return (int) abs($from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()));
    }

    /**
     * A familiar day the finish date lands shortly before — Nowruz within a
     * month, Yalda within two weeks — or null.
     */
    private function occasionBefore(Carbon $date): ?string
    {
        $occasions = [
            ['month' => 0, 'day' => 1, 'within' => 30, 'text' => 'قبل از عید 🌱'],
            ['month' => 8, 'day' => 30, 'within' => 14, 'text' => 'قبل از یلدا 🍉'],
        ];

        foreach ($occasions as $occasion) {
            $next = $this->nextPersianDay($date, $occasion['month'], $occasion['day']);
            $daysBefore = (int) $date->copy()->startOfDay()->diffInDays($next, false);

            if ($daysBefore >= 1 && $daysBefore <= $occasion['within']) {
                return $occasion['text'];
            }
        }

        return null;
    }

    /**
     * The first time on or after $date that the Persian calendar reads
     * $day of $month (0 = Farvardin).
     */
    private function nextPersianDay(Carbon $date, int $month, int $day): Carbon
    {
        $calendar = IntlCalendar::createInstance($date->getTimezone(), 'fa_IR@calendar=persian');
        $calendar->setTime($date->copy()->startOfDay()->getTimestamp() * 1000);
        $calendar->set(IntlCalendar::FIELD_MONTH, $month);
        $calendar->set(IntlCalendar::FIELD_DAY_OF_MONTH, $day);

        $candidate = Carbon::createFromTimestamp((int) ($calendar->getTime() / 1000), $date->getTimezone())->startOfDay();

        if ($candidate->lt($date->copy()->startOfDay())) {
            $calendar->add(IntlCalendar::FIELD_YEAR, 1);
            $candidate = Carbon::createFromTimestamp((int) ($calendar->getTime() / 1000), $date->getTimezone())->startOfDay();
        }

        return $candidate;
    }
}
