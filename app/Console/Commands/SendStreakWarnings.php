<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SendsScheduledPush;
use App\Models\User;
use App\Notifications\StreakWarning;
use App\Services\Reminders;
use App\Services\StreakRing;
use Illuminate\Console\Command;

/**
 * Tells learners at 20:00 their own time how many activities still keep
 * their streak today — only when today's ring is short of it. Scheduled
 * every minute in routes/console.php.
 */
class SendStreakWarnings extends Command
{
    use SendsScheduledPush;

    /** Local "HH:MM" the warning goes out — late enough that the day is nearly spent. */
    public const WARNING_TIME = '20:00';

    protected $signature = 'streak:send-warnings';

    protected $description = 'Warn learners whose streak will break unless they are active today';

    public function handle(): int
    {
        $sent = 0;

        User::query()
            ->whereHas('pushSubscriptions')
            ->lazyById()
            ->each(function (User $user) use (&$sent): void {
                if ($this->warnAboutStreak($user)) {
                    $sent++;
                }
            });

        $this->info("Sent {$sent} streak warning(s).");

        return self::SUCCESS;
    }

    private function warnAboutStreak(User $user): bool
    {
        $day = $this->dueWindowDay($user, self::WARNING_TIME, $user->last_streak_warning_on);

        if ($day === null) {
            return false;
        }

        $streak = $user->streakAtRiskLength();

        if ($streak === 0) {
            return false;
        }

        // Marked before sending: a push service that is slow or unreachable
        // must not be retried every minute for the rest of the window.
        $user->forceFill(['last_streak_warning_on' => $day])->save();

        $reminders = app(Reminders::class);

        if (! $reminders->hasRoomToday($user)) {
            return false;
        }

        $left = StreakRing::SLICES_TO_KEEP - $user->streakRing()['todaySlices'];

        $this->sendPush($user, new StreakWarning($streak, $reminders->quickestNext($user), $left), 'Streak warning');
        $reminders->countSent($user);

        return true;
    }
}
