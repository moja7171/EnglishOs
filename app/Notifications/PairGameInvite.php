<?php

namespace App\Notifications;

use App\Models\PairGame;
use App\Models\User;
use App\Notifications\Concerns\DeliversWebPush;
use Illuminate\Notifications\Notification;

/**
 * A friend invited you to a game from the Games page — the bell row, the
 * banner's Join and the push open the game and join it in that one tap.
 */
class PairGameInvite extends Notification
{
    use DeliversWebPush;

    public function __construct(private readonly PairGame $pairGame, private readonly User $inviter) {}

    /**
     * @return array{icon: string, title: string, url: string, sender_id: int, game_id: int}
     */
    public function toArray(object $notifiable): array
    {
        $name = PairGame::info($this->pairGame->game)['name'];

        return [
            'icon' => 'heroicon-o-puzzle-piece',
            'title' => "بازی {$name}: {$this->inviter->name} الان منتظرته",
            'url' => route('games.play', ['pairGame' => $this->pairGame, 'join' => 1]),
            'sender_id' => $this->inviter->id,
            'game_id' => $this->pairGame->id,
        ];
    }

    protected function webPushTag(): ?string
    {
        return "game-invite-{$this->pairGame->id}";
    }

    /**
     * A live invite: the friend is waiting right now.
     */
    protected function silentInQuietHours(): bool
    {
        return false;
    }
}
