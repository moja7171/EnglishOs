<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SendsScheduledPush;
use App\Models\User;
use App\Notifications\PairStreakWarning;
use App\Services\PairPractice;
use App\Services\Reminders;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Warns a learner at 20:00 their time that the pair streak with a friend
 * breaks tonight because today doesn't keep their streak yet (see StreakRing) — one
 * alert a day, about the friend with the longest pair streak. Scheduled
 * every minute in routes/console.php.
 */
class SendPairStreakWarnings extends Command
{
    use SendsScheduledPush;

    public const WARNING_TIME = '20:00';

    protected $signature = 'pair:send-streak-warnings';

    protected $description = 'Warn learners whose pair streak with a friend breaks tonight';

    public function handle(PairPractice $pairPractice, Reminders $reminders): int
    {
        $sent = 0;

        User::query()
            ->whereHas('pushSubscriptions')
            ->lazyById()
            ->each(function (User $user) use ($pairPractice, $reminders, &$sent): void {
                if ($this->warnAboutPairStreak($user, $pairPractice, $reminders)) {
                    $sent++;
                }
            });

        $this->info("Sent {$sent} pair streak warning(s).");

        return self::SUCCESS;
    }

    private function warnAboutPairStreak(User $user, PairPractice $pairPractice, Reminders $reminders): bool
    {
        $checkedKey = "pair-streak-checked:{$user->id}";
        $lastChecked = Cache::get($checkedKey);
        $day = $this->dueWindowDay($user, self::WARNING_TIME, $lastChecked ? Carbon::parse($lastChecked) : null);

        if ($day === null) {
            return false;
        }

        // Checked once per evening, whatever the outcome.
        Cache::put($checkedKey, $day, now()->addDays(2));

        $friends = $user->mutualFriends();

        if ($friends->isEmpty() || $user->wasActiveToday() || ! $reminders->hasRoomToday($user)) {
            return false;
        }

        $best = $friends
            ->map(fn (User $friend) => ['friend' => $friend, 'streak' => $pairPractice->pairStreak($user, $friend)])
            ->sortByDesc('streak')
            ->first();

        if ($best['streak'] === 0) {
            return false;
        }

        $user->notify(new PairStreakWarning($best['friend'], $best['streak']));
        $reminders->countSent($user);

        return true;
    }
}
