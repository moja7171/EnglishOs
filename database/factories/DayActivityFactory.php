<?php

namespace Database\Factories;

use App\Models\DayActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DayActivity>
 */
class DayActivityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'learner_id' => User::factory(),
            'on_date' => today()->toDateString(),
            'activity' => 'step:'.fake()->unique()->numberBetween(1, 100000).':listening_1',
        ];
    }
}
