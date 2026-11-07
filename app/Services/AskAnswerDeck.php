<?php

namespace App\Services;

use App\Models\AskAnswerItem;
use App\Models\AskAnswerProgress;
use App\Models\PairGame;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Ask & Answer: each round is one situation (AskAnswerItem) — one friend
 * asks, the other answers — and the two swap every round. Rounds come in
 * blocks of four plain ones then four follow-ups, so 5 minutes is one block
 * and 10 minutes is two. Situations come from the mission days both friends
 * finished (or the days they pick); ones neither has played come first, and
 * ones said wrong come back after a few days.
 */
class AskAnswerDeck
{
    /** Rounds per block: this many plain ones, then this many follow-ups. */
    public const BLOCK = 4;

    /** The round is not timed; this only closes a round a phone walked away from. */
    public const ROUND_SECONDS = 300;

    /** A situation said wrong comes back after this many days. */
    private const RETRY_AFTER_DAYS = 3;

    public function __construct(private readonly PairPractice $pairPractice, private readonly TabooDeck $taboo) {}

    /**
     * @return array{turn_seconds: int, turns: int, skips: null, bonus: bool, starters: int, level: int, levels: array<int, array{q: int, a: int}>}
     */
    public function rulesFor(User $a, User $b, int $minutes): array
    {
        return [
            'turn_seconds' => self::ROUND_SECONDS,
            'turns' => $minutes >= 10 ? 4 * self::BLOCK : 2 * self::BLOCK,
            'skips' => null,
            'bonus' => false,
            'starters' => 0,
            'level' => min($this->taboo->missionNumberOf($a), $this->taboo->missionNumberOf($b)),
            'levels' => [
                $a->id => ['q' => (int) $a->ask_question_level, 'a' => (int) $a->ask_answer_level],
                $b->id => ['q' => (int) $b->ask_question_level, 'a' => (int) $b->ask_answer_level],
            ],
        ];
    }

    /**
     * Whether any situation is written for the days these two draw from.
     */
    public function available(User $a, User $b, ?array $days = null): bool
    {
        return $this->candidates($a, $b, ['days' => $days, 'level' => min($this->taboo->missionNumberOf($a), $this->taboo->missionNumberOf($b))])->isNotEmpty();
    }

    /**
     * @param  array{turns: int, level: int, days?: ?list<string>}  $rules
     * @return list<array<string, mixed>>
     */
    public function cardsFor(User $a, User $b, array $rules): array
    {
        $candidates = $this->candidates($a, $b, $rules);

        if ($candidates->isEmpty()) {
            return [];
        }

        $progress = AskAnswerProgress::query()
            ->whereIn('user_id', array_unique([$a->id, $b->id]))
            ->whereIn('ask_answer_item_id', $candidates->pluck('id'))
            ->get()
            ->groupBy('ask_answer_item_id');

        $ranked = $candidates->sortBy(fn (AskAnswerItem $item) => $this->rank($progress->get($item->id, collect())) + mt_rand(0, 3))->values();
        $pools = [
            AskAnswerItem::KIND_SIMPLE => $ranked->where('kind', AskAnswerItem::KIND_SIMPLE)->values(),
            AskAnswerItem::KIND_FOLLOWUP => $ranked->where('kind', AskAnswerItem::KIND_FOLLOWUP)->values(),
        ];
        $cards = [];
        $taken = [];

        for ($round = 0; $round < $rules['turns']; $round++) {
            $kind = $round % (2 * self::BLOCK) < self::BLOCK ? AskAnswerItem::KIND_SIMPLE : AskAnswerItem::KIND_FOLLOWUP;
            $item = $this->next($pools[$kind], $ranked, $taken, array_slice($cards, -(self::BLOCK - 1)));

            if ($item === null) {
                break;
            }

            $taken[] = $item->id;
            $cards[] = $item->toCard();
        }

        return $cards;
    }

