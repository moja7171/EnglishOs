<?php

namespace App\Services;

use App\Models\Evidence;
use App\Models\User;
use App\Notifications\FriendStudyingNow;
use Illuminate\Support\Facades\Cache;

/**
 * News about a friend's day — "studying now", "finished, your turn",
 * "hasn't studied yet, nudge them" — reaches a learner at most once per
 * friend per day, never about a friend they muted, and not at all with
 * Profile › Reminders › Friends switched off.
 */
class FriendAlerts
{
    public function __construct(private readonly Reminders $reminders) {}

    /**
     * True (and today's one alert about $about used up) when $to may hear
     * about $about now.
     */
    public function claim(User $to, User $about): bool
    {
        if ($to->friend_alerts === false || $to->hasMuted($about)) {
            return false;
        }

        return Cache::add("friend-alert:{$to->id}:{$about->id}:{$to->localToday()->toDateString()}", true, now()->addDays(2));
    }

    /**
     * The learner's first answer of the day: friends who haven't finished
     * their own day yet hear where the learner is studying. An answer that
     * finishes the day is DayFinishedAlerts' news instead.
     */
    public function evidenceRecorded(Evidence $evidence): void
    {
        $run = $evidence->missionRun;
        $learner = $run?->learner;

        if (! $learner || $evidence->completesItsDay() || ! Cache::add('friend-studying-alerts:'.$learner->id.':'.$learner->localToday()->toDateString(), true, now()->addDays(2))) {
            return;
        }

        $where = $run->mission->code.' · '.$run->mission->stepLabel($evidence->phase);

        foreach ($learner->mutualFriends() as $friend) {
            if (! $this->reminders->finishedToday($friend) && $this->claim($friend, $learner)) {
                $friend->notify(new FriendStudyingNow($learner, $where));
            }
        }
    }
}
