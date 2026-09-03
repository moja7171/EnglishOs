<?php

namespace Tests\Feature;

use App\Models\ErrorLogItem;
use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\SelfAssessment;
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

    public function test_the_progress_page_surfaces_streak_missions_vocabulary_and_recurring_error(): void
    {
        $learner = User::factory()->create();
        $run1 = $this->makeRun($learner, 'M01');
        $run2 = $this->makeRun($learner, 'M02');
        $run1->update(['status' => MissionRun::STATUS_COMPLETE]);

        $this->seedVocabulary($run1, ['commute', 'errand']);

        ErrorLogItem::create(['mission_run_id' => $run1->id, 'error' => 'She go to work.', 'correction' => 'She goes to work.', 'category' => 'third-person-s']);
        ErrorLogItem::create(['mission_run_id' => $run2->id, 'error' => 'He walk fast.', 'correction' => 'He walks fast.', 'category' => 'third-person-s']);

        $this->actingAs($learner);

        Livewire::test('progress.index')
            ->assertSet('progressStats.missionsCompleted', 1)
            ->assertSet('progressStats.vocabularyCount', 2)
            ->assertSee('He walk fast.')
            ->assertSee('He walks fast.');
    }

    public function test_the_progress_page_has_a_friendly_empty_state_for_recurring_errors(): void
    {
        $learner = User::factory()->create();

        $this->actingAs($learner);

        Livewire::test('progress.index')
            ->assertSee('Complete 2+ missions and any pattern in your mistakes will show up here.');
    }

    public function test_setting_a_weekly_goal_saves_it(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);

        Livewire::test('progress.index')
            ->set('weeklyGoalDays', '5')
            ->call('updateWeeklyGoal')
            ->assertSet('weeklyGoalSaved', true);

        $this->assertSame(5, $learner->fresh()->weekly_goal_days);
    }

    public function test_clearing_the_weekly_goal_sets_it_back_to_no_goal(): void
    {
        $learner = User::factory()->create(['weekly_goal_days' => 5]);
        $this->actingAs($learner);

        Livewire::test('progress.index')
            ->set('weeklyGoalDays', '')
            ->call('updateWeeklyGoal');

        $this->assertNull($learner->fresh()->weekly_goal_days);
    }

    public function test_an_invalid_weekly_goal_value_is_ignored(): void
    {
        $learner = User::factory()->create(['weekly_goal_days' => 5]);
        $this->actingAs($learner);

        Livewire::test('progress.index')
            ->set('weeklyGoalDays', '99')
            ->call('updateWeeklyGoal')
            ->assertSet('weeklyGoalSaved', false);

        $this->assertSame(5, $learner->fresh()->weekly_goal_days);
    }

    public function test_the_progress_page_shows_progress_toward_the_weekly_goal(): void
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

        Livewire::test('progress.index')
            ->assertSet('progressStats.activeDaysThisWeek', 1)
            ->assertSee('1 of 5 days this week');
    }

    public function test_the_progress_page_renders_the_activity_calendar(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);

        Livewire::test('progress.index')
            ->assertSee('Last 12 weeks')
            ->assertSet('progressStats.calendar', fn ($calendar) => count($calendar) === 84);
    }

    public function test_the_profile_page_no_longer_has_a_progress_tab(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);

        Livewire::test('profile')
            ->assertDontSee('My progress')
            ->assertDontSee('Weekly goal');
    }

    public function test_memory_freshness_has_a_friendly_empty_state_with_nothing_reviewed_yet(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);

        // false = don't escape the needle — it's plain literal Blade text
        // (not passed through {{ }}), so the raw apostrophe in the
        // rendered HTML would never match an auto-escaped needle.
        Livewire::test('progress.index')
            ->assertSee("Once you've reviewed a word, speaking prompt, or grammar pattern at least once, its memory freshness shows up here.", false);
    }

    public function test_memory_freshness_averages_across_reviewed_words_and_shows_the_fastest_fading_first(): void
    {
        $learner = User::factory()->create();

        // Reviewed just now — fully fresh (100%).
        $learner->vocabularyWords()->create([
            'word' => 'commute', 'meaning' => 'to travel to work',
            'repetitions' => 3, 'interval_days' => 10,
            'last_reviewed_at' => now(), 'next_review_at' => now()->addDays(10),
        ]);

        // Reviewed 10 days ago with a 5-day interval — twice overdue, fully decayed (0%).
        $learner->vocabularyWords()->create([
            'word' => 'errand', 'meaning' => 'a short trip to do a task',
            'repetitions' => 2, 'interval_days' => 5,
            'last_reviewed_at' => now()->subDays(10), 'next_review_at' => now()->subDays(5),
        ]);

        // Never reviewed — excluded entirely from the average.
        $learner->vocabularyWords()->create([
            'word' => 'chore', 'meaning' => 'a routine task',
            'repetitions' => 0, 'interval_days' => 0, 'next_review_at' => now(),
        ]);

        $this->actingAs($learner);

        Livewire::test('progress.index')
            ->assertSet('averageFreshness', 50)
            ->assertSeeInOrder(['errand', 'commute']);
    }

    public function test_skill_averages_only_uses_after_scores_across_completed_runs(): void
    {
        $learner = User::factory()->create();
        $run1 = $this->makeRun($learner, 'M01');
        $run2 = $this->makeRun($learner, 'M02');

        SelfAssessment::create(['mission_run_id' => $run1->id, 'skill' => 'Speaking', 'before' => 2, 'after' => 4]);
        SelfAssessment::create(['mission_run_id' => $run2->id, 'skill' => 'Speaking', 'before' => 3, 'after' => 2]);

        $this->assertSame(['Speaking' => 3.0], $learner->skillAverages());
    }

    public function test_skill_averages_is_empty_with_no_self_assessments_yet(): void
    {
        $learner = User::factory()->create();

        $this->assertSame([], $learner->skillAverages());
    }

    public function test_total_practice_minutes_sums_only_recorded_steps(): void
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'Test Mission',
            'module' => 'Me',
            'outcome' => 'Outcome.',
            'phases' => [[
                'phase' => 'foundation',
                'steps' => [
                    ['key' => 'mission_brief', 'duration_minutes' => 5],
                    ['key' => 'listening', 'duration_minutes' => 20],
                ],
            ]],
        ]);
        $run = MissionRun::findOrStart($learner, $mission);
        Evidence::create(['mission_run_id' => $run->id, 'phase' => 'mission_brief', 'type' => Evidence::TYPE_TEXT, 'content_ref' => 'x']);

        $this->assertSame(5, $learner->totalPracticeMinutes());
    }

    public function test_vocabulary_growth_by_week_buckets_words_by_creation_week(): void
    {
        $learner = User::factory()->create();

        $thisWeek = $learner->vocabularyWords()->create(['word' => 'commute', 'meaning' => 'to travel to work', 'next_review_at' => now()]);
        $thisWeek->forceFill(['created_at' => now()])->saveQuietly();

        $lastWeek = $learner->vocabularyWords()->create(['word' => 'errand', 'meaning' => 'a short task', 'next_review_at' => now()]);
        $lastWeek->forceFill(['created_at' => now()->subWeek()])->saveQuietly();

        $growth = $learner->vocabularyGrowthByWeek(4);

        $this->assertCount(4, $growth);
        $this->assertSame(1, $growth[3]['count']); // most recent week
        $this->assertSame(1, $growth[2]['count']); // the week before
        $this->assertSame(0, $growth[0]['count']);
    }

    public function test_top_recurring_error_trend_is_null_when_nothing_recurs(): void
    {
        $learner = User::factory()->create();
        $run = $this->makeRun($learner, 'M01');
        ErrorLogItem::create(['mission_run_id' => $run->id, 'error' => 'x', 'correction' => 'y', 'category' => 'third-person-s']);

        $this->assertNull($learner->topRecurringErrorTrend());
    }

    public function test_top_recurring_error_trend_counts_recent_vs_total_occurrences(): void
    {
        $learner = User::factory()->create();
        $run1 = $this->makeRun($learner, 'M01');
        $run2 = $this->makeRun($learner, 'M02');
        $run1->update(['status' => MissionRun::STATUS_COMPLETE, 'completed_at' => now()->subDays(2)]);
        $run2->update(['status' => MissionRun::STATUS_COMPLETE, 'completed_at' => now()]);

        ErrorLogItem::create(['mission_run_id' => $run1->id, 'error' => 'She go.', 'correction' => 'She goes.', 'category' => 'third-person-s']);
        ErrorLogItem::create(['mission_run_id' => $run2->id, 'error' => 'He walk.', 'correction' => 'He walks.', 'category' => 'third-person-s']);

        $trend = $learner->topRecurringErrorTrend();

        $this->assertSame('third-person-s', $trend['category']);
        $this->assertSame(2, $trend['totalCount']);
        $this->assertSame(2, $trend['recentCount']); // both runs are within the 2 most recent
    }
}
