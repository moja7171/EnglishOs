<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;

class FollowRequestAccepted extends Notification
{
    public function __construct(private readonly User $accepter) {}

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
            'icon' => 'heroicon-o-check-circle',
            'title' => "{$this->accepter->name} accepted your friend request",
            'url' => route('friends.conversation', $this->accepter),
        ];
    }
}
