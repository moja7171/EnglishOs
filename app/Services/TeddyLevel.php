<?php

namespace App\Services;

use App\Models\AiPractice;
use App\Models\PairGame;
use App\Models\User;
use Illuminate\Support\Arr;

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

    /** A grammar card made from the learner's own notebook mistake: it counts at least this much when a slip is drawn. */
    private const NOTEBOOK_SLIP_PERCENT = 60;

    public function levelFor(User $learner, string $kind): string
    {
        $shares = AiPractice::where('learner_id', $learner->id)
            ->where('kind', $kind)
            ->where('answered', '>=', AiPractice::MIN_COUNTED_ANSWERS)
            ->whereNotNull('finished_at')
            ->latest('finished_at')
            ->take(self::LOOKBACK)
            ->get()
            ->map(fn (AiPractice $practice) => $practice->scoreShare());

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
     * Which of Teddy's own turns he slips on, by part of the practice.
     * About the level's share of all his turns (a whole number, rounded
     * up or down by chance), spread over the parts one at a time so that
     * no part is skipped while another has two. Within a part a card made
     * from the learner's own notebook mistake is likelier to be picked.
     * Drawn once and then kept.
     *
     * @param  array<string, mixed>  $deck
     * @return array<string, list<int>>
     */
    public function planSlips(array $deck, string $level): array
    {
        $chance = self::SLIP_PERCENT[$level] ?? self::SLIP_PERCENT[self::NORMAL];
        $turns = $this->turnsWithSlips($deck, $chance);
        $toPlan = $this->slipsToPlan(array_sum(array_map(count(...), $turns)), $chance);
        $slips = [];

        while ($toPlan > 0 && array_filter($turns) !== []) {
            foreach (Arr::shuffle(array_keys(array_filter($turns))) as $part) {
                if ($toPlan === 0) {
                    break;
                }

                $position = $this->drawTurn($turns[$part]);
                unset($turns[$part][$position]);
                $slips[$part][] = $position;
                $toPlan--;
            }
        }

        return array_map(function (array $positions) {
            sort($positions);

            return $positions;
        }, $slips);
    }

    /**
     * How many slips a practice with this many of Teddy's turns gets: the
     * share of them, rounded up with the chance of its fraction, and never
     * none when he has a turn to slip on.
     */
    private function slipsToPlan(int $turns, int $chance): int
    {
        $percentOfTurns = $turns * $chance;
        $wholeSlips = intdiv($percentOfTurns, 100) + (random_int(1, 100) <= $percentOfTurns % 100 ? 1 : 0);

        return min($turns, max($turns > 0 ? 1 : 0, $wholeSlips));
    }

    /**
     * Teddy's turns he may slip on in each part, as position => weight.
     *
     * @param  array<string, mixed>  $deck
     * @return array<string, array<int, int>>
     */
    private function turnsWithSlips(array $deck, int $chance): array
    {
        $turns = [];

        foreach (AiPractice::CARD_STAGES as $stage) {
            foreach ($deck[$stage] ?? [] as $position => $card) {
                if ($position % 2 === 1 && ! isset($card['owner_id']) && filled($card['answer'] ?? null)) {
                    $turns[$stage][$position] = isset($card['notebook_id']) ? max($chance, self::NOTEBOOK_SLIP_PERCENT) : $chance;
                }
            }
        }

        foreach ($this->gameTurnsWithSlips($deck) as $position) {
            $turns[AiPractice::STAGE_GAME][$position] = $chance;
        }

        return $turns;
    }

    /**
     * One position drawn by weight.
     *
     * @param  array<int, int>  $weights
     */
    private function drawTurn(array $weights): int
    {
        $draw = random_int(1, array_sum($weights));

        foreach ($weights as $position => $weight) {
            $draw -= $weight;

            if ($draw <= 0) {
                return $position;
            }
        }

        return array_key_last($weights);
    }

    /**
     * The game cards where Teddy may slip: the cards he holds the secret in
     * Who am I (answering yes or no wrongly), his turns in Just a Minute (a
     * mistake in his talk) and the start of a Story Chain (one of his
     * lines).
     *
     * @param  array<string, mixed>  $deck
     * @return list<int>
     */
    private function gameTurnsWithSlips(array $deck): array
    {
        $cards = $deck['game']['cards'] ?? [];

        return match ($deck['game']['game'] ?? null) {
            PairGame::GAME_WHO => array_keys(array_filter($cards, fn ($card, int $position) => $position % 2 === 0, ARRAY_FILTER_USE_BOTH)),
            PairGame::GAME_MINUTE => array_keys(array_filter($cards, fn ($card, int $position) => $position % 2 === 1, ARRAY_FILTER_USE_BOTH)),
            PairGame::GAME_STORY => $cards === [] ? [] : [0],
            default => [],
        };
    }
}
