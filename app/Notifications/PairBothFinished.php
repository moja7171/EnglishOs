<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Concerns\DeliversWebPush;
use Illuminate\Notifications\Notification;

/**
 * Both friends finished today's mission day — the moment a Pair Practice
 * round has today's content. Sent to both, once per pair per day.
 */
class PairBothFinished extends Notification
{
    use DeliversWebPush;

    public function __construct(private readonly User $friend) {}

    /**
     * @return array{icon: string, title: string, url: string, sender_id: int}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'icon' => 'heroicon-o-users',
            'title' => "امروز تو و {$this->friend->name} هر دو تموم کردید؛ ۱۲ دقیقه با هم تمرین کنید؟",
            'url' => route('pair-practice.index'),
            'sender_id' => $this->friend->id,
        ];
    }

    protected function webPushTag(): ?string
    {
        return "pair-both-finished-{$this->friend->id}";
    }
}
