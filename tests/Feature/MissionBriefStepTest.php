<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MissionBriefStepTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_a_score_records_evidence_and_advances_the_run(): void
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [
                [
                    'phase' => 'foundation',
                    'steps' => [
                        ['key' => 'mission_brief', 'warm_up_questions' => ['What time do you wake up?']],
                        ['key' => 'vocabulary_builder'],
                    ],
                ],
            ],
        ]);
        $run = MissionRun::findOrStart($learner, $mission);

        Livewire::test('missions.steps.mission-brief', ['run' => $run])
            ->set('score', 3)
            ->call('save')
            ->assertRedirect(route('missions.show', $mission));

        $this->assertDatabaseHas('evidences', [
            'mission_run_id' => $run->id,
            'phase' => 'mission_brief',
            'type' => Evidence::TYPE_SCORE,
            'content_ref' => '3',
        ]);

        $this->assertSame('vocabulary_builder', $run->fresh()->currentStepKey());
    }

    public function test_score_is_required_before_saving(): void
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [['phase' => 'foundation', 'steps' => [['key' => 'mission_brief']]]],
        ]);
        $run = MissionRun::findOrStart($learner, $mission);

        Livewire::test('missions.steps.mission-brief', ['run' => $run])
            ->call('save')
            ->assertHasErrors(['score' => 'required']);

        $this->assertDatabaseCount('evidences', 0);
    }

    public function test_read_only_mode_preloads_the_saved_score_and_hides_continue(): void
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [['phase' => 'foundation', 'steps' => [['key' => 'mission_brief']]]],
        ]);
        $run = MissionRun::findOrStart($learner, $mission);

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'mission_brief',
            'type' => Evidence::TYPE_SCORE,
            'content_ref' => '4',
        ]);

        Livewire::test('missions.steps.mission-brief', ['run' => $run, 'readOnly' => true])
            ->assertSet('score', 4)
            ->assertDontSee('Continue');
    }

    public function test_shows_the_real_hook_and_a_roadmap_of_the_mission_phases(): void
    {
        $this->seed(\Database\Seeders\MissionSeeder::class);

        $learner = User::factory()->create();
        $mission = Mission::where('code', 'M01')->firstOrFail();
        $run = MissionRun::findOrStart($learner, $mission);

        Livewire::test('missions.steps.mission-brief', ['run' => $run])
            ->assertSee('new coworker turns to you and asks')
            ->assertSee('Foundation')
            ->assertSee('Build')
            ->assertSee('Mission')
            ->assertSee('We\'ll compare this to your score at the end of the mission.', false);
    }
}