    /**
     * The first situation of the pool not used yet and not on the same
     * subject as one of the last few rounds; if the pool runs dry, any
     * unused one, then the pool again.
     *
     * @param  Collection<int, AskAnswerItem>  $pool
     * @param  Collection<int, AskAnswerItem>  $all
     * @param  list<int>  $taken
     * @param  list<array<string, mixed>>  $recentCards
     */
    private function next(Collection $pool, Collection $all, array $taken, array $recentCards): ?AskAnswerItem
    {
        $recentSituations = collect($recentCards)->pluck('situation')->all();

        foreach ([$pool, $all] as $source) {
            $fresh = $source->reject(fn (AskAnswerItem $item) => in_array($item->id, $taken, true));

            $item = $fresh->first(fn (AskAnswerItem $item) => ! in_array($item->situation, $recentSituations, true)) ?? $fresh->first();

            if ($item) {
                return $item;
            }
        }

        return $pool->first();
    }

    /**
     * Lower plays first: never played by either friend (0), played a lot
     * (more), and one that was said wrong long enough ago jumps ahead.
     *
     * @param  Collection<int, AskAnswerProgress>  $rows
     */
    private function rank(Collection $rows): int
    {
        $seen = (int) $rows->sum('times_seen');
        $retry = $rows->contains(fn (AskAnswerProgress $row) => $row->missed && $row->last_seen_at?->lte(now()->subDays(self::RETRY_AFTER_DAYS)));

        return $seen * 10 - ($retry ? 25 : 0);
    }

    /**
     * Situations written for the days the pair finished together (or the
     * days they chose); when there are none, everything up to the mission
     * the less advanced of the two is on.
     *
     * @param  array{level: int, days?: ?list<string>}  $rules
     * @return Collection<int, AskAnswerItem>
     */
    private function candidates(User $a, User $b, array $rules): Collection
    {
        // Days named outright (the Daily talk of a day) are used as they are;
        // otherwise the days both friends finished.
        $named = collect($rules['days'] ?? [])
            ->map(fn (string $key) => preg_match('/^M(\d+)-(\d)$/', $key, $match) === 1 ? [(int) $match[1], (int) $match[2]] : null)
            ->filter()
            ->values();
        $days = $named->isNotEmpty()
            ? $named
            : $this->pairPractice->gameDays($a, $b, $rules['days'] ?? null)
                ->map(fn (array $day) => [$day['mission']->number(), $day['day']])
                ->values();

        $items = AskAnswerItem::query()
            ->whereIn('mission_number', $days->pluck(0)->unique()->all())
            ->get()
            ->filter(fn (AskAnswerItem $item) => $days->contains(fn (array $pair) => $pair[0] === $item->mission_number && $pair[1] === $item->day));

        if ($items->isNotEmpty() || ($rules['days'] ?? null) !== null) {
            return $items->values();
        }

        return AskAnswerItem::query()->where('mission_number', '<=', max(1, $rules['level']))->get();
    }

    /**
     * A finished game: each friend's situations are marked as seen, and
     * as missed when they had to be corrected or passed.
     */
    public function recordPlayed(PairGame $game): void
    {
        foreach ($game->turns ?? [] as $turn) {
            foreach ($turn['cards'] as $played) {
                $itemId = $game->deck[$played['card']]['id'] ?? null;

                if ($itemId === null) {
                    continue;
                }

                foreach ([PairGame::PHASE_QUESTION => $turn['explainer_id'], PairGame::PHASE_ANSWER => $game->otherPlayerIdOf($turn['explainer_id'])] as $step => $userId) {
                    $row = AskAnswerProgress::firstOrNew(['user_id' => $userId, 'ask_answer_item_id' => $itemId]);
                    $row->forceFill([
                        'times_seen' => (int) $row->times_seen + 1,
                        'missed' => ($played['qa'][$step]['status'] ?? null) !== 'ok',
                        'last_seen_at' => now(),
                    ])->save();
                }
            }
        }
    }
}
