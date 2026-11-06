<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\VocabularyWord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VocabularyWord>
 */
class VocabularyWordFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'learner_id' => User::factory(),
            'source_mission_run_id' => null,
            'word' => fake()->unique()->word(),
            'meaning' => fake()->sentence(),
            'meaning_fa' => 'معنی',
            'pos' => 'noun',
            'example' => null,
            'next_review_at' => now()->addDay(),
        ];
    }
}
