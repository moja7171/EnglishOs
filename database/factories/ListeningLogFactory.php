<?php

namespace Database\Factories;

use App\Models\ListeningLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ListeningLog>
 */
class ListeningLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'learner_id' => User::factory(),
            'listened_on' => now()->toDateString(),
            'mission_code' => 'M01',
            'day_number' => 1,
        ];
    }
}
