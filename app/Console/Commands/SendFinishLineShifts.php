<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SendsScheduledPush;
use App\Models\User;
use App\Services\FinishLine;
use App\Services\FinishLineAlerts;
use App\Services\Reminders;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The finish date also moves on days the learner does nothing (their pace
 * drops), when no finished day triggers FinishLineAlerts. Once a day at
 * 13:00 their time this tells them if it has moved 3 days or more since
 * they were last told — within the daily reminder limit, and never to
 * someone away a week or more (the comeback alerts handle them). Scheduled
 * every minute in routes/console.php.
 */
class SendFinishLineShifts extends Command
{
    use SendsScheduledPush;

    public const CHECK_TIME = '13:00';

    protected $signature = 'finish-line:send-shifts';

    protected $description = 'Tell learners whose finish date moved 3 days or more';

    public function handle(FinishLine $finishLine, FinishLineAlerts $alerts, Reminders $reminders): int
    {
        $sent = 0;

        User::query()
            ->whereNotNull('program_started_at')
            ->whereNotNull('finish_line_told_on')
            ->lazyById()
            ->each(function (User $user) use ($finishLine, $alerts, $reminders, &$sent): void {
                if ($this->check($user, $finishLine, $alerts, $reminders)) {
                    $sent++;
                }
            });

        $this->info("Sent {$sent} finish line update(s).");

        return self::SUCCESS;
    }

    private function check(User $user, FinishLine $finishLine, FinishLineAlerts $alerts, Reminders $reminders): bool
    {
        $checkedKey = "finish-line-checked:{$user->id}";
        $lastChecked = Cache::get($checkedKey);
        $day = $this->dueWindowDay($user, self::CHECK_TIME, $lastChecked ? Carbon::parse($lastChecked) : null);

        if ($day === null) {
            return false;
        }

        // Checked once a day, whatever the outcome.
        Cache::put($checkedKey, $day, now()->addDays(2));

        if ($reminders->isAway($user) || ! $reminders->hasRoomToday($user)) {
            return false;
        }

        $forecast = $finishLine->forecast($user);
        $message = $forecast === null ? null : $alerts->shiftMessage($user, $forecast);

        if ($message === null) {
            return false;
        }

        // FinishLineUpdate counts itself towards today's limit.
        $alerts->tell($user, $message, $forecast);

        return true;
    }
}
