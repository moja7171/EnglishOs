<?php

namespace App\Notifications;

use App\Notifications\Concerns\DeliversWebPush;
use App\Notifications\Concerns\WritesPersianNumbers;
use Illuminate\Notifications\Notification;

class StreakMilestoneReached extends Notification
{
    use DeliversWebPush;
    use WritesPersianNumbers;

    public function __construct(private readonly int $milestone) {}

    /**
     * @return array{icon: string, title: string, url: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'icon' => 'heroicon-s-fire',
            'title' => "زنجیرهٔ {$this->persianNumber($this->milestone)} روزه! 🔥",
            'url' => route('progress.index'),
        ];
    }

    protected function countsTowardDailyLimit(): bool
    {
        return true;
    }
}
