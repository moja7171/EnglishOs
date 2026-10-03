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
}
