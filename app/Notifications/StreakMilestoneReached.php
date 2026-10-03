<?php

namespace App\Notifications;

use App\Notifications\Concerns\DeliversWebPush;
use Illuminate\Notifications\Notification;

class StreakMilestoneReached extends Notification
{
    use DeliversWebPush;

    public function __construct(private readonly int $milestone) {}

    /**
     * @return array{icon: string, title: string, url: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'icon' => 'heroicon-s-fire',
            'title' => "You reached a {$this->milestone}-day streak!",
            'url' => route('progress.index'),
        ];
    }
}
