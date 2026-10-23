<?php

namespace App\Notifications;

use App\Notifications\Concerns\DeliversWebPush;
use Illuminate\Notifications\Notification;

/**
 * When the learner reaches the end of the 96 days — told on day 1, at the
 * end of every mission, at each quarter, in the last 10 days and whenever
 * the date moves 3 days or more (see FinishLineAlerts). Opens Progress,
 * where the same dates sit in the header.
 */
class FinishLineUpdate extends Notification
{
    use DeliversWebPush;

    public function __construct(private readonly string $message) {}

    /**
     * @return array{icon: string, title: string, url: string, topic: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'icon' => 'heroicon-s-flag',
            'title' => $this->message,
            'url' => route('progress.index'),
            'topic' => 'finish-line',
        ];
    }

    protected function countsTowardDailyLimit(): bool
    {
        return true;
    }

    protected function webPushTag(): ?string
    {
        return 'finish-line';
    }
}
