<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Concerns\DeliversWebPush;
use App\Notifications\Concerns\WritesPersianNumbers;
use Illuminate\Notifications\Notification;

/**
 * The pair streak with a friend breaks tonight unless this learner finishes
 * today's mission day — sent in the evening (see SendPairStreakWarnings).
 */
class PairStreakWarning extends Notification
{
    use DeliversWebPush;
    use WritesPersianNumbers;

    public function __construct(private readonly User $friend, private readonly int $streakDays) {}

    /**
     * @return array{icon: string, title: string, url: string, sender_id: int}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'icon' => 'heroicon-s-fire',
            'title' => "زنجیرهٔ {$this->persianNumber($this->streakDays)} روزهٔ تو و {$this->friend->name} امشب می‌شکنه؛ درس امروزت رو تموم کن.",
            'url' => route('home'),
            'sender_id' => $this->friend->id,
        ];
    }

    protected function webPushTag(): ?string
    {
        return "pair-streak-{$this->friend->id}";
    }
}
