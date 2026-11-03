<?php

namespace App\Services;

use App\Models\PairGame;
use App\Models\User;

/**
 * Who am I? on the Games page: one player holds a secret (a job, an
 * animal, a food, a place…) and answers yes / no / sometimes; the other
 * asks questions to guess it. The secrets (config/who_am_i.php) open with
 * the mission whose words they need; the lower of the two players'
 * missions decides. Beginners (up to M04) see the secret's category.
 */
class WhoAmIDeck
{
    public const TURN_SECONDS = 90;

    public function __construct(private readonly TabooDeck $taboo) {}

    /**
     * @return array{turn_seconds: int, turns: int, skips: null, bonus: bool, starters: int, level: int, show_category: bool}
     */
    public function rulesFor(User $a, User $b, int $minutes): array
    {
        $level = min($this->taboo->missionNumberOf($a), $this->taboo->missionNumberOf($b));

        return [
            'turn_seconds' => self::TURN_SECONDS,
            'turns' => max(2, 2 * (int) round($minutes * 60 / self::TURN_SECONDS / 2)),
            'skips' => null,
            'bonus' => false,
            'starters' => 0,
            'level' => $level,
            'show_category' => $level <= TabooDeck::BEGINNER_UNTIL_MISSION,
        ];
    }

    /**
     * Secrets the two can talk about, shuffled; the ones they played in
     * the last day go last.
     *
     * @param  array{turns: int, level: int}  $rules
     * @return list<array{word: string, category: string, source: string, bonus: list<string>, key: string}>
     */
    public function cardsFor(User $a, User $b, array $rules): array
    {
        $recent = PairGame::forPair($a, $b)
            ->where('game', PairGame::GAME_WHO)
            ->where('created_at', '>=', now()->subDay())
            ->get()
            ->flatMap(fn (PairGame $game) => collect($game->playedCards())->pluck('word'))
            ->flip();
        $categories = config('who_am_i.categories', []);

        return collect(config('who_am_i.secrets', []))
            ->filter(fn (array $secret) => $secret[2] <= $rules['level'])
            ->shuffle()
            ->sortBy(fn (array $secret) => $recent->has($secret[0]) ? 1 : 0)
            ->take($rules['turns'] * 2)
            ->map(fn (array $secret) => [
                'word' => $secret[0],
                'category' => $categories[$secret[1]] ?? $secret[1],
                'source' => ucfirst($categories[$secret[1]] ?? $secret[1]),
                'bonus' => [],
                'key' => 'who:'.$secret[0],
            ])
            ->values()
            ->all();
    }
}
