<?php

namespace App\Services;

use App\Models\Evidence;
use App\Notifications\FriendFinishedDay;
use App\Notifications\PairBothFinished;
use Illuminate\Support\Facades\Cache;

/**
 * Friends push each other: the first time a learner finishes a mission day
 * on a given day, each mutual friend who hasn't finished theirs yet hears
 * "your turn", and a friend who already has gets — together with the
 * learner — "you both finished, practise together?".
 */
class DayFinishedAlerts
{
    public function __construct(private readonly Reminders $reminders) {}

    /**
     * Called for every new Evidence row; acts only when that row is the
     * first-ever answer to the step that completed its day.
     */
    public function evidenceRecorded(Evidence $evidence): void
    {
        $run = $evidence->missionRun;
        $learner = $run?->learner;

        if (! $learner || ! $this->completedItsDay($evidence)) {
            return;
        }

        if (! Cache::add('day-finished-alerts:'.$learner->id.':'.$learner->localToday()->toDateString(), true, now()->addDays(2))) {
            return;
        }

        foreach ($learner->mutualFriends() as $friend) {
            if ($this->reminders->finishedToday($friend)) {
                $friend->notify(new PairBothFinished($learner));
                $learner->notify(new PairBothFinished($friend));
            } else {
                $friend->notify(new FriendFinishedDay($learner));
            }
        }
    }

    private function completedItsDay(Evidence $evidence): bool
    {
        $isFirstAnswer = Evidence::where('mission_run_id', $evidence->mission_run_id)
            ->where('phase', $evidence->phase)
            ->count() === 1;

        if (! $isFirstAnswer) {
            return false;
        }

        $day = collect($evidence->missionRun->dayProgress())
            ->first(fn (array $day) => in_array($evidence->phase, $day['stepKeys'], true));

        return $day !== null && $day['done'];
    }
}
