<?php

namespace Database\Factories;

use App\Models\PairPracticeSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PairPracticeSession>
 */
class PairPracticeSessionFactory extends Factory
{
    /**
     * A round on its first vocabulary card, started by user A.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_a_id' => User::factory(),
            'user_b_id' => User::factory(),
            'started_by_id' => fn (array $attributes) => $attributes['user_a_id'],
            'mission_code' => 'M01',
            'day_number' => 1,
            'deck' => [
                PairPracticeSession::STAGE_VOCABULARY => [
                    ['prompt' => 'بیدار شدن', 'answer' => 'wake up', 'meaning' => 'to stop sleeping', 'pos' => 'phrasal verb', 'example' => 'I wake up at seven.', 'source' => 'M01 · Day 1'],
                ],
                PairPracticeSession::STAGE_GRAMMAR => [
                    ['prompt' => 'She go to work at eight.', 'answer' => 'She goes to work at eight.', 'source' => 'M01 · Day 2'],
                ],
                PairPracticeSession::STAGE_SPEAKING => [
                    ['question' => 'What time do you wake up?', 'answer' => 'I usually wake up at seven.', 'words' => ['wake up', 'usually'], 'source' => 'M01 · Day 1'],
                ],
            ],
            'results' => [],
            'stage' => PairPracticeSession::STAGE_VOCABULARY,
            'stage_ends_at' => now()->addSeconds(PairPracticeSession::STAGE_SECONDS[PairPracticeSession::STAGE_VOCABULARY]),
        ];
    }

    /**
     * A round that is still waiting for user B to join.
     */
    public function waiting(): static
    {
        return $this->state(fn () => [
            'stage' => PairPracticeSession::STAGE_WAITING,
            'stage_ends_at' => null,
        ]);
    }
}
