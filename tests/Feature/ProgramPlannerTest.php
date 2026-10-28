<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\PiPracticeLog;
use App\Models\PlacementTest;
use App\Models\User;
use App\Models\VocabularyWord;
use App\Services\ProgramPlanner;
use Carbon\CarbonInterface;
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

    private function record(MissionRun $run, string $phase, ?CarbonInterface $at = null): void
    {
        $evidence = Evidence::create(['mission_run_id' => $run->id, 'phase' => $phase, 'type' => Evidence::TYPE_TEXT, 'content_ref' => 'x']);

        if ($at) {
            $evidence->forceFill(['created_at' => $at])->save();
        }
    }

    private function finishDayOne(MissionRun $run, ?CarbonInterface $at = null): void
    {
        $this->record($run, 'mission_brief', $at);
        $this->record($run, 'vocabulary_builder', $at);
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

    public function test_a_later_mission_opened_ahead_does_not_pull_today_past_the_earliest_open_one(): void
    {
        $learner = User::factory()->create();
        MissionRun::findOrStart($learner, $this->makeMission('M01'));
        $this->travel(1)->hours();
        MissionRun::findOrStart($learner, $this->makeMission('M10', 'Shopping'));

        $today = app(ProgramPlanner::class)->plan($learner)['today'];

        $this->assertSame('mission_day', $today['kind']);
        $this->assertSame('M01', $today['mission']->code);
    }

    public function test_mid_mission_today_is_the_current_day_with_its_steps(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission());
        $this->finishDayOne($run, now()->subDay());

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
        $this->finishDayOne($run, now()->subDay());
        $learner->forceFill(['program_started_at' => now()->subDays(9)])->save();

        $plan = app(ProgramPlanner::class)->plan($learner->fresh());

        $this->assertSame(2, $plan['programDay']);
        $this->assertSame(10, $plan['calendarDay']);
        $this->assertSame(-8, $plan['daysDelta']);
    }

    public function test_the_missions_page_shows_the_mission_its_day_and_today(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission());
        $this->record($run, 'mission_brief');
        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertSee('M01 · My Daily Life')
            ->assertSeeHtml('aria-label="Day 1, today"')
            ->assertSee('Vocabulary Builder')
            ->assertDontSee('Day 1 of 96');
    }

    public function test_the_progress_page_shows_the_program_day_and_how_far_behind_the_calendar(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission());
        $this->finishDayOne($run, now()->subDay());
        $learner->forceFill(['program_started_at' => now()->subDays(9)])->save();
        $this->actingAs($learner);

        Livewire::test('progress.index')
            ->assertSeeText('Day 2 of 96')
            ->assertSee('8 days behind');
    }

    public function test_todays_time_counts_the_steps_and_pi_then_shows_what_is_left(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission());
        $this->actingAs($learner);

        // Mission Brief 5 + Vocabulary Builder 15 + Pi 10.
        Livewire::test('missions.overview')
            ->assertSee('~30 min')
            ->assertDontSee('left');

        $this->record($run, 'mission_brief');

        Livewire::test('missions.overview')->assertSee('~25 min left');
    }

    public function test_the_main_button_sits_above_the_days_steps(): void
    {
        $learner = User::factory()->create();
        $mission = $this->makeMission();
        MissionRun::findOrStart($learner, $mission);
        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertSeeInOrder(['Lesson', 'Start', 'Mission Brief', 'Vocabulary Builder'])
            ->assertSeeHtml('href="'.route('missions.show', [$mission, 'mission_brief']).'"');
    }

    public function test_a_day_finished_today_stays_as_todays_day_with_the_next_one_offered(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission());
        $this->finishDayOne($run);

        $plan = app(ProgramPlanner::class)->plan($learner);
        $today = $plan['today'];

        $this->assertSame(1, $today['dayNumber']);
        $this->assertSame(1, $plan['programDay']);
        $this->assertSame('Foundation', $today['dayLabel']);
        $this->assertTrue($today['dayCompletedToday']);
        $this->assertSame([true, true], array_column($today['steps'], 'done'));
        $this->assertSame(
            ['dayNumber' => 2, 'label' => 'Build', 'minutes' => 22, 'stepKey' => 'grammar_in_context'],
            $today['upNext'],
        );
    }

    public function test_a_day_finished_yesterday_has_moved_on_to_the_next_day(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission());
        $this->finishDayOne($run, now()->subDay());

        $today = app(ProgramPlanner::class)->plan($learner)['today'];

        $this->assertSame(2, $today['dayNumber']);
        $this->assertFalse($today['dayCompletedToday']);
        $this->assertNull($today['upNext']);
    }

    public function test_starting_the_next_day_after_finishing_one_today_moves_today_to_it(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission());
        $this->finishDayOne($run);
        $this->record($run, 'grammar_in_context');

        $plan = app(ProgramPlanner::class)->plan($learner);

        $this->assertSame(2, $plan['today']['dayNumber']);
        $this->assertSame(2, $plan['programDay']);
        $this->assertFalse($plan['today']['dayCompletedToday']);
    }

    public function test_the_missions_page_celebrates_a_day_finished_today_and_offers_the_next(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission());
        $this->finishDayOne($run);
        $this->actingAs($learner);

        // Day 2: Grammar 12 + Final Challenge 10 + Pi 10.
        Livewire::test('missions.overview')
            ->assertSee('Steps done — one more: Pi')
            ->assertSee('Tomorrow · Day 2 · ~32 min')
            ->assertSeeHtml('href="'.route('missions.show', [$run->mission, 'grammar_in_context']).'"')
            ->assertDontSee('Day 1 done')
            ->assertDontSee('Continue');
    }

    public function test_the_missions_page_goes_back_to_continue_once_the_day_is_not_finished_today(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission());
        $this->finishDayOne($run, now()->subDay());
        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertSeeHtml('aria-label="Day 2, today"')
            ->assertSee('Start')
            ->assertDontSee('Tomorrow');
    }

    public function test_the_main_button_starts_the_day_with_daily_review_when_items_are_due(): void
    {
        $learner = User::factory()->create();
        MissionRun::findOrStart($learner, $this->makeMission());
        VocabularyWord::create(['learner_id' => $learner->id, 'word' => 'commute', 'meaning' => 'to travel to work', 'next_review_at' => now()->subMinute()]);
        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertSeeInOrder(['Review', 'Start'])
            ->assertSeeHtml('href="'.route('review.index').'"')
            ->assertDontSee('Continue');
    }

    public function test_with_the_days_steps_done_the_main_button_is_pi_and_the_next_day_waits(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission());
        $this->finishDayOne($run);
        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertSeeInOrder(['Steps done — one more: Pi', 'Tomorrow · Day 2', 'Daily talk'])
            ->assertSeeHtml('href="'.route('pi.practice', ['M01', 1]).'"');
    }

    public function test_once_pi_is_done_too_the_day_is_done_with_only_the_tomorrow_link(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission());
        $this->finishDayOne($run);
        PiPracticeLog::factory()->for($learner, 'learner')->create();
        $this->actingAs($learner);

        $html = Livewire::test('missions.overview')
            ->assertSee('Day 1 done — nice work.')
            ->assertDontSeeHtml('>Daily talk <svg')
            ->assertDontSee('Steps done')
            ->html();

        $this->assertSame(1, substr_count($html, 'Tomorrow · Day 2'));
    }

    public function test_a_step_that_finishes_its_day_sends_the_learner_home(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission());

        $this->record($run, 'mission_brief');
        $this->assertSame(route('missions.show', $run->mission), $run->urlAfterSavingAStep());

        $this->record($run, 'vocabulary_builder');
        $this->assertSame(route('home'), $run->urlAfterSavingAStep());
    }

    public function test_a_step_done_out_of_order_does_not_count_as_the_end_of_its_day(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission());

        $this->record($run, 'vocabulary_builder');

        $this->assertSame(route('missions.show', $run->mission), $run->urlAfterSavingAStep());
    }

    public function test_the_last_step_of_the_mission_stays_on_the_mission(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission());

        foreach ($run->mission->stepKeys() as $key) {
            $this->record($run, $key);
        }

        $this->assertSame(route('missions.show', $run->mission), $run->urlAfterSavingAStep());
    }

    public function test_the_missions_listening_and_pi_links_open_todays_day(): void
    {
        $learner = User::factory()->create();
        $mission = $this->makeMission();
        $run = MissionRun::findOrStart($learner, $mission);
        $this->finishDayOne($run, now()->subDay());
        $this->actingAs($learner);

        Livewire::test('missions.runner', ['mission' => $mission, 'step' => 'overview'])
            ->assertSeeHtml('href="'.route('listening.show', ['M01', 2]).'"')
            ->assertSeeHtml('href="'.route('pi.practice', ['M01', 2]).'"');
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
        $next = $this->makeMission('M02', 'People');
        $this->actingAs($learner);

        // Straight into the first step, not the day overview.
        Livewire::test('missions.overview')
            ->assertSee('Start a new mission')
            ->assertSeeHtml('href="'.route('missions.show', [$next, $next->stepKeys()[0]]).'"');
    }

    public function test_the_old_program_url_redirects_to_the_merged_guide(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('program.guide'))->assertRedirect(route('learner.guide'));
    }

    public function test_the_learner_guide_is_three_cards_and_short_questions(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('learner.guide'))
            ->assertOk()
            ->assertSeeInOrder(['اپ چطور کار می‌کنه', 'روز تو در ۴ قدم', '۳ عادت که جواب می‌ده', 'سؤال‌ها', 'چرا این‌همه تکرار؟'])
            ->assertDontSee('نقشه‌ی ۲۴ ماموریت')
            ->assertDontSee("Let's go");
    }

    public function test_the_learner_guide_links_to_the_listening_picks_page(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('learner.guide'))
            ->assertOk()
            ->assertSeeHtml('href="'.route('listening.show').'"')
            ->assertSee('بیرون از اپ چی گوش بدم؟');
    }
}
