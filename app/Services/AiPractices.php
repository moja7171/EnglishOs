<?php

namespace App\Services;

use App\Models\AiPractice;
use App\Models\PairGame;
use App\Models\User;

/**
 * Starting a practice with Teddy: the learner's open one is picked up,
 * otherwise a new one is drawn from the days they finished.
 */
class AiPractices
{
    /** Turns in a game that sets its own length: inside a round, and as a game of its own. */
    private const TURNS_IN_ROUND = 2;

    private const TURNS_ALONE = 4;

    /** Cards of a game that carry one of the learner's own words from My Words. */
    private const OWN_WORD_GAME_CARDS = 2;

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
        private readonly TeddyLevel $teddyLevel,
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
        $level = $this->teddyLevel->levelFor($learner, $kind);
        $deck = [...$deck, 'teddy_level' => $level, 'slips' => $this->teddyLevel->planSlips($deck, $level)];

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
            return ['game' => $game, 'cards' => $this->withOwnWordsInGame($learner, $game, array_slice($deckMaker->cardsFor($learner, $learner, $rules), 0, $inRound ? self::STORY_TURNS_IN_ROUND : StoryChainDeck::STORY_TURNS))];
        }

        $rules['turns'] = $inRound ? self::TURNS_IN_ROUND : self::TURNS_ALONE;
        $rules['bonus'] = false;

        return ['game' => $game, 'cards' => $this->withOwnWordsInGame($learner, $game, array_slice($deckMaker->cardsFor($learner, $learner, $rules), 0, $rules['turns']))];
    }

    /**
     * The learner's own words from My Words in the games where a word is
     * said or used, on the cards that are theirs (the even ones): the word
     * to explain in Taboo, the word to use in Story Chain, and one of the
     * words to work into the talk in Just a Minute.
     *
     * @param  list<array<string, mixed>>  $cards
     * @return list<array<string, mixed>>
     */
    public function withOwnWordsInGame(User $learner, string $game, array $cards): array
    {
        if (! in_array($game, [PairGame::GAME_STORY, PairGame::GAME_TABOO, PairGame::GAME_MINUTE], true) || $cards === []) {
            return $cards;
        }

        $used = collect($cards)->flatMap(fn (array $card) => $game === PairGame::GAME_MINUTE ? $card['words'] : [$card['word']])->map(fn (string $word) => mb_strtolower($word))->all();
        $own = $learner->vocabularyWords()
            ->whereNull('source_mission_run_id')
            ->orderByRaw('last_reviewed_at is null desc')
            ->orderBy('next_review_at')
            ->orderByDesc('id')
            ->limit(12)
            ->pluck('word')
            ->map(fn ($word) => trim((string) $word))
            ->filter(fn (string $word) => $word !== '' && str_word_count($word) <= 2 && ! in_array(mb_strtolower($word), $used, true))
            ->values();
        $given = 0;

        foreach ($cards as $position => $card) {
            if ($position % 2 !== 0 || $given >= self::OWN_WORD_GAME_CARDS || ! $own->has($given)) {
                continue;
            }

            $word = $own[$given++];

            $cards[$position] = $game === PairGame::GAME_MINUTE
                ? [...$card, 'words' => [...array_slice($card['words'], 0, -1), $word]]
                : [...$card, 'word' => $word, 'source' => 'My Words', 'bonus' => [], 'key' => 'w:'.mb_strtolower($word)];
        }

        return $cards;
    }
}
