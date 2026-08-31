<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MissionRunnerNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function makeMission(): Mission
    {
        return Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [
                [
                    'phase' => 'foundation',
                    'steps' => [
                        ['key' => 'mission_brief'],
                        ['key' => 'vocabulary_builder'],
                        ['key' => 'listening'],
                    ],
                ],
            ],
        ]);
    }

    public function test_a_future_step_is_not_reachable_and_falls_back_to_the_current_step(): void
    {
        $learner = User::factory()->create();
        $mission = $this->makeMission();
        MissionRun::findOrStart($learner, $mission);

        $this->actingAs($learner);

        Livewire::test('missions.runner', ['mission' => $mission, 'step' => 'listening'])
            ->assertSet('activeStepKey', 'mission_brief')
            ->assertSet('isReviewing', false);
    }

    public function test_a_completed_step_can_be_reviewed_without_disturbing_progress(): void
    {
        $learner = User::factory()->create();
        $mission = $this->makeMission();
        $run = MissionRun::findOrStart($learner, $mission);

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'mission_brief',
            'type' => Evidence::TYPE_SCORE,
            'content_ref' => '3',
        ]);

        $this->actingAs($learner);

        Livewire::test('missions.runner', ['mission' => $mission, 'step' => 'mission_brief'])
            ->assertSet('activeStepKey', 'mission_brief')
            ->assertSet('currentStepKey', 'vocabulary_builder')
            ->assertSet('isReviewing', true);

        // Progress itself is untouched by merely viewing a past step.
        $this->assertSame('vocabulary_builder', $run->fresh()->currentStepKey());
    }

    public function test_previous_and_next_step_keys_only_span_reachable_steps(): void
    {
        $learner = User::factory()->create();
        $mission = $this->makeMission();
        $run = MissionRun::findOrStart($learner, $mission);

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'mission_brief',
            'type' => Evidence::TYPE_SCORE,
            'content_ref' => '3',
        ]);

        $this->actingAs($learner);

        // Viewing the completed mission_brief step.
        Livewire::test('missions.runner', ['mission' => $mission, 'step' => 'mission_brief'])
            ->assertSet('previousStepKey', null)
            ->assertSet('nextStepKey', 'vocabulary_builder');

        // Viewing the current (not yet completed) vocabulary_builder step.
        Livewire::test('missions.runner', ['mission' => $mission, 'step' => 'vocabulary_builder'])
            ->assertSet('previousStepKey', 'mission_brief')
            ->assertSet('nextStepKey', null); // listening isn't reachable yet
    }

    public function test_a_fully_complete_mission_defaults_to_the_completion_card(): void
    {
        $learner = User::factory()->create();
        $mission = $this->makeMission();
        $run = MissionRun::findOrStart($learner, $mission);

        foreach ($mission->stepKeys() as $key) {
            Evidence::create([
                'mission_run_id' => $run->id,
                'phase' => $key,
                'type' => Evidence::TYPE_TEXT,
                'content_ref' => 'done',
            ]);
        }
        $run->update(['status' => 'complete']);

        $this->actingAs($learner);

        Livewire::test('missions.runner', ['mission' => $mission])
            ->assertSet('activeStepKey', null);

        // But a specific completed step can still be reviewed on request.
        Livewire::test('missions.runner', ['mission' => $mission, 'step' => 'listening'])
            ->assertSet('activeStepKey', 'listening')
            ->assertSet('isReviewing', true);
    }
}
