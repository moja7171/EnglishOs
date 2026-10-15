<?php

namespace App\Console\Commands\Concerns;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\WebPushChannel;
use Throwable;

/**
 * What every scheduled phone alert shares: reading a learner's chosen
 * "HH:MM" in their own timezone and sending through the push channel so a
 * refusal ends up in the log.
 */
trait SendsScheduledPush
{
    /**
     * How long after the chosen time an alert may still go out — covers a
     * late or missed cron run without sending a 7 pm alert at midnight.
     */
    protected const GRACE_MINUTES = 120;

    /**
     * The learner's local date of the latest occurrence of $time when an
     * alert is due now: today's occurrence, or yesterday's while today's
     * hasn't come yet — so a window that opened just before midnight is
     * still honoured after it. Null when it was already sent for that date
     * or the window has closed ($graceMinutes after $time).
     */
    protected function dueWindowDay(User $user, string $time, ?CarbonInterface $lastSentOn, int $graceMinutes = self::GRACE_MINUTES): ?string
    {
        $localNow = now($user->reminderTimezone());
        $chosenTime = $localNow->copy()->setTimeFromTimeString($time);

        if ($chosenTime->greaterThan($localNow)) {
            $chosenTime->subDay();
        }

        $day = $chosenTime->toDateString();

        if ($lastSentOn?->toDateString() === $day) {
            return null;
        }

        if ($chosenTime->diffInMinutes($localNow) > $graceMinutes) {
            return null;
        }

        return $day;
    }

    /**
     * Goes straight through the push channel (not notify()) to see whether
     * the push service accepted it — a refusal is logged at error level, the
     * only level production keeps, so an unreachable service shows up in the
     * log instead of alerts silently never arriving. $label starts the log
     * line ("Review reminder push was not accepted").
     */
    protected function sendPush(User $user, Notification $notification, string $label): void
    {
        try {
            $reports = app(WebPushChannel::class)->send($user, $notification);
        } catch (Throwable $e) {
            report($e);

            return;
        }

        foreach ($reports as $report) {
            if (! $report->isSuccess()) {
                Log::error("{$label} push was not accepted", [
                    'user_id' => $user->id,
                    'expired' => $report->isSubscriptionExpired(),
                    'reason' => Str::limit($report->getReason(), 200),
                ]);
            }
        }
    }
}
