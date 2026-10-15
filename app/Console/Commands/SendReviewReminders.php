<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SendsScheduledPush;
use App\Models\User;
use App\Notifications\ReviewReminder;
use Illuminate\Console\Command;

/**
 * Sends each learner their daily review reminder at the time they chose, in
 * their own timezone, to the minute. Scheduled every minute in
 * routes/console.php.
 */
class SendReviewReminders extends Command
{
    use SendsScheduledPush;

    protected $signature = 'review:send-reminders';

    protected $description = 'Send the daily review reminder to learners whose chosen time has come';

    public function handle(): int
    {
        $sent = 0;

        User::query()
            ->whereNotNull('review_reminder_time')
            ->whereHas('pushSubscriptions')
            ->lazyById()
            ->each(function (User $user) use (&$sent): void {
                if ($this->remind($user)) {
                    $sent++;
                }
            });

        $this->info("Sent {$sent} review reminder(s).");

        return self::SUCCESS;
    }

    private function remind(User $user): bool
    {
        $day = $this->dueWindowDay($user, $user->review_reminder_time, $user->last_review_reminder_on);

        if ($day === null) {
            return false;
        }

        // Already practised today — nothing to nudge about.
        if ($user->reviewedTodayCount() > 0) {
            return false;
        }

        $dueCount = $user->dailyReviewCount();

        if ($dueCount === 0) {
            return false;
        }

        // Marked before sending: a push service that is slow or unreachable
        // must not be retried every minute for the rest of the window.
        $user->forceFill(['last_review_reminder_on' => $day])->save();

        $this->sendPush($user, new ReviewReminder($dueCount), 'Review reminder');

        return true;
    }
}
