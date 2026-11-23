<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\VocabUnit;
use App\Models\VocabUnitRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VocabUnitRun>
 */
class VocabUnitRunFactory extends Factory
{
    /**
     * A unit that was just opened: nothing listened to yet.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'vocab_unit_id' => VocabUnit::factory(),
            'listened_letters' => [],
        ];
    }

    public function finished(): static
    {
        return $this->state(fn (): array => [
            'listen_done_at' => now(),
            'read_done_at' => now(),
            'finished_at' => now(),
        ]);
    }
}
