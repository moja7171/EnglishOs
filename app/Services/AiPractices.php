<?php

namespace App\Services;

use App\Models\AiPractice;
use App\Models\PairGame;
use App\Models\User;

/**
 * Starting a practice with the AI: the learner's open one is picked up,
 * otherwise a new one is drawn from the days they finished.
 */
class AiPractices
{
    /** Would You Rather questions: inside a round, and as a game of its own. */
    private const RATHER_QUESTIONS_IN_ROUND = 2;

    private const RATHER_QUESTIONS = 4;

    public function __construct(private readonly PairPractice $pairPractice, private readonly WhoAmIDeck $whoAmI, private readonly WouldYouRatherDeck $wouldYouRather) {}

    /**
     * @param  string  $kind  AiPractice::KIND_PRACTICE or one of AiPractice::GAMES
     * @param  list<string>|null  $days  chosen mission days ("M03-2"); null = the latest
     */
    public function start(User $learner, string $kind, ?array $days = null): ?AiPractice
    {
        $open = AiPractice::where('learner_id', $learner->id)->where('kind', $kind)->open()->latest('id')->first();

        if ($open) {
            return $open;
        }

        if ($kind !== AiPractice::KIND_PRACTICE) {
            $game = in_array($kind, AiPractice::GAMES, true) ? $this->gameDeck($learner, $kind, $days, inRound: false) : null;

            return ($game['cards'] ?? []) === [] ? null : $this->create($learner, $kind, $days, ['game' => $game], AiPractice::STAGE_GAME);
        }

        $deck = $this->pairPractice->soloDeck($learner, $days);

        if ($deck === null) {
            return null;
        }

        $practice = new AiPractice(['deck' => [
            ...$deck['cards'],
            'game' => $this->gameDeck($learner, $this->nextGame($learner), $deck['days'], inRound: true),
        ]]);

        return $practice->stages() === [] ? null : $this->create($learner, $kind, $deck['days'], $practice->deck, $practice->stages()[0]);
    }

    /**
     * @param  array<string, mixed>  $deck
     * @param  list<string>|null  $days
     */
    private function create(User $learner, string $kind, ?array $days, array $deck, string $stage): AiPractice
    {
        return AiPractice::create(['learner_id' => $learner->id, 'kind' => $kind, 'days' => $days, 'deck' => $deck, 'stage' => $stage]);
    }

    /**
     * The game a round ends with: not the one the last round ended with.
     */
    private function nextGame(User $learner): string
    {
        $last = AiPractice::where('learner_id', $learner->id)->where('kind', AiPractice::KIND_PRACTICE)->latest('id')->first()?->game();

        return collect(AiPractice::GAMES)->first(fn (string $game) => $game !== $last);
    }

    /**
     * @param  list<string>|null  $days
     * @return array{game: string, cards: list<array<string, mixed>>}
     */
    private function gameDeck(User $learner, string $game, ?array $days, bool $inRound): array
    {
        $deckMaker = $game === PairGame::GAME_WHO ? $this->whoAmI : $this->wouldYouRather;
        $rules = [...$deckMaker->rulesFor($learner, $learner, PairGames::PRACTICE_MINUTES), 'days' => $days];

        if ($game === PairGame::GAME_RATHER) {
            $rules['turns'] = $inRound ? self::RATHER_QUESTIONS_IN_ROUND : self::RATHER_QUESTIONS;
        }

        return ['game' => $game, 'cards' => $deckMaker->cardsFor($learner, $learner, $rules)];
    }
}
