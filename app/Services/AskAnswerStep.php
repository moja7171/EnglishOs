<?php

namespace App\Services;

/**
 * The state of one sentence of an Ask & Answer round (a question or an
 * answer), shared by the game with a friend and the practice with Teddy:
 * right first time is a point, right after one fix half a point, a second
 * miss passes on, and two sentences nobody could check score nothing.
 *
 * A step is: tries, unchecked, status (null while going on, then ok / fixed
 * / passed / unchecked), heard, correct, why, extra, points and level.
 */
class AskAnswerStep
{
    /** A sentence is tried at most this many times before the round moves on. */
    public const TRIES = 2;

    /** Half a point for the extra detail said as well. */
    public const POINTS_EXTRA = 1;

    /** Score is kept in half points: a point is 2 (see PairGame::POINTS_WORD). */
    private const POINT = 2;

    private const HALF_POINT = 1;

    /**
     * @return array{tries: int, unchecked: int, status: ?string, heard: ?string, correct: ?string, why: ?string, extra: bool, points: int, level: int}
     */
    public static function fresh(): array
    {
        return ['tries' => 0, 'unchecked' => 0, 'status' => null, 'heard' => null, 'correct' => null, 'why' => null, 'extra' => false, 'points' => 0, 'level' => 1];
    }

    /**
     * The step after one checked sentence (see AskAnswerChecker::check()).
     *
     * @param  array<string, mixed>  $step
     * @param  array{status: string, extra: bool, correct: string, why: ?string}  $result
     * @return array<string, mixed>
     */
    public static function apply(array $step, string $heard, array $result, int $level): array
    {
        $step['heard'] = $heard;
        $step['level'] = $level;

        if ($result['status'] === 'ok') {
            $step['status'] = $step['tries'] === 0 ? 'ok' : 'fixed';
            $step['points'] = ($step['tries'] === 0 ? self::POINT : self::HALF_POINT) + ($result['extra'] ? self::POINTS_EXTRA : 0);
            $step['extra'] = $result['extra'];

            return $step;
        }

        if ($result['status'] === 'wrong') {
            $step['tries']++;
            $step['correct'] = $result['correct'];
            $step['why'] = $result['why'];
            $step['status'] = $step['tries'] >= self::TRIES ? 'passed' : null;

            return $step;
        }

        $step['unchecked']++;
        $step['status'] = $step['unchecked'] >= self::TRIES ? 'unchecked' : null;

        return $step;
    }

    /**
     * The step when the speaker skips: the right sentence stays on screen.
     *
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    public static function pass(array $step, int $level, ?string $target): array
    {
        $step['status'] = 'passed';
        $step['level'] = $level;
        $step['correct'] ??= $target;

        return $step;
    }

    /**
     * The level to check the next try against: after one miss the speaker
     * repeats the corrected sentence, so that is the target, and the
     * original target is still accepted.
     *
     * @param  array<string, mixed>  $level
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    public static function levelToCheck(array $level, array $step): array
    {
        if (($step['tries'] ?? 0) < 1 || blank($step['correct'] ?? null)) {
            return $level;
        }

        return [...$level, 'en' => $step['correct'], 'alt' => [$level['en'], ...($level['alt'] ?? [])], 'wrong' => []];
    }
}
