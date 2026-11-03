<?php

namespace Database\Factories;

use App\Models\PairGame;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PairGame>
 */
class PairGameFactory extends Factory
{
    /**
     * A 5-minute Taboo game user B has joined, waiting for user A (who
     * started it) to start the first turn: 4 turns of 60 s, 2 skips each.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_a_id' => User::factory(),
            'user_b_id' => User::factory(),
            'started_by_id' => fn (array $attributes) => $attributes['user_a_id'],
            'game' => PairGame::GAME_TABOO,
            'minutes' => 5,
            'rules' => ['turn_seconds' => 60, 'turns' => 4, 'skips' => 2, 'bonus' => true, 'starters' => 2, 'level' => 5],
            'deck' => [
                ['word' => 'breakfast', 'source' => 'M04 · Food', 'bonus' => ['morning', 'eat'], 'key' => 'w:breakfast'],
                ['word' => 'kitchen', 'source' => 'M04 · Food', 'bonus' => [], 'key' => 'w:kitchen'],
                ['word' => 'commute', 'source' => 'Notebook', 'bonus' => ['work', 'travel'], 'key' => 'w:commute'],
                ['word' => 'spoon', 'source' => 'M04 · Food', 'bonus' => [], 'key' => 'w:spoon'],
            ],
            'turns' => [],
            'stage' => PairGame::STAGE_READY,
            'joined_at' => now(),
        ];
    }

    /**
     * An invite still waiting for user B to join.
     */
    public function waiting(): static
    {
        return $this->state(fn () => ['stage' => PairGame::STAGE_WAITING, 'joined_at' => null]);
    }
}
