<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SendsScheduledPush;
use App\Models\User;
use App\Notifications\StudyReminder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Sends each learner a "time to study" alert at each of the (up to two)
 * times they chose, in their own timezone. Scheduled every minute in
 * routes/console.php.
 */
class SendStudyReminders extends Command
{
    use SendsScheduledPush;

    protected $signature = 'study:send-reminders';

    protected $description = 'Send the study-time alerts to learners whose chosen times have come';

    public function handle(): int
    {
        $sent = 0;

        User::query()
            ->where(function ($query): void {
                $query->whereNotNull('study_reminder_time_1')->orWhereNotNull('study_reminder_time_2');
            })
            ->whereHas('pushSubscriptions')
            ->lazyById()
            ->each(function (User $user) use (&$sent): void {
                $sent += $this->remind($user);
            });

        $this->info("Sent {$sent} study reminder(s).");

        return self::SUCCESS;
    }

    /**
     * @return int how many alerts went out for this learner just now
     */
    private function remind(User $user): int
    {
        $sent = 0;
        $firedTimes = [];

        foreach ([1, 2] as $slot) {
            $time = $user->{"study_reminder_time_{$slot}"};

            // Two slots set to the same minute must not buzz the phone twice.
            if ($time === null || in_array($time, $firedTimes, true)) {
                continue;
            }

            $day = $this->dueWindowDay($user, $time, $user->{"last_study_reminder_{$slot}_on"});

            if ($day === null) {
                continue;
            }

            // Marked before sending: a push service that is slow or
            // unreachable must not be retried every minute for the window.
            $user->forceFill(["last_study_reminder_{$slot}_on" => $day])->save();
            $firedTimes[] = $time;

            $this->sendPush($user, new StudyReminder($time, Carbon::parse($day)->dayOfYear + $slot), 'Study reminder');
            $sent++;
        }

        return $sent;
    }
}
