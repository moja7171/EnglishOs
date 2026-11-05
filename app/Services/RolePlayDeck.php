<?php

namespace App\Services;

use App\Models\PairGame;
use App\Models\User;

/**
 * Role-play on the Games page: in each scene both players have a role and
 * a secret goal (config/role_play.php, four scenes per mission). They talk
 * for two minutes, then each picks the friend's goal out of three and says
 * whether they reached their own. Scenes come from the missions both have
 * reached; a scene played in the last day goes last.
 */
class RolePlayDeck
{
    public const TALK_SECONDS = 120;

    public function __construct(private readonly PairPractice $pairPractice, private readonly TabooDeck $taboo) {}

    /**
     * @return array{turn_seconds: int, turns: int, skips: null, bonus: bool, starters: int, level: int}
     */
    public function rulesFor(User $a, User $b, int $minutes): array
    {
        return [
            'turn_seconds' => self::TALK_SECONDS,
            'turns' => max(2, 2 * (int) round($minutes * 60 / (self::TALK_SECONDS + PairGame::GUESS_SECONDS) / 2)),
            'skips' => null,
            'bonus' => false,
            'starters' => 0,
            'level' => min($this->taboo->missionNumberOf($a), $this->taboo->missionNumberOf($b)),
        ];
    }

    /**
     * @param  array{turns: int, level: int}  $rules
     * @return list<array<string, mixed>>
     */
    public function cardsFor(User $a, User $b, array $rules): array
    {
        $shared = $this->pairPractice->sharedDays($a, $b);
        $picked = $shared->only($rules['days'] ?? []);
        $codes = ($picked->isNotEmpty() ? $picked : $shared)->map(fn (array $day) => $day['mission']->code)->unique()->values();

        if ($codes->isEmpty()) {
            $codes = collect(array_keys(config('role_play', [])))->filter(fn (string $code) => (int) substr($code, 1) <= max(1, $rules['level']))->values();
        }

        $played = PairGame::forPair($a, $b)->where('game', PairGame::GAME_ROLE)->where('created_at', '>=', now()->subDay())->get()
            ->flatMap(fn (PairGame $game) => collect($game->playedCards())->pluck('word'))
            ->flip();

        return $codes->flatMap(fn (string $code) => collect(config('role_play.'.$code, []))->map(fn (array $scene) => [$code, $scene]))
            ->shuffle()
            ->sortBy(fn (array $entry) => $played->has($entry[1][0]) ? 1 : 0)
            ->take($rules['turns'])
            ->map(function (array $entry) {
                [$code, $scene] = $entry;
                $side = fn (array $role) => ['role' => $role[0], 'goal' => $role[1], 'help' => $role[2]];

                return [
                    'word' => $scene[0],
                    'a' => $side($scene['a']),
                    'b' => $side($scene['b']),
                    'options_a' => collect([$scene['a'][1], ...$scene['a'][3]])->shuffle()->values()->all(),
                    'options_b' => collect([$scene['b'][1], ...$scene['b'][3]])->shuffle()->values()->all(),
                    'source' => $code.' · Role-play',
                    'bonus' => [],
                    'key' => 'role:'.$code.':'.$scene[0],
                ];
            })
            ->values()
            ->all();
    }
}
