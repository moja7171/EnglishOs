<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Concerns\DeliversWebPush;
use Illuminate\Notifications\Notification;

class FollowRequestAccepted extends Notification
{
    use DeliversWebPush;

    public function __construct(private readonly User $accepter) {}

    /**
     * @return array{icon: string, title: string, url: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'icon' => 'heroicon-o-check-circle',
            'title' => "{$this->accepter->name} accepted your friend request",
            'url' => route('friends.conversation', $this->accepter),
        ];
    }
}
