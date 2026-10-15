<?php

namespace Database\Factories;

use App\Models\AudioListen;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AudioListen>
 */
class AudioListenFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'learner_id' => User::factory(),
            'mission_code' => 'M01',
            'source' => AudioListen::SOURCE_LISTENING,
        ];
    }

    /**
     * A listen of one of a day's three picks on /listening.
     */
    public function pick(int $dayNumber = 1, int $level = 0): static
    {
        return $this->state([
            'source' => AudioListen::SOURCE_LISTENING_PICK,
            'day_number' => $dayNumber,
            'pick_key' => AudioListen::pickKey($dayNumber, $level),
        ]);
    }

    public function selfReported(): static
    {
        return $this->state(['self_reported' => true]);
    }
}
