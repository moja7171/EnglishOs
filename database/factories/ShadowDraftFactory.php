<?php

namespace Database\Factories;

use App\Models\ShadowDraft;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * There is no MissionRun factory, so callers pass the run:
 * ShadowDraft::factory()->for($run, 'missionRun').
 *
 * @extends Factory<ShadowDraft>
 */
class ShadowDraftFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'phase' => 'video_shadowing',
            'line_index' => 0,
            'path' => 'missions/m01/evidence/'.fake()->uuid().'.webm',
        ];
    }
}
