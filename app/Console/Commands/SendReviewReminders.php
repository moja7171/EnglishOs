<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\ReviewReminder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\WebPushChannel;
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
        $localNow = now($user->reminderTimezone());

        // The latest occurrence of the chosen time: today's, or yesterday's
        // while today's hasn't come yet — so a window that opened just before
        // midnight is still honoured after it.
        $chosenTime = $localNow->copy()->setTimeFromTimeString($user->review_reminder_time);

        if ($chosenTime->greaterThan($localNow)) {
            $chosenTime->subDay();
        }

        $day = $chosenTime->toDateString();

        if ($user->last_review_reminder_on?->toDateString() === $day) {
            return false;
        }

        if ($chosenTime->diffInMinutes($localNow) > self::GRACE_MINUTES) {
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
        $user->forceFill(['last_review_reminder_on' => $day])->save();

        $this->send($user, $dueCount);

        return true;
    }

    /**
     * Goes straight through the push channel (not notify()) to see whether
     * the push service accepted it — a refusal is logged at error level, the
     * only level production keeps, so an unreachable service shows up in the
     * log instead of reminders silently never arriving.
     */
    private function send(User $user, int $dueCount): void
    {
        try {
            $reports = app(WebPushChannel::class)->send($user, new ReviewReminder($dueCount));
        } catch (Throwable $e) {
            report($e);

            return;
        }

        foreach ($reports as $report) {
            if (! $report->isSuccess()) {
                Log::error('Review reminder push was not accepted', [
                    'user_id' => $user->id,
                    'expired' => $report->isSubscriptionExpired(),
                    'reason' => Str::limit($report->getReason(), 200),
                ]);
            }
        }
    }
}
