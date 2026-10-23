<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Concerns\DeliversWebPush;
use Illuminate\Notifications\Notification;

/**
 * A friend's one-tap "finish your day" from the Pair Practice page — their
 * shared practice only gets today's content once both have finished it.
 */
class PairPracticeNudge extends Notification
{
    use DeliversWebPush;

    public function __construct(private readonly User $sender) {}

    /**
     * @return array{icon: string, title: string, url: string, sender_id: int, topic: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'icon' => 'heroicon-s-fire',
            'title' => "تلنگر از {$this->sender->name}: درس امروزت رو تموم کن تا با هم تمرین کنید",
            'url' => route('pair-practice.index'),
            'sender_id' => $this->sender->id,
            'topic' => "pair-nudge-{$this->sender->id}",
        ];
    }

    protected function webPushTag(): ?string
    {
        return "pair-nudge-{$this->sender->id}";
    }
}
