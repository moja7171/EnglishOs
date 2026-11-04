<?php

namespace App\Services;

use App\Models\PairGame;
use App\Models\User;

/**
 * Two Truths on the Games page: the teller gets a topic (and a hint with
 * the grammar to use) and says three things about themselves, one of them
 * a lie, marking the lie on their own phone; the listener may ask two
 * questions, then picks the lie. Topics (config/pair_games.php
 * 'truth_topics') open with the mission that teaches their grammar; the
 * lower of the two players' missions decides. Beginners (up to M04) get
 * longer to tell.
 */
class TwoTruthsDeck
{
    public const BEGINNER_TELL_SECONDS = 75;

    public const TELL_SECONDS = 60;

    public function __construct(private readonly TabooDeck $taboo) {}

    /**
     * @return array{turn_seconds: int, turns: int, skips: null, bonus: bool, starters: int, level: int}
     */
    public function rulesFor(User $a, User $b, int $minutes): array
    {
        $level = min($this->taboo->missionNumberOf($a), $this->taboo->missionNumberOf($b));
        $tell = $level <= TabooDeck::BEGINNER_UNTIL_MISSION ? self::BEGINNER_TELL_SECONDS : self::TELL_SECONDS;

        return [
            'turn_seconds' => $tell,
            'turns' => max(2, 2 * (int) round($minutes * 60 / ($tell + PairGame::GUESS_SECONDS) / 2)),
            'skips' => null,
            'bonus' => false,
            'starters' => 0,
            'level' => $level,
        ];
    }

    /**
     * Topics the two have the grammar for, shuffled; the ones they played
     * in the last day go last.
     *
     * @param  array{turns: int, level: int}  $rules
     * @return list<array{word: string, hint: ?string, source: string, bonus: list<string>, key: string}>
     */
    public function cardsFor(User $a, User $b, array $rules): array
    {
        $recent = PairGame::forPair($a, $b)
            ->where('game', PairGame::GAME_TRUTHS)
            ->where('created_at', '>=', now()->subDay())
            ->get()
            ->flatMap(fn (PairGame $game) => collect($game->playedCards())->pluck('word'))
            ->flip();

        return collect(config('pair_games.truth_topics', []))
            ->filter(fn (array $topic) => ($topic[2] ?? 1) <= max(1, $rules['level']))
            ->shuffle()
            ->sortBy(fn (array $topic) => $recent->has($topic[0]) ? 1 : 0)
            ->take($rules['turns'])
            ->map(fn (array $topic) => [
                'word' => $topic[0],
                'hint' => $topic[1] ?? null,
                'source' => 'Two Truths',
                'bonus' => [],
                'key' => 'truths:'.$topic[0],
            ])
            ->values()
            ->all();
    }
}
