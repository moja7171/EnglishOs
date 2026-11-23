<?php

namespace Database\Factories;

use App\Models\ExtraEpisode;
use App\Models\ExtraWatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExtraWatch>
 */
class ExtraWatchFactory extends Factory
{
    /**
     * An episode a learner has just finished watching.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'extra_episode_id' => ExtraEpisode::factory(),
            'watched_at' => now(),
        ];
    }
}
