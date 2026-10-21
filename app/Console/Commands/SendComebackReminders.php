<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SendsScheduledPush;
use App\Models\User;
use App\Notifications\ComebackReminder;
use App\Services\Reminders;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Brings back a learner who stopped: one alert at 19:00 their time after 2
 * days away and one after 5 (Reminders::COMEBACK_DAYS) — instead of the
 * same daily alert, which stops after a week away. On day 2 a learner with
 * a streak at risk gets the streak warning instead. Scheduled every minute
 * in routes/console.php.
 */
class SendComebackReminders extends Command
{
    use SendsScheduledPush;

    public const REMINDER_TIME = '19:00';

    protected $signature = 'reminders:send-comeback';

    protected $description = 'Send one comeback alert to learners who have been away 2 or 5 days';

    public function handle(Reminders $reminders): int
    {
        $sent = 0;

        User::query()
            ->whereHas('pushSubscriptions')
            ->lazyById()
            ->each(function (User $user) use ($reminders, &$sent): void {
                if ($this->remind($user, $reminders)) {
                    $sent++;
                }
            });

        $this->info("Sent {$sent} comeback reminder(s).");

        return self::SUCCESS;
    }

    private function remind(User $user, Reminders $reminders): bool
    {
        $checkedKey = "comeback-checked:{$user->id}";
        $lastChecked = Cache::get($checkedKey);
        $day = $this->dueWindowDay($user, self::REMINDER_TIME, $lastChecked ? Carbon::parse($lastChecked) : null);

        if ($day === null) {
            return false;
        }

        // Checked once per evening, whatever the outcome.
        Cache::put($checkedKey, $day, now()->addDays(2));

        $daysAway = $reminders->daysAway($user);

        if (! in_array($daysAway, Reminders::COMEBACK_DAYS, true)) {
            return false;
        }

        if ($user->streakAtRiskLength() > 0 || ! $reminders->hasRoomToday($user)) {
            return false;
        }

        $this->sendPush($user, new ComebackReminder($daysAway, $reminders->whatsWaiting($user)), 'Comeback reminder');
        $reminders->countSent($user);

        return true;
    }
}
