<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LearningStreakTest extends TestCase
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
                ['phase' => 'foundation', 'label' => 'Day 1', 'steps' => [['key' => 'mission_brief']]],
            ],
        ]);
    }

    /**
     * Records one Evidence row on the given date — the exact timestamp
     * within the day never matters to the streak logic, only the date.
     */
    private function recordEvidenceOn(MissionRun $run, string $date): void
    {
        $evidence = Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'mission_brief',
            'type' => Evidence::TYPE_SCORE,
            'content_ref' => '3',
        ]);

        $evidence->forceFill(['created_at' => Carbon::parse($date)->setTime(12, 0)])->saveQuietly();
    }

    public function test_a_learner_with_no_evidence_has_no_streak(): void
    {
        $learner = User::factory()->create();

        $this->assertSame(0, $learner->currentStreak());
        $this->assertSame(0, $learner->longestStreak());
    }

    public function test_evidence_recorded_today_gives_a_streak_of_one(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission());

        $this->recordEvidenceOn($run, now()->toDateString());

        $this->assertSame(1, $learner->currentStreak());
        $this->assertSame(1, $learner->longestStreak());
    }

    public function test_consecutive_active_days_count_up_the_streak(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission());

        $this->recordEvidenceOn($run, now()->toDateString());
        $this->recordEvidenceOn($run, now()->subDay()->toDateString());
        $this->recordEvidenceOn($run, now()->subDays(2)->toDateString());

        $this->assertSame(3, $learner->currentStreak());
    }

    public function test_multiple_evidence_rows_on_the_same_day_only_count_once(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission());

        $this->recordEvidenceOn($run, now()->toDateString());
        $this->recordEvidenceOn($run, now()->toDateString());
        $this->recordEvidenceOn($run, now()->subDay()->toDateString());

        $this->assertSame(2, $learner->currentStreak());
    }

    public function test_a_single_skipped_day_is_forgiven_and_does_not_break_the_streak(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission());

        // Active today and 2 days ago, but NOT yesterday — one day off.
        $this->recordEvidenceOn($run, now()->toDateString());
        $this->recordEvidenceOn($run, now()->subDays(2)->toDateString());
        $this->recordEvidenceOn($run, now()->subDays(3)->toDateString());

        $this->assertSame(3, $learner->currentStreak());
    }

    public function test_two_or_more_skipped_days_in_a_row_breaks_the_streak(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission());

        $this->recordEvidenceOn($run, now()->toDateString());
        $this->recordEvidenceOn($run, now()->subDays(3)->toDateString());
        $this->recordEvidenceOn($run, now()->subDays(4)->toDateString());

        $this->assertSame(1, $learner->currentStreak());
    }

    public function test_the_streak_resets_to_zero_once_more_than_a_day_has_passed_since_the_last_activity(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission());

        $this->recordEvidenceOn($run, now()->subDays(3)->toDateString());
        $this->recordEvidenceOn($run, now()->subDays(4)->toDateString());

        $this->assertSame(0, $learner->currentStreak());
    }

    public function test_being_active_yesterday_but_not_yet_today_keeps_the_streak_alive(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission());

        $this->recordEvidenceOn($run, now()->subDay()->toDateString());
        $this->recordEvidenceOn($run, now()->subDays(2)->toDateString());

        $this->assertSame(2, $learner->currentStreak());
    }

    public function test_longest_streak_remembers_a_past_run_even_after_it_has_ended(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission());

        // A 4-day streak two weeks ago, long since broken...
        $this->recordEvidenceOn($run, now()->subDays(20)->toDateString());
        $this->recordEvidenceOn($run, now()->subDays(19)->toDateString());
        $this->recordEvidenceOn($run, now()->subDays(18)->toDateString());
        $this->recordEvidenceOn($run, now()->subDays(17)->toDateString());

        // ...then a single active day today.
        $this->recordEvidenceOn($run, now()->toDateString());

        $this->assertSame(1, $learner->currentStreak());
        $this->assertSame(4, $learner->longestStreak());
    }

    public function test_streaks_across_different_missions_still_combine_into_one_learner_streak(): void
    {
        $learner = User::factory()->create();

        $missionOne = $this->makeMission();
        $missionTwo = Mission::create([
            'code' => 'M02',
            'title' => 'Making Friends',
            'module' => 'Social',
            'outcome' => 'I can talk about friendship.',
            'phases' => [
                ['phase' => 'foundation', 'label' => 'Day 1', 'steps' => [['key' => 'mission_brief']]],
            ],
        ]);

        $this->recordEvidenceOn(MissionRun::findOrStart($learner, $missionOne), now()->toDateString());
        $this->recordEvidenceOn(MissionRun::findOrStart($learner, $missionTwo), now()->subDay()->toDateString());

        $this->assertSame(2, $learner->currentStreak());
    }

    public function test_one_learners_streak_never_counts_another_learners_evidence(): void
    {
        $learner = User::factory()->create();
        $otherLearner = User::factory()->create();
        $mission = $this->makeMission();

        $this->recordEvidenceOn(MissionRun::findOrStart($otherLearner, $mission), now()->toDateString());

        $this->assertSame(0, $learner->currentStreak());
    }
}
