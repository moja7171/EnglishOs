<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\VocabProgress;
use App\Models\VocabText;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VocabProgress>
 */
class VocabProgressFactory extends Factory
{
    /**
     * A sentence saved a day ago, so it is due now.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'vocab_text_id' => VocabText::factory(),
            'sentence_index' => 0,
            'saved_item_ids' => [],
            'next_review_at' => now()->subMinute(),
        ];
    }

    public function notDueYet(): static
    {
        return $this->state(fn (): array => ['next_review_at' => now()->addDay()]);
    }
}
