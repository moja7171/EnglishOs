<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SendsScheduledPush;
use App\Models\User;
use App\Notifications\ReviewReminder;
use App\Services\Reminders;
use Illuminate\Console\Command;

/**
 * Sends each learner one evening nudge — at a fixed hour in their own
 * timezone — if they still haven't reviewed today and have reviews waiting.
 * Scheduled every minute in routes/console.php.
 */
class SendReviewReminders extends Command
{
    use SendsScheduledPush;

    /** Local "HH:MM" the reminder goes out — after the 20:00 streak warning, late enough that a learner who was going to review today has had the chance. */
    public const REMINDER_TIME = '20:30';

    /** How long after REMINDER_TIME it may still go out; shorter than the default so it never lands near bedtime. */
    private const GRACE_MINUTES_AFTER_REMINDER_TIME = 60;

    protected $signature = 'review:send-reminders';

    protected $description = 'Send the evening review reminder to learners who have not reviewed today';

    public function handle(): int
    {
        $sent = 0;

        User::query()
            ->where('review_reminder_enabled', true)
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
        $day = $this->dueWindowDay($user, self::REMINDER_TIME, $user->last_review_reminder_on, self::GRACE_MINUTES_AFTER_REMINDER_TIME);

        if ($day === null) {
            return false;
        }

        // Only when no other reminder went out today: the study reminder
        // already lists the words due, the streak warning is the more
        // important evening alert, and a week away stops daily alerts.
        $reminders = app(Reminders::class);

        if ($reminders->sentToday($user) > 0 || $reminders->isAway($user)) {
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
        $reminders->countSent($user);

        return true;
    }
}
