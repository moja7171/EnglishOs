<?php

namespace App\Services;

use App\Models\PairGame;
use App\Models\PairPracticeSession;
use App\Models\User;
use App\Notifications\PairGameInvite;

/**
 * Starting a game from the Games page: the pair's open game is picked up
 * (or the friend's invite joined), otherwise a new one is drawn and the
 * friend is invited to join on their phone.
 */
class PairGames
{
    /** A Pair Practice round's game is drawn for this many minutes. */
    public const PRACTICE_MINUTES = 3;

    /**
     * Games too long for a round's 3 minutes at their usual turns: one
     * scene, and half a story.
     *
     * @var array<string, array<string, int>>
     */
    private const PRACTICE_RULES = [
        PairGame::GAME_ASK => ['turns' => 4],
        PairGame::GAME_TABOO => ['turns' => 2],
        PairGame::GAME_ROLE => ['turns' => 1],
        PairGame::GAME_STORY => ['turns' => 4, 'story_turns' => 4],
    ];

    public function __construct(private readonly TabooDeck $taboo, private readonly WhoAmIDeck $whoAmI, private readonly JustAMinuteDeck $justAMinute, private readonly StoryChainDeck $storyChain, private readonly RolePlayDeck $rolePlay, private readonly TwoTruthsDeck $twoTruths, private readonly WouldYouRatherDeck $wouldYouRather, private readonly AskAnswerDeck $askAnswer) {}

    /**
     * @param  list<string>|null  $days  chosen mission days ("M03-2"); null = every day both finished
     */
    public function start(User $starter, User $friend, string $game, int $minutes, ?array $days = null): ?PairGame
    {
        if (! in_array($game, PairGame::PLAYABLE, true) || ! in_array($minutes, PairGame::LENGTHS, true)) {
            return null;
        }

        $open = PairGame::forPair($starter, $friend)->open()->latest()->first();

        if ($open) {
            $open->joinWithLock($starter);

            return $open;
        }

        $deckMaker = $this->deckFor($game);
        $rules = [...$deckMaker->rulesFor($starter, $friend, $minutes), 'days' => $days ?: null];
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

    /**
     * The games in the order a Pair Practice round should try them: the one
     * the two played least in the last day first (so a second round the
     * same day is a different game), then in turn after the last one
     * played.
     *
     * @return list<string>
     */
    public function practiceOrder(User $a, User $b): array
    {
        $played = PairGame::forPair($a, $b)
            ->where('created_at', '>=', now()->subDay())
            ->where(fn ($query) => $query->where('stage', PairGame::STAGE_DONE)->orWhere('stage', PairGame::STAGE_PLAYING))
            ->latest('id')
            ->pluck('game');
        $games = PairGame::PLAYABLE;
        $last = array_search($played->first(), $games, true);
        $last = $last === false ? -1 : $last;

        return collect($games)
            ->sortBy(fn (string $game) => $played->filter(fn (string $name) => $name === $game)->count() * 10
                + (array_search($game, $games, true) - $last - 1 + count($games)) % count($games))
            ->values()
            ->all();
    }

    /**
     * A round reaches its game: both are already there, so it opens ready
     * for its first Start, with no invite. The round's pick comes first;
     * a game with no cards for these days gives way to the next one.
     */
    public function startForPractice(PairPracticeSession $session): ?PairGame
    {
        $starter = $session->startedBy;
        $friend = $session->partnerFor($starter);
        $order = collect([$session->game(), ...$this->practiceOrder($starter, $friend)])->filter()->unique();

        foreach ($order as $game) {
            $deckMaker = $this->deckFor($game);
            $rules = [
                ...$deckMaker->rulesFor($starter, $friend, self::PRACTICE_MINUTES),
                ...(self::PRACTICE_RULES[$game] ?? []),
                'days' => $session->days,
            ];
            $deck = $deckMaker->cardsFor($starter, $friend, $rules);

            if ($deck !== []) {
                return PairGame::create([
                    'pair_practice_session_id' => $session->id,
                    'user_a_id' => $session->user_a_id,
                    'user_b_id' => $session->user_b_id,
                    'started_by_id' => $starter->id,
                    'game' => $game,
                    'minutes' => self::PRACTICE_MINUTES,
                    'rules' => $rules,
                    'deck' => $deck,
                    'turns' => [],
                    'stage' => PairGame::STAGE_READY,
                    'joined_at' => now(),
                ]);
            }
        }

        return null;
    }

    private function deckFor(string $game): AskAnswerDeck|TabooDeck|WhoAmIDeck|JustAMinuteDeck|StoryChainDeck|RolePlayDeck|TwoTruthsDeck|WouldYouRatherDeck
    {
        return match ($game) {
            PairGame::GAME_ASK => $this->askAnswer,
            PairGame::GAME_WHO => $this->whoAmI,
            PairGame::GAME_MINUTE => $this->justAMinute,
            PairGame::GAME_STORY => $this->storyChain,
            PairGame::GAME_ROLE => $this->rolePlay,
            PairGame::GAME_TRUTHS => $this->twoTruths,
            PairGame::GAME_RATHER => $this->wouldYouRather,
            default => $this->taboo,
        };
    }
}
