<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;

class FollowRequestReceived extends Notification
{
    public function __construct(private readonly User $follower) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * `follower_id` lets the bell offer Accept/Decline right on the row.
     *
     * @return array{icon: string, title: string, url: string, follower_id: int}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'icon' => 'heroicon-o-user-plus',
            'title' => "{$this->follower->name} wants to connect",
            'url' => route('friends.index'),
            'follower_id' => $this->follower->id,
        ];
    }
}
