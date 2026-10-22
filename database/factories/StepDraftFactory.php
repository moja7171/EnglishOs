<?php

namespace Database\Factories;

use App\Models\StepDraft;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * There is no MissionRun factory, so callers pass the run:
 * StepDraft::factory()->for($run, 'missionRun').
 *
 * @extends Factory<StepDraft>
 */
class StepDraftFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'phase' => 'picture_description',
            'data' => [],
            'paths' => [],
        ];
    }
}
