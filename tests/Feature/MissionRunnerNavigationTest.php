<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use Database\Seeders\MissionSeeder;
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

    public function test_retry_reopens_an_already_evidenced_step_as_live_and_editable(): void
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
            ->set('retry', true)
            ->assertSet('activeStepKey', 'mission_brief')
            ->assertSet('isReviewing', false);
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

    public function test_reviewing_a_real_seeded_step_shows_the_same_layout_with_the_saved_answer(): void
    {
        $this->seed(MissionSeeder::class);

        $learner = User::factory()->create();
        $mission = Mission::where('code', 'M01')->firstOrFail();
        $run = MissionRun::findOrStart($learner, $mission);

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'mission_brief',
            'type' => Evidence::TYPE_SCORE,
            'content_ref' => '4',
        ]);

        $this->actingAs($learner);

        // Real seeded warm-up question text AND the saved score both render
        // through the exact same mission-brief component the live step uses.
        Livewire::test('missions.runner', ['mission' => $mission, 'step' => 'mission_brief'])
            ->assertSee('What time do you usually wake up?')
            ->assertSeeHtml('bg-accent') // the "4" button rendered as chosen
            ->assertDontSee('Continue');
    }

    public function test_a_fresh_run_shows_the_day_overview_with_all_four_days(): void
    {
        $this->seed(MissionSeeder::class);

        $learner = User::factory()->create();
        $mission = Mission::where('code', 'M01')->firstOrFail();
        MissionRun::findOrStart($learner, $mission);

        $this->actingAs($learner);

        Livewire::test('missions.runner', ['mission' => $mission])
            ->assertSet('showOverview', true)
            ->assertSee('Day 1')
            ->assertSee('Day 2')
            ->assertSee('Day 3')
            ->assertSee('Day 4')
            ->assertSee('Continue') // Day 1's entry button, not started yet
            ->assertDontSee('Completed');
    }

    public function test_mid_day_bare_visit_skips_the_overview_and_goes_to_the_step(): void
    {
        $this->seed(MissionSeeder::class);

        $learner = User::factory()->create();
        $mission = Mission::where('code', 'M01')->firstOrFail();
        $run = MissionRun::findOrStart($learner, $mission);

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'mission_brief',
            'type' => Evidence::TYPE_SCORE,
            'content_ref' => '3',
        ]);

        $this->actingAs($learner);

        Livewire::test('missions.runner', ['mission' => $mission])
            ->assertSet('showOverview', false)
            ->assertSet('activeStepKey', 'vocabulary_builder');
    }

    public function test_finishing_a_day_shows_the_overview_again_with_that_day_marked_done(): void
    {
        $this->seed(MissionSeeder::class);

        $learner = User::factory()->create();
        $mission = Mission::where('code', 'M01')->firstOrFail();
        $run = MissionRun::findOrStart($learner, $mission);

        foreach (['mission_brief', 'vocabulary_builder', 'listening'] as $key) {
            Evidence::create([
                'mission_run_id' => $run->id,
                'phase' => $key,
                'type' => Evidence::TYPE_TEXT,
                'content_ref' => 'done',
            ]);
        }

        $this->actingAs($learner);

        Livewire::test('missions.runner', ['mission' => $mission])
            ->assertSet('showOverview', true)
            ->assertSee('Completed') // Day 1
            ->assertSee('Review'); // Day 1's entry button now offers review
    }

    public function test_next_and_previous_never_cross_a_day_boundary(): void
    {
        $this->seed(MissionSeeder::class);

        $learner = User::factory()->create();
        $mission = Mission::where('code', 'M01')->firstOrFail();
        $run = MissionRun::findOrStart($learner, $mission);

        foreach (['mission_brief', 'vocabulary_builder', 'listening'] as $key) {
            Evidence::create([
                'mission_run_id' => $run->id,
                'phase' => $key,
                'type' => Evidence::TYPE_TEXT,
                'content_ref' => 'done',
            ]);
        }

        $this->actingAs($learner);

        // "listening" is the last step of Day 1 — even though grammar_in_context
        // (Day 2) is the actual next mission-wide step, Next must not offer it.
        Livewire::test('missions.runner', ['mission' => $mission, 'step' => 'listening'])
            ->assertSet('nextStepKey', null);
    }

    public function test_the_overview_pseudo_step_always_forces_the_overview_even_mid_day(): void
    {
        $this->seed(MissionSeeder::class);

        $learner = User::factory()->create();
        $mission = Mission::where('code', 'M01')->firstOrFail();
        $run = MissionRun::findOrStart($learner, $mission);

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'mission_brief',
            'type' => Evidence::TYPE_SCORE,
            'content_ref' => '3',
        ]);

        $this->actingAs($learner);

        Livewire::test('missions.runner', ['mission' => $mission, 'step' => 'overview'])
            ->assertSet('showOverview', true);
    }

    public function test_the_within_day_checklist_shows_real_step_labels_not_just_numbers(): void
    {
        $this->seed(MissionSeeder::class);

        $learner = User::factory()->create();
        $mission = Mission::where('code', 'M01')->firstOrFail();
        MissionRun::findOrStart($learner, $mission);

        $this->actingAs($learner);

        // On Day 1's first step, all three of that day's real labels should
        // already be visible (icon + text), not hidden behind a numbered dot.
        Livewire::test('missions.runner', ['mission' => $mission, 'step' => 'mission_brief'])
            ->assertSee('Mission Brief')
            ->assertSee('Vocabulary Builder')
            ->assertSee('Listening')
            // A later day's steps must stay invisible-as-content on Day 1 — the
            // checklist only ever lists the active day's own steps.
            ->assertDontSee('Mission Result');
    }
}
