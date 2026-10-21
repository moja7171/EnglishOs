<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Concerns\DeliversWebPush;
use Illuminate\Notifications\Notification;

/**
 * A mutual friend just finished their mission day and this learner hasn't
 * yet — at most once per friend per day (see DayFinishedAlerts).
 */
class FriendFinishedDay extends Notification
{
    use DeliversWebPush;

    public function __construct(private readonly User $friend) {}

    /**
     * @return array{icon: string, title: string, url: string, sender_id: int}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'icon' => 'heroicon-s-fire',
            'title' => "نوبت توئه 👋 {$this->friend->name} درس امروزش رو تموم کرد",
            'url' => route('home'),
            'sender_id' => $this->friend->id,
        ];
    }

    protected function webPushTag(): ?string
    {
        return "friend-finished-{$this->friend->id}";
    }
}
