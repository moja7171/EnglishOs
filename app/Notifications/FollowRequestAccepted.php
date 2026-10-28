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
            'title' => "حالا با {$this->accepter->name} دوستید 🎉",
            'url' => route('friends.conversation', $this->accepter),
            'sender_id' => $this->accepter->id,
        ];
    }
}
