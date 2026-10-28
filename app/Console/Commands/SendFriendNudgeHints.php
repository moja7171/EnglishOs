<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SendsScheduledPush;
use App\Models\User;
use App\Notifications\FriendNotStudiedYet;
use App\Services\FriendAlerts;
use App\Services\PairPractice;
use App\Services\Reminders;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * At 17:00 their time, a learner who studied today hears which friends
 * haven't yet — "nudge them" — in one alert, each friend at most once a
 * day (see FriendAlerts). Scheduled every minute in routes/console.php.
 */
class SendFriendNudgeHints extends Command
{
    use SendsScheduledPush;

    public const HINT_TIME = '17:00';

    /** At most this many names in one alert. */
    public const MAX_NAMES = 3;

    protected $signature = 'friends:send-nudge-hints';

    protected $description = 'Tell learners who studied today which friends have not yet';

    public function handle(FriendAlerts $friendAlerts, Reminders $reminders): int
    {
        $sent = 0;

        User::query()
            ->whereHas('pushSubscriptions')
            ->lazyById()
            ->each(function (User $user) use ($friendAlerts, $reminders, &$sent): void {
                if ($this->hint($user, $friendAlerts, $reminders)) {
                    $sent++;
                }
            });

        $this->info("Sent {$sent} friend nudge hint(s).");

        return self::SUCCESS;
    }

    private function hint(User $user, FriendAlerts $friendAlerts, Reminders $reminders): bool
    {
        $checkedKey = "friend-nudge-hint-checked:{$user->id}";
        $lastChecked = Cache::get($checkedKey);
        $day = $this->dueWindowDay($user, self::HINT_TIME, $lastChecked ? Carbon::parse($lastChecked) : null);

        if ($day === null) {
            return false;
        }

        // Checked once per afternoon, whatever the outcome.
        Cache::put($checkedKey, $day, now()->addDays(2));

        if ($user->streakRing()['todaySlices'] === 0 || ! $reminders->hasRoomToday($user)) {
            return false;
        }

        $names = $user->mutualFriends()
            ->reject(fn (User $friend) => $friend->streakRing()['todaySlices'] > 0 || Cache::has(PairPractice::nudgeKey($user, $friend)))
            ->filter(fn (User $friend) => $friendAlerts->claim($user, $friend))
            ->take(self::MAX_NAMES)
            ->pluck('name')
            ->values()
            ->all();

        if ($names === []) {
            return false;
        }

        $this->sendPush($user, new FriendNotStudiedYet($names), 'Friend nudge hint');
        $reminders->countSent($user);

        return true;
    }
}
