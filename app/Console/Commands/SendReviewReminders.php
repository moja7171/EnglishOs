<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\ReviewReminder;
use Illuminate\Console\Command;
use Throwable;

/**
 * Sends each learner their daily review reminder at the time they chose, in
 * their own timezone, to the minute. Scheduled every minute in
 * routes/console.php.
 */
class SendReviewReminders extends Command
{
    protected $signature = 'review:send-reminders';

    protected $description = 'Send the daily review reminder to learners whose chosen time has come';

    /**
     * How long after the chosen time a reminder may still go out — covers a
     * late or missed cron run without sending a 7 pm reminder at midnight.
     */
    private const GRACE_MINUTES = 120;

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
        $timezone = $user->reminderTimezone();
        $localNow = now($timezone);
        $today = $localNow->toDateString();

        if ($user->last_review_reminder_on?->toDateString() === $today) {
            return false;
        }

        $chosenTime = $localNow->copy()->setTimeFromTimeString($user->review_reminder_time);
        $minutesLate = ($localNow->getTimestamp() - $chosenTime->getTimestamp()) / 60;

        if ($minutesLate < 0 || $minutesLate > self::GRACE_MINUTES) {
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
        // must not be retried every 15 minutes for the rest of the window.
        $user->forceFill(['last_review_reminder_on' => $today])->save();

        try {
            $user->notify(new ReviewReminder($dueCount));
        } catch (Throwable $e) {
            report($e);
        }

        return true;
    }
}
