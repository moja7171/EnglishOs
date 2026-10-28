<?php

namespace App\Notifications;

use App\Notifications\Concerns\DeliversWebPush;
use Illuminate\Notifications\Notification;

/**
 * Afternoon: this learner studied today, these friends haven't yet — a
 * nudge from a friend helps (see SendFriendNudgeHints).
 */
class FriendNotStudiedYet extends Notification
{
    use DeliversWebPush;

    /**
     * @param  list<string>  $names
     */
    public function __construct(private readonly array $names) {}

    /**
     * @return array{icon: string, title: string, url: string, names: list<string>, topic: string}
     */
    public function toArray(object $notifiable): array
    {
        $who = count($this->names) === 1
            ? "{$this->names[0]} امروز هنوز درس نخونده"
            : implode(' و ', $this->names).' امروز هنوز درس نخوندن';

        return [
            'icon' => 'heroicon-s-hand-raised',
            'title' => "{$who} — یه هل بده 👋",
            'url' => route('friends.index'),
            'names' => $this->names,
            'topic' => 'friends-nudge',
        ];
    }

    protected function webPushTag(): ?string
    {
        return 'friends-nudge';
    }
}
