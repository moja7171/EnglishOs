<?php

namespace App\Services;

use App\Models\PairGame;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Eight personal badges for playing on the Games page — for effort and
 * variety only, never for beating someone. Nothing is stored: each badge
 * is worked out from the finished games, so "new" means earned with this
 * game and not before it.
 */
class GameBadges
{
    /**
     * Every badge: its name, the one line under it, its icon and how much
     * it takes.
     *
     * @var array<string, array{name: string, line: string, icon: string, target: int}>
     */
    public const BADGES = [
        'first-game' => ['name' => 'First game', 'line' => 'Play your first game', 'icon' => 'heroicon-o-play', 'target' => 1],
        'all-games' => ['name' => 'Explorer', 'line' => 'Try all 7 games', 'icon' => 'heroicon-o-map', 'target' => 7],
        'games-10' => ['name' => '10 games', 'line' => 'Play 10 games', 'icon' => 'heroicon-o-fire', 'target' => 10],
        'games-50' => ['name' => '50 games', 'line' => 'Play 50 games', 'icon' => 'heroicon-o-star', 'target' => 50],
        'friend-streak' => ['name' => '3 days in a row', 'line' => 'Play with one friend 3 days running', 'icon' => 'heroicon-o-calendar-days', 'target' => 3],
        'records-5' => ['name' => 'Record breaker', 'line' => 'Set 5 new records', 'icon' => 'heroicon-o-trophy', 'target' => 5],
        'bonus-10' => ['name' => 'Clean talker', 'line' => 'Avoid the bonus words 10 times in Taboo', 'icon' => 'heroicon-o-shield-check', 'target' => 10],
        'talk-60' => ['name' => 'Hour of talk', 'line' => 'Play 60 minutes in all', 'icon' => 'heroicon-o-chat-bubble-left-right', 'target' => 60],
    ];

    /**
     * All eight badges with $user's progress, from their finished games
     * (up to and including $upTo when given).
     *
     * @return list<array{key: string, name: string, line: string, icon: string, target: int, progress: int, earned: bool}>
     */
    public function for(User $user, ?PairGame $upTo = null): array
    {
        $progress = $this->progress($user, $this->finishedGames($user, $upTo));

        return collect(self::BADGES)
            ->map(fn (array $badge, string $key) => [
                'key' => $key,
                ...$badge,
                'progress' => min($badge['target'], $progress[$key]),
                'earned' => $progress[$key] >= $badge['target'],
            ])
            ->values()
            ->all();
    }

    /**
     * Badges $user earned with this very game.
     *
     * @return list<array{key: string, name: string, line: string, icon: string, target: int, progress: int, earned: bool}>
     */
    public function newWith(User $user, PairGame $pairGame): array
    {
        if (! $pairGame->isFinished() || ! $pairGame->isAccessibleBy($user)) {
            return [];
        }

        $games = $this->finishedGames($user, $pairGame);
        $before = $this->progress($user, $games->reject(fn (PairGame $game) => $game->is($pairGame)));
        $after = $this->progress($user, $games);

        return collect($this->for($user, $pairGame))
            ->filter(fn (array $badge) => $badge['earned'] && $before[$badge['key']] < $badge['target'] && $after[$badge['key']] >= $badge['target'])
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, PairGame>
     */
    private function finishedGames(User $user, ?PairGame $upTo): Collection
    {
        return PairGame::involving($user)
            ->standalone()
            ->where('stage', PairGame::STAGE_DONE)
            ->when($upTo?->finished_at, fn ($query) => $query->where('finished_at', '<=', $upTo->finished_at))
            ->orderBy('finished_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * How far $user is with each badge.
     *
     * @param  Collection<int, PairGame>  $games  finished, oldest first
     * @return array<string, int>
     */
    private function progress(User $user, Collection $games): array
    {
        return [
            'first-game' => $games->count(),
            'all-games' => $games->pluck('game')->intersect(PairGame::PLAYABLE)->unique()->count(),
            'games-10' => $games->count(),
            'games-50' => $games->count(),
            'friend-streak' => $this->longestFriendStreak($user, $games),
            'records-5' => $this->newRecords($games),
            'bonus-10' => $this->bonusAvoided($user, $games),
            'talk-60' => $this->minutesPlayed($games),
        ];
    }

    /**
     * The most days running $user played with one and the same friend.
     *
     * @param  Collection<int, PairGame>  $games
     */
    private function longestFriendStreak(User $user, Collection $games): int
    {
        return $games->groupBy(fn (PairGame $game) => $game->user_a_id === $user->id ? $game->user_b_id : $game->user_a_id)
            ->map(function (Collection $friendGames) {
                $days = $friendGames->map(fn (PairGame $game) => $game->finished_at->copy()->startOfDay())->unique(fn (Carbon $day) => $day->toDateString())->sort()->values();
                $longest = $run = 0;
                $previous = null;

                foreach ($days as $day) {
                    $run = $previous !== null && $previous->copy()->addDay()->isSameDay($day) ? $run + 1 : 1;
                    $longest = max($longest, $run);
                    $previous = $day;
                }

                return $longest;
            })
            ->max() ?? 0;
    }

    /**
     * Games that beat the pair's best in that game and length (the first
     * game with points counts too, as on the result screen).
     *
     * @param  Collection<int, PairGame>  $games  oldest first
     */
    private function newRecords(Collection $games): int
    {
        $best = [];
        $records = 0;

        foreach ($games as $game) {
            $key = $game->user_a_id.'-'.$game->user_b_id.'-'.$game->game.'-'.$game->minutes;

            if ($game->score > 0 && $game->score > ($best[$key] ?? -1)) {
                $records++;
            }

            $best[$key] = max($best[$key] ?? 0, $game->score);
        }

        return $records;
    }

    /**
     * Taboo cards $user explained that were guessed without saying any of
     * their bonus words.
     *
     * @param  Collection<int, PairGame>  $games
     */
    private function bonusAvoided(User $user, Collection $games): int
    {
        return $games->where('game', PairGame::GAME_TABOO)->sum(fn (PairGame $game) => collect($game->turns ?? [])
            ->where('explainer_id', $user->id)
            ->sum(fn (array $turn) => collect($turn['cards'])
                ->filter(fn (array $card) => $card['result'] === PairGame::RESULT_GOT && ! ($card['bonus_said'] ?? false) && ($game->deck[$card['card']]['bonus'] ?? []) !== [])
                ->count()));
    }

    /**
     * Minutes really played: from the first turn's start to the end of the
     * game, never more than the game's length.
     *
     * @param  Collection<int, PairGame>  $games
     */
    private function minutesPlayed(Collection $games): int
    {
        $seconds = $games->sum(function (PairGame $game) {
            $started = $game->turns[0]['started_at'] ?? null;

            return $started === null ? 0 : max(0, min($game->minutes * 60, $game->finished_at->getTimestamp() - $started));
        });

        return intdiv((int) $seconds, 60);
    }
}
