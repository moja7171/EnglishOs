<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Concerns\DeliversWebPush;
use Illuminate\Notifications\Notification;

/**
 * A mutual friend just started today's lesson and this learner hasn't
 * finished theirs — at most once per friend per day (see FriendAlerts).
 */
class FriendStudyingNow extends Notification
{
    use DeliversWebPush;

    public function __construct(private readonly User $friend, private readonly string $where) {}

    /**
     * @return array{icon: string, title: string, url: string, sender_id: int, topic: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'icon' => 'heroicon-s-book-open',
            'title' => "{$this->friend->name} الان داره درس می‌خونه 📖 {$this->where}",
            'url' => route('home'),
            'sender_id' => $this->friend->id,
            'topic' => 'friend-studying',
        ];
    }

    protected function countsTowardDailyLimit(): bool
    {
        return true;
    }

    protected function webPushTag(): ?string
    {
        return "friend-studying-{$this->friend->id}";
    }
}
