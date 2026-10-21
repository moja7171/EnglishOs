<?php

namespace App\Notifications;

use App\Models\PairPracticeSession;
use App\Models\User;
use App\Notifications\Concerns\DeliversWebPush;
use Illuminate\Notifications\Notification;

/**
 * A friend started a Pair Practice round — the bell row and the push open
 * the round, where the invited friend taps Join.
 */
class PairPracticeInvite extends Notification
{
    use DeliversWebPush;

    public function __construct(private readonly PairPracticeSession $session, private readonly User $inviter) {}

    /**
     * @return array{icon: string, title: string, url: string, sender_id: int}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'icon' => 'heroicon-o-users',
            'title' => "دعوت به تمرین: {$this->inviter->name} الان منتظرته",
            'url' => route('pair-practice.show', $this->session),
            'sender_id' => $this->inviter->id,
        ];
    }

    protected function webPushTag(): ?string
    {
        return "pair-invite-{$this->session->id}";
    }

    /**
     * A live invite: the friend is waiting right now.
     */
    protected function silentInQuietHours(): bool
    {
        return false;
    }
}
