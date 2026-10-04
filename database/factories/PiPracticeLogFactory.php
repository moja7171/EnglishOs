<?php

namespace Database\Factories;

use App\Models\PiPracticeLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PiPracticeLog>
 */
class PiPracticeLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'learner_id' => User::factory(),
            'practiced_on' => now()->toDateString(),
            'mission_code' => 'M01',
            'day_number' => 1,
        ];
    }
}
