<?php

namespace Database\Factories;

use App\Models\ExtraEpisode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExtraEpisode>
 */
class ExtraEpisodeFactory extends Factory
{
    /**
     * Episode 1 of the series, "Hector's Arrival" (about 24 minutes).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => fake()->unique()->numberBetween(1, 30),
            'title' => "Hector's Arrival",
            'video_file' => '01 - Hectors_arrival [www.languagecentre.ir].mp4',
            'duration_seconds' => 1438,
        ];
    }
}
