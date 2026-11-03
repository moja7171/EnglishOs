<?php

namespace App\Services;

use App\Models\PairGame;
use App\Models\User;
use App\Notifications\PairGameInvite;

/**
 * Starting a game from the Games page: the pair's open game is picked up
 * (or the friend's invite joined), otherwise a new one is drawn and the
 * friend is invited to join on their phone.
 */
class PairGames
{
    public function __construct(private readonly TabooDeck $taboo, private readonly WhoAmIDeck $whoAmI, private readonly JustAMinuteDeck $justAMinute) {}

    public function start(User $starter, User $friend, string $game, int $minutes): ?PairGame
    {
        if (! in_array($game, PairGame::PLAYABLE, true) || ! in_array($minutes, PairGame::LENGTHS, true)) {
            return null;
        }

        $open = PairGame::forPair($starter, $friend)->open()->latest()->first();

        if ($open) {
            $open->joinWithLock($starter);

            return $open;
        }

        $deckMaker = match ($game) {
            PairGame::GAME_WHO => $this->whoAmI,
            PairGame::GAME_MINUTE => $this->justAMinute,
            default => $this->taboo,
        };
        $rules = $deckMaker->rulesFor($starter, $friend, $minutes);
        $deck = $deckMaker->cardsFor($starter, $friend, $rules);

        if ($deck === []) {
            return null;
        }

        [$lowId, $highId] = $starter->id < $friend->id ? [$starter->id, $friend->id] : [$friend->id, $starter->id];

        $pairGame = PairGame::create([
            'user_a_id' => $lowId,
            'user_b_id' => $highId,
            'started_by_id' => $starter->id,
            'game' => $game,
            'minutes' => $minutes,
            'rules' => $rules,
            'deck' => $deck,
            'turns' => [],
            'stage' => PairGame::STAGE_WAITING,
        ]);

        $friend->notify(new PairGameInvite($pairGame, $starter));

        return $pairGame;
    }
}
