<?php

namespace App\Services;

use App\Models\PairGame;
use App\Models\User;

/**
 * Would You Rather on the Games page: both players see one question with
 * two sides (config/would_you_rather.php, four per mission), secretly pick
 * a side and guess the friend's; after the reveal both say why. Questions
 * come from the missions both have reached; one played in the last day
 * goes last.
 */
class WouldYouRatherDeck
{
    /** Time for both to say why, after the reveal. */
    public const WHY_SECONDS = 60;

    public function __construct(private readonly PairPractice $pairPractice, private readonly TabooDeck $taboo) {}

    /**
     * @return array{turn_seconds: int, turns: int, skips: null, bonus: bool, starters: int, level: int}
     */
    public function rulesFor(User $a, User $b, int $minutes): array
    {
        return [
            'turn_seconds' => self::WHY_SECONDS,
            'turns' => max(2, 2 * (int) round($minutes * 60 / (self::WHY_SECONDS + PairGame::PICK_SECONDS) / 2)),
            'skips' => null,
            'bonus' => false,
            'starters' => 0,
            'level' => min($this->taboo->missionNumberOf($a), $this->taboo->missionNumberOf($b)),
        ];
    }

    /**
     * @param  array{turns: int, level: int}  $rules
     * @return list<array{word: string, a: string, b: string, source: string, bonus: list<string>, key: string}>
     */
    public function cardsFor(User $a, User $b, array $rules): array
    {
        $codes = $this->pairPractice->sharedDays($a, $b)->map(fn (array $day) => $day['mission']->code)->unique()->values();

        if ($codes->isEmpty()) {
            $codes = collect(array_keys(config('would_you_rather', [])))->filter(fn (string $code) => (int) substr($code, 1) <= max(1, $rules['level']))->values();
        }

        $recent = PairGame::forPair($a, $b)->where('game', PairGame::GAME_RATHER)->where('created_at', '>=', now()->subDay())->get()
            ->flatMap(fn (PairGame $game) => collect($game->playedCards())->pluck('word'))
            ->flip();

        return $codes->flatMap(fn (string $code) => collect(config('would_you_rather.'.$code, []))->map(fn (array $sides) => [
            'word' => 'Would you rather '.$sides[0].' or '.$sides[1].'?',
            'a' => $sides[0],
            'b' => $sides[1],
            'source' => $code.' · Would you rather',
            'bonus' => [],
            'key' => 'rather:'.$code.':'.$sides[0],
        ]))
            ->shuffle()
            ->sortBy(fn (array $card) => $recent->has($card['word']) ? 1 : 0)
            ->take($rules['turns'])
            ->values()
            ->all();
    }
}
