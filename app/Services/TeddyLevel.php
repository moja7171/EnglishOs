<?php

namespace App\Services;

use App\Models\AiPractice;
use App\Models\User;

/**
 * How well Teddy plays today: easy after the learner has been losing,
 * hard after a run of wins, so that about half the games are theirs. It
 * is worked out once when a practice starts (from the last few finished
 * ones of the same kind) and kept in the practice's deck. It also sets
 * how often he slips on his own turns.
 */
class TeddyLevel
{
    public const EASY = 'easy';

    public const NORMAL = 'normal';

    public const HARD = 'hard';

    /** Finished practices of the same kind that count. */
    private const LOOKBACK = 3;

    /** Fewer than this many finished ones: stay normal. */
    private const MIN_PRACTICES = 2;

    /** Average share of points above which the learner is winning easily. */
    private const WINNING_ABOVE = 0.75;

    /** Average share of points below which the learner is struggling. */
    private const STRUGGLING_BELOW = 0.35;

    /**
     * The chance, in percent, that Teddy slips on a turn of his own.
     *
     * @var array<string, int>
     */
    private const SLIP_PERCENT = [self::EASY => 25, self::NORMAL => 34, self::HARD => 45];

    public function levelFor(User $learner, string $kind): string
    {
        $shares = AiPractice::where('learner_id', $learner->id)
            ->where('kind', $kind)
            ->where('answered', '>=', AiPractice::MIN_COUNTED_ANSWERS)
            ->whereNotNull('finished_at')
            ->latest('finished_at')
            ->take(self::LOOKBACK)
            ->get()
            ->map(fn (AiPractice $practice) => min(1, $practice->score / max(1, $practice->totalCards())));

        if ($shares->count() < self::MIN_PRACTICES) {
            return self::NORMAL;
        }

        return match (true) {
            $shares->avg() >= self::WINNING_ABOVE => self::HARD,
            $shares->avg() <= self::STRUGGLING_BELOW => self::EASY,
            default => self::NORMAL,
        };
    }

    /**
     * Which of Teddy's own turns he slips on, by part of the practice. A
     * part with turns of his own gets at least none and at most half of
     * them, drawn at random once and then kept.
     *
     * @param  array<string, mixed>  $deck
     * @return array<string, list<int>>
     */
    public function planSlips(array $deck, string $level): array
    {
        $chance = self::SLIP_PERCENT[$level] ?? self::SLIP_PERCENT[self::NORMAL];
        $slips = [];

        foreach (AiPractice::CARD_STAGES as $stage) {
            foreach ($deck[$stage] ?? [] as $position => $card) {
                $isTeddysTurn = $position % 2 === 1 && ! isset($card['owner_id']) && filled($card['answer'] ?? null);

                if ($isTeddysTurn && random_int(1, 100) <= $chance) {
                    $slips[$stage][] = $position;
                }
            }
        }

        return $slips;
    }
}
