<?php

namespace Database\Factories;

use App\Models\NotebookEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotebookEntry>
 */
class NotebookEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $better = fake()->sentence();

        return [
            'learner_id' => User::factory(),
            'kind' => NotebookEntry::KIND_MISTAKE,
            'dedupe_key' => 'm:'.fake()->unique()->words(3, true),
            'label' => 'past simple',
            'original' => fake()->sentence(),
            'better' => $better,
            'note' => 'زمان فعل را درست کن.',
            'source' => 'writing',
            'last_seen_at' => now(),
            'next_review_at' => now(),
        ];
    }

    public function tip(): static
    {
        return $this->state(fn () => [
            'kind' => NotebookEntry::KIND_TIP,
            'dedupe_key' => 'p:'.fake()->unique()->words(3, true),
            'label' => null,
        ]);
    }

    public function learned(): static
    {
        return $this->state(fn () => ['learned_at' => now()]);
    }
}
