<?php

namespace App\Services;

use App\Models\Evidence;
use App\Models\MissionRun;
use App\Models\User;
use App\Notifications\Concerns\WritesPersianNumbers;
use App\Notifications\FinishLineUpdate;
use Illuminate\Support\Carbon;

/**
 * Tells the learner when they'll reach the end of the 96 days (see
 * FinishLine), at the moments it means something: right after day 1, at
 * the end of every mission, at each quarter of the road, in the last 10
 * days — and, between those, whenever the date has moved 3 days or more
 * either way since they were last told. Never as a daily message.
 */
class FinishLineAlerts
{
    use WritesPersianNumbers;

    /** The last stretch counted down day by day. */
    public const COUNTDOWN_DAYS = 10;

    public function __construct(private readonly FinishLine $finishLine) {}

    /**
     * Called once an answer has finished its mission day (see
     * Evidence::completesItsDay()).
     */
    public function evidenceRecorded(Evidence $evidence): void
    {
        $run = $evidence->missionRun;
        $learner = $run?->learner;

        if (! $learner || ! $evidence->completesItsDay()) {
            return;
        }

        $forecast = $this->finishLine->forecast($learner);

        if ($forecast === null) {
            return;
        }

        $message = $this->milestoneMessage($forecast, $this->finishedMission($run))
            ?? $this->shiftMessage($learner, $forecast);

        if ($message !== null) {
            $this->tell($learner, $message, $forecast);
        }
    }

    /**
     * "Moved 3 days or more since the learner was last told", as the line
     * to send — or null when there's no news. Earlier and later alike.
     *
     * @param  array{steadyDate: Carbon, paceDate: ?Carbon, daysLeft: int}  $forecast
     */
    public function shiftMessage(User $learner, array $forecast): ?string
    {
        $told = $learner->finish_line_told_on;

        if ($told === null || $forecast['daysLeft'] === 0) {
            return null;
        }

        $date = $this->finishLine->headlineDate($forecast);
        $moved = $this->finishLine->daysMoved($told, $date);

        if ($moved < FinishLine::SHIFT_DAYS) {
            return null;
        }

        $persianDate = $this->finishLine->persianDate($date);

        return $date->lt($told)
            ? "آفرین! {$this->persianNumber($moved)} روز جلو افتادی 🚀 خط پایان شد {$persianDate}"
            : "خط پایان رفت {$persianDate} — امروز بیای، برمی‌گرده جلو 💪";
    }

    /**
     * Sends $message and remembers the date it was about.
     *
     * @param  array{steadyDate: Carbon, paceDate: ?Carbon}  $forecast
     */
    public function tell(User $learner, string $message, array $forecast): void
    {
        $learner->notify(new FinishLineUpdate($message));
        $learner->forceFill(['finish_line_told_on' => $this->finishLine->headlineDate($forecast)->toDateString()])->save();
    }

    /**
     * @param  array{daysDone: int, daysLeft: int, steadyDate: Carbon, paceDate: ?Carbon}  $forecast
     */
    private function milestoneMessage(array $forecast, ?string $finishedMissionCode): ?string
    {
        $daysDone = $forecast['daysDone'];
        $daysLeft = $forecast['daysLeft'];
        $dates = $this->finishLine->datesLine($forecast);

        if ($daysLeft === 0) {
            return 'خط پایان! هر '.$this->persianNumber(ProgramPlanner::TOTAL_DAYS).' روز رو تموم کردی 🏁';
        }

        if ($daysDone === 1) {
            return 'اگه هر روز بیای، '.$this->finishLine->persianDate($forecast['steadyDate']).' به خط پایان می‌رسی — '.FinishLine::OUTCOME.' 🎯';
        }

        if ($daysLeft <= self::COUNTDOWN_DAYS) {
            return "فقط {$this->persianNumber($daysLeft)} روز تا خط پایان مونده 🏁 {$dates}";
        }

        $quarter = match ($daysDone) {
            ProgramPlanner::TOTAL_DAYS / 4 => 'یک‌چهارم راه رو اومدی 🎉',
            ProgramPlanner::TOTAL_DAYS / 2 => 'نصف راه رو اومدی 🎉',
            ProgramPlanner::TOTAL_DAYS * 3 / 4 => 'سه‌چهارم راه رو اومدی 🎉',
            default => null,
        };

        if ($quarter !== null) {
            return "{$quarter} {$dates} — ".FinishLine::OUTCOME;
        }

        if ($finishedMissionCode !== null) {
            $missionsLeft = intdiv($daysLeft, ProgramPlanner::DAYS_PER_MISSION);

            return "ماموریت {$finishedMissionCode} تموم شد ✓ {$this->persianNumber($missionsLeft)} ماموریت مونده · {$dates}";
        }

        return null;
    }

    /** The mission's code when the day just finished was its last one. */
    private function finishedMission(MissionRun $run): ?string
    {
        $allDaysDone = collect($run->dayProgress())->every(fn (array $day) => $day['done']);

        return $allDaysDone ? $run->mission->code : null;
    }
}
