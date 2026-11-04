<?php

namespace App\Services;

use App\Models\PairGame;

/**
 * The one-time usage guides: which guide belongs to a part of the training
 * and what it says. Whether a learner has read one lives on the User.
 */
class UsageGuides
{
    /** A game's guide key starts with this; the rest is the game. */
    private const GAME_PREFIX = 'game_';

    /**
     * @return array{title: string, lines: list<string>, more?: array{label: string, route: string}}|null
     */
    public static function find(string $key): ?array
    {
        if (str_starts_with($key, self::GAME_PREFIX)) {
            $game = substr($key, strlen(self::GAME_PREFIX));
            $lines = PairGame::guide($game);

            return $lines === [] ? null : ['title' => PairGame::info($game)['name'], 'lines' => $lines];
        }

        return config('usage_guides.'.$key);
    }

    /**
     * New Words runs on three days and Listen Again on two: one guide each,
     * so a learner confirms it once.
     */
    public static function keyForStep(string $stepKey): string
    {
        return match (true) {
            str_starts_with($stepKey, 'vocabulary_builder_') => 'vocabulary_builder',
            str_starts_with($stepKey, 'daily_listen_') => 'daily_listen',
            default => $stepKey,
        };
    }

    public static function keyForGame(string $game): string
    {
        return self::GAME_PREFIX.$game;
    }
}
