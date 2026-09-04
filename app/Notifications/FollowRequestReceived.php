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
     * @return array{icon: string, title: string, url: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'icon' => 'heroicon-o-user-plus',
            'title' => "{$this->follower->name} wants to connect",
            'url' => route('friends.index'),
        ];
    }
}
