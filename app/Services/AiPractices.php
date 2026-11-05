<?php

namespace App\Services;

use App\Models\AiPractice;
use App\Models\PairGame;
use App\Models\User;

/**
 * Starting a practice with Tara: the learner's open one is picked up,
 * otherwise a new one is drawn from the days they finished.
 */
class AiPractices
{
    /** Turns in a game that sets its own length: inside a round, and as a game of its own. */
    private const TURNS_IN_ROUND = 2;

    private const TURNS_ALONE = 4;

    /** Story Chain counts both tellers' turns: inside a round, a shorter story. */
    private const STORY_TURNS_IN_ROUND = 4;

    public function __construct(
        private readonly PairPractice $pairPractice,
        private readonly WhoAmIDeck $whoAmI,
        private readonly WouldYouRatherDeck $wouldYouRather,
        private readonly JustAMinuteDeck $justAMinute,
        private readonly StoryChainDeck $storyChain,
        private readonly TwoTruthsDeck $twoTruths,
        private readonly TabooDeck $taboo,
    ) {}

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

        $practice = new AiPractice(['deck' => [...$deck['cards'], 'game' => $this->roundGame($learner, $deck['days'])]]);

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
     * The game a round ends with: the one after the last round's, skipping
     * any the chosen days have no cards for.
     *
     * @param  list<string>|null  $days
     * @return array{game: string, cards: list<array<string, mixed>>}
     */
    private function roundGame(User $learner, ?array $days): array
    {
        $last = AiPractice::where('learner_id', $learner->id)->where('kind', AiPractice::KIND_PRACTICE)->latest('id')->first()?->game();
        $after = array_search($last, AiPractice::GAMES, true);
        $games = collect(AiPractice::GAMES);
        $inTurn = $after === false ? $games : $games->slice($after + 1)->concat($games->take($after + 1));

        foreach ($inTurn as $game) {
            $deck = $this->gameDeck($learner, $game, $days, inRound: true);

            if ($deck['cards'] !== []) {
                return $deck;
            }
        }

        return ['game' => AiPractice::GAMES[0], 'cards' => []];
    }

    /**
     * @param  list<string>|null  $days
     * @return array{game: string, cards: list<array<string, mixed>>}
     */
    private function gameDeck(User $learner, string $game, ?array $days, bool $inRound): array
    {
        $deckMaker = match ($game) {
            PairGame::GAME_WHO => $this->whoAmI,
            PairGame::GAME_MINUTE => $this->justAMinute,
            PairGame::GAME_STORY => $this->storyChain,
            PairGame::GAME_TRUTHS => $this->twoTruths,
            PairGame::GAME_TABOO => $this->taboo,
            default => $this->wouldYouRather,
        };
        $rules = [...$deckMaker->rulesFor($learner, $learner, PairGames::PRACTICE_MINUTES), 'days' => $days];

        if ($game === PairGame::GAME_WHO) {
            return ['game' => $game, 'cards' => $deckMaker->cardsFor($learner, $learner, $rules)];
        }

        if ($game === PairGame::GAME_STORY) {
            return ['game' => $game, 'cards' => array_slice($deckMaker->cardsFor($learner, $learner, $rules), 0, $inRound ? self::STORY_TURNS_IN_ROUND : StoryChainDeck::STORY_TURNS)];
        }

        $rules['turns'] = $inRound ? self::TURNS_IN_ROUND : self::TURNS_ALONE;
        $rules['bonus'] = false;

        return ['game' => $game, 'cards' => array_slice($deckMaker->cardsFor($learner, $learner, $rules), 0, $rules['turns'])];
    }
}
