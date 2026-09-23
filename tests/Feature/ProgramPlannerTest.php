<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\PlacementTest;
use App\Models\User;
use App\Services\ProgramPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProgramPlannerTest extends TestCase
{
    use RefreshDatabase;

    private function makeMission(string $code = 'M01', string $title = 'My Daily Life'): Mission
    {
        return Mission::create([
            'code' => $code,
            'title' => $title,
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [
                ['phase' => 'foundation', 'label' => 'Foundation', 'steps' => [
                    ['key' => 'mission_brief', 'label' => 'Mission Brief', 'duration_minutes' => 5],
                    ['key' => 'vocabulary_builder', 'label' => 'Vocabulary Builder', 'duration_minutes' => 15],
                ]],
                ['phase' => 'build', 'label' => 'Build', 'steps' => [
                    ['key' => 'grammar_in_context', 'label' => 'Grammar in Context', 'duration_minutes' => 12, 'focus' => 'Present Simple'],
                    ['key' => 'ai_conversation_2', 'label' => 'Final Challenge', 'duration_minutes' => 10, 'rounds' => ['What do you do?', 'Describe your morning.']],
                ]],
            ],
        ]);
    }

    private function record(MissionRun $run, string $phase): void
    {
        Evidence::create(['mission_run_id' => $run->id, 'phase' => $phase, 'type' => Evidence::TYPE_TEXT, 'content_ref' => 'x']);
    }

    public function test_a_brand_new_learner_is_on_day_1_about_to_start_m01(): void
    {
        $this->makeMission();
        $plan = app(ProgramPlanner::class)->plan(User::factory()->create());

        $this->assertSame(1, $plan['programDay']);
        $this->assertSame(96, $plan['totalDays']);
        $this->assertFalse($plan['started']);
        $this->assertSame('start_next', $plan['today']['kind']);
        $this->assertSame('M01', $plan['today']['nextMissionCode']);
        $this->assertSame('M01', $plan['today']['nextMission']->code);
    }

    public function test_starting_the_first_mission_stamps_program_day_1_once(): void
    {
        $learner = User::factory()->create();
        $mission = $this->makeMission();

        $this->assertNull($learner->program_started_at);
        MissionRun::findOrStart($learner, $mission);
        $startedAt = $learner->fresh()->program_started_at;
        $this->assertNotNull($startedAt);

        $this->travel(3)->days();
        MissionRun::findOrStart($learner->fresh(), $this->makeMission('M02', 'People'));
        $this->assertTrue($startedAt->equalTo($learner->fresh()->program_started_at));
    }

    public function test_mid_mission_today_is_the_current_day_with_its_steps(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission());
        $this->record($run, 'mission_brief');
        $this->record($run, 'vocabulary_builder');

        $today = app(ProgramPlanner::class)->plan($learner)['today'];

        $this->assertSame('mission_day', $today['kind']);
        $this->assertSame(2, $today['dayNumber']);
        $this->assertSame('Build', $today['dayLabel']);
        $this->assertSame(['grammar_in_context', 'ai_conversation_2'], array_column($today['steps'], 'key'));
        $this->assertTrue($today['steps'][0]['current']);
        $this->assertSame(22, $today['estimatedMinutes']);
        $this->assertSame(2, app(ProgramPlanner::class)->plan($learner)['programDay']);
    }

    public function test_a_finished_non_checkpoint_mission_moves_straight_to_starting_the_next_one(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission());
        $run->update(['status' => MissionRun::STATUS_COMPLETE, 'completed_at' => now()]);
        $this->makeMission('M02', 'People');

        $plan = app(ProgramPlanner::class)->plan($learner);

        $this->assertSame('start_next', $plan['today']['kind']);
        $this->assertSame(5, $plan['programDay']);
        $this->assertSame('M02', $plan['today']['nextMissionCode']);
    }

    public function test_a_checkpoint_is_offered_right_after_a_checkpoint_mission_finishes(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission('M06', 'Checkpoint Mission'));
        $run->update(['status' => MissionRun::STATUS_COMPLETE, 'completed_at' => now()]);

        $plan = app(ProgramPlanner::class)->plan($learner);

        $this->assertSame('checkpoint', $plan['today']['kind']);
        $this->assertTrue($plan['today']['checkpointAvailable']);
    }

    public function test_a_checkpoint_is_not_offered_after_a_non_checkpoint_mission(): void
    {
        $learner = User::factory()->create();
        // M01 is not in ProgramPlanner::CHECKPOINT_MISSIONS.
        $run = MissionRun::findOrStart($learner, $this->makeMission());
        $run->update(['status' => MissionRun::STATUS_COMPLETE, 'completed_at' => now()]);

        $plan = app(ProgramPlanner::class)->plan($learner);

        $this->assertFalse($plan['today']['checkpointAvailable']);
        $this->assertSame('start_next', $plan['today']['kind']);
    }

    public function test_a_checkpoint_already_taken_is_not_offered_again(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission('M12', 'Checkpoint Mission'));
        $run->update(['status' => MissionRun::STATUS_COMPLETE, 'completed_at' => now()]);

        PlacementTest::create([
            'learner_id' => $learner->id,
            'kind' => PlacementTest::KIND_CHECKPOINT,
            'checkpoint_mission_code' => 'M12',
            'level' => 'B1',
            'recognition_level' => 'B1',
            'detail' => [],
        ]);

        $plan = app(ProgramPlanner::class)->plan($learner);

        $this->assertFalse($plan['today']['checkpointAvailable']);
        $this->assertSame('start_next', $plan['today']['kind']);
    }

    public function test_a_checkpoint_never_blocks_starting_the_next_mission(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission('M18', 'Checkpoint Mission'));
        $run->update(['status' => MissionRun::STATUS_COMPLETE, 'completed_at' => now()]);
        $next = $this->makeMission('M02', 'Next Mission');

        // Starting the next mission directly — skipping the checkpoint
        // offer entirely — must work with no gate in the way.
        MissionRun::findOrStart($learner, $next);

        $this->assertSame('mission_day', app(ProgramPlanner::class)->plan($learner)['today']['kind']);
    }

    public function test_starting_the_next_mission_advances_the_program_day_and_mission_number(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission());
        $run->update(['status' => MissionRun::STATUS_COMPLETE, 'completed_at' => now()]);
        $next = $this->makeMission('M02', 'People');

        MissionRun::findOrStart($learner, $next);

        $plan = app(ProgramPlanner::class)->plan($learner);
        $this->assertSame('mission_day', $plan['today']['kind']);
        $this->assertSame('M02', $plan['today']['mission']->code);
        $this->assertSame(5, $plan['programDay']);
        $this->assertSame(2, $plan['missionNumber']);
    }

    public function test_days_behind_compares_progress_to_the_calendar(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission());
        $this->record($run, 'mission_brief');
        $this->record($run, 'vocabulary_builder');
        $learner->forceFill(['program_started_at' => now()->subDays(9)])->save();

        $plan = app(ProgramPlanner::class)->plan($learner->fresh());

        $this->assertSame(2, $plan['programDay']);
        $this->assertSame(10, $plan['calendarDay']);
        $this->assertSame(-8, $plan['daysDelta']);
    }

    public function test_the_missions_page_shows_today_and_the_program_day(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission());
        $this->record($run, 'mission_brief');
        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertSee('Day 1 of 96')
            ->assertSee('Today · Day 1 of My Daily Life')
            ->assertSee('Vocabulary Builder');
    }

    public function test_the_missions_page_offers_a_checkpoint_after_a_checkpoint_mission(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission('M06', 'Checkpoint Mission'));
        $run->update(['status' => MissionRun::STATUS_COMPLETE, 'completed_at' => now()]);
        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertSee('Want to hear how far')
            ->assertSee('Your voice, M06 in');
    }

    public function test_the_missions_page_offers_to_start_next_after_a_non_checkpoint_mission(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission());
        $run->update(['status' => MissionRun::STATUS_COMPLETE, 'completed_at' => now()]);
        $this->makeMission('M02', 'People');
        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertSee('Start a new mission');
    }

    public function test_the_program_guide_page_renders(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('program.guide'))->assertOk()->assertSee('The 100-day program')->assertSee('Day 4');
    }
}
