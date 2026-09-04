<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class StreakMilestoneReached extends Notification
{
    public function __construct(private readonly int $milestone) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

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
