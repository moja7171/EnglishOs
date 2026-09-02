<?php

namespace Tests\Feature;

use App\Models\ErrorLogItem;
use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UserVocabularyAndProgressTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(User $learner, string $code): MissionRun
    {
        $mission = Mission::create([
            'code' => $code,
            'title' => 'Test Mission',
            'module' => 'Me',
            'outcome' => 'Outcome.',
            'phases' => [],
        ]);

        return MissionRun::findOrStart($learner, $mission);
    }

    private function seedVocabulary(MissionRun $run, array $words): void
    {
        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'vocabulary_builder',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode(['selected_words' => $words, 'examples' => []]),
        ]);
    }

    public function test_vocabulary_words_are_collected_across_every_mission_run(): void
    {
        $learner = User::factory()->create();
        $run1 = $this->makeRun($learner, 'M01');
        $run2 = $this->makeRun($learner, 'M02');

        $this->seedVocabulary($run1, ['wake up', 'get up']);
        $this->seedVocabulary($run2, ['commute', 'errand']);

        $this->assertSame(['wake up', 'get up', 'commute', 'errand'], $learner->vocabularyWordsSelected()->all());
    }

    public function test_duplicate_words_across_runs_are_only_counted_once(): void
    {
        $learner = User::factory()->create();
        $run1 = $this->makeRun($learner, 'M01');
        $run2 = $this->makeRun($learner, 'M02');

        $this->seedVocabulary($run1, ['commute', 'errand']);
        $this->seedVocabulary($run2, ['commute', 'chore']);

        $this->assertSame(3, $learner->vocabularyWordsSelected()->count());
    }

    public function test_vocabulary_is_scoped_to_the_learner(): void
    {
        $learner = User::factory()->create();
        $other = User::factory()->create();
        $run = $this->makeRun($other, 'M01');

        $this->seedVocabulary($run, ['commute']);

        $this->assertTrue($learner->vocabularyWordsSelected()->isEmpty());
    }

    public function test_missions_completed_count_only_counts_complete_runs(): void
    {
        $learner = User::factory()->create();
        $run1 = $this->makeRun($learner, 'M01');
        $this->makeRun($learner, 'M02');

        $run1->update(['status' => MissionRun::STATUS_COMPLETE]);

        $this->assertSame(1, $learner->missionsCompletedCount());
    }

    public function test_the_profile_progress_tab_surfaces_streak_missions_vocabulary_and_recurring_error(): void
    {
        $learner = User::factory()->create();
        $run1 = $this->makeRun($learner, 'M01');
        $run2 = $this->makeRun($learner, 'M02');
        $run1->update(['status' => MissionRun::STATUS_COMPLETE]);

        $this->seedVocabulary($run1, ['commute', 'errand']);

        ErrorLogItem::create(['mission_run_id' => $run1->id, 'error' => 'She go to work.', 'correction' => 'She goes to work.', 'category' => 'third-person-s']);
        ErrorLogItem::create(['mission_run_id' => $run2->id, 'error' => 'He walk fast.', 'correction' => 'He walks fast.', 'category' => 'third-person-s']);

        $this->actingAs($learner);

        Livewire::test('profile')
            ->assertSet('progressStats.missionsCompleted', 1)
            ->assertSet('progressStats.vocabularyCount', 2)
            ->assertSee('He walk fast.')
            ->assertSee('He walks fast.');
    }

    public function test_the_profile_progress_tab_has_a_friendly_empty_state_for_recurring_errors(): void
    {
        $learner = User::factory()->create();

        $this->actingAs($learner);

        Livewire::test('profile')
            ->assertSee('Complete 2+ missions and any pattern in your mistakes will show up here.');
    }

    public function test_setting_a_weekly_goal_saves_it(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);

        Livewire::test('profile')
            ->set('weeklyGoalDays', '5')
            ->call('updateWeeklyGoal')
            ->assertSet('weeklyGoalSaved', true);

        $this->assertSame(5, $learner->fresh()->weekly_goal_days);
    }

    public function test_clearing_the_weekly_goal_sets_it_back_to_no_goal(): void
    {
        $learner = User::factory()->create(['weekly_goal_days' => 5]);
        $this->actingAs($learner);

        Livewire::test('profile')
            ->set('weeklyGoalDays', '')
            ->call('updateWeeklyGoal');

        $this->assertNull($learner->fresh()->weekly_goal_days);
    }

    public function test_an_invalid_weekly_goal_value_is_ignored(): void
    {
        $learner = User::factory()->create(['weekly_goal_days' => 5]);
        $this->actingAs($learner);

        Livewire::test('profile')
            ->set('weeklyGoalDays', '99')
            ->call('updateWeeklyGoal')
            ->assertSet('weeklyGoalSaved', false);

        $this->assertSame(5, $learner->fresh()->weekly_goal_days);
    }

    public function test_the_progress_tab_shows_progress_toward_the_weekly_goal(): void
    {
        $learner = User::factory()->create(['weekly_goal_days' => 5]);
        $run = $this->makeRun($learner, 'M01');
        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'mission_brief',
            'type' => Evidence::TYPE_SCORE,
            'content_ref' => '3',
        ]);

        $this->actingAs($learner);

        Livewire::test('profile')
            ->assertSet('progressStats.activeDaysThisWeek', 1)
            ->assertSee('1 of 5 days this week');
    }

    public function test_the_progress_tab_renders_the_activity_calendar(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);

        Livewire::test('profile')
            ->assertSee('Last 12 weeks')
            ->assertSet('progressStats.calendar', fn ($calendar) => count($calendar) === 84);
    }
}
