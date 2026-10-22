<?php

namespace Tests\Feature;

use App\Models\AiUsageLog;
use App\Models\ErrorLogItem;
use App\Models\Evidence;
use App\Models\ListeningLog;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\NotebookEntry;
use App\Models\User;
use App\Services\AdminInsights;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The numbers behind the admin page: active users, AI usage per learner,
 * who needs attention, and the step analytics.
 */
class AdminInsightsTest extends TestCase
{
    use RefreshDatabase;

    private function mission(): Mission
    {
        return Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'Outcome.',
            'phases' => [[
                'phase' => 'foundation',
                'steps' => [
                    ['key' => 'writing', 'label' => 'Writing', 'duration_minutes' => 10],
                    ['key' => 'reading', 'label' => 'Reading', 'duration_minutes' => 8],
                ],
            ]],
        ]);
    }

    /**
     * A run that started at $startedAt, with each step saved at its time.
     *
     * @param  array<string, Carbon>  $steps
     */
    private function runFor(User $learner, Mission $mission, Carbon $startedAt, array $steps = []): MissionRun
    {
        $run = MissionRun::create([
            'learner_id' => $learner->id,
            'mission_id' => $mission->id,
            'status' => MissionRun::STATUS_IN_PROGRESS,
            'started_at' => $startedAt,
        ]);
        $learner->forceFill(['program_started_at' => $startedAt])->save();

        foreach ($steps as $phase => $savedAt) {
            $evidence = Evidence::create(['mission_run_id' => $run->id, 'phase' => $phase, 'type' => Evidence::TYPE_TEXT, 'content_ref' => 'Done.']);
            $evidence->forceFill(['created_at' => $savedAt])->save();
        }

        return $run;
    }

    public function test_active_users_are_counted_by_when_they_last_opened_the_app(): void
    {
        User::factory()->create(['last_seen_at' => now()]);
        User::factory()->create(['last_seen_at' => now()->subDays(3)]);
        User::factory()->create(['last_seen_at' => now()->subDays(20)]);
        User::factory()->create(['last_seen_at' => now()->subDays(40)]);
        User::factory()->create(['last_seen_at' => null]);

        $counts = (new AdminInsights)->activeUserCounts();

        $this->assertSame(['today' => 1, 'week' => 2, 'month' => 3, 'total' => 5], $counts);
    }

    public function test_ai_usage_is_split_by_key_outcome_and_day_with_each_learners_share_of_today(): void
    {
        $heavy = User::factory()->create();
        $light = User::factory()->create();
        AiUsageLog::factory()->for($heavy)->count(3)->create();
        AiUsageLog::factory()->for($heavy)->ownKey()->create();
        AiUsageLog::factory()->for($heavy)->create(['created_at' => now()->subDays(5)]);
        AiUsageLog::factory()->for($heavy)->failed()->create();
        AiUsageLog::factory()->for($heavy)->create(['created_at' => now()->subDays(40)]);
        AiUsageLog::factory()->for($light)->create();
        AiUsageLog::factory()->for($light)->limited()->create();

        $rows = (new AdminInsights)->usersWithAiUsage()->keyBy(fn (array $row) => $row['user']->id);

        $this->assertSame(
            ['todayApp' => 3, 'todayOwn' => 1, 'monthApp' => 4, 'monthOwn' => 1, 'failed' => 1, 'limited' => 0, 'blocked' => 0, 'share' => 75],
            collect($rows[$heavy->id])->except('user')->all(),
        );
        $this->assertSame(1, $rows[$light->id]['limited']);
        $this->assertSame(25, $rows[$light->id]['share']);
        $this->assertSame($heavy->id, (new AdminInsights)->usersWithAiUsage()->first()['user']->id);
    }

    public function test_the_user_list_can_be_searched_by_name_or_email(): void
    {
        User::factory()->create(['name' => 'Sara', 'email' => 'sara@example.com']);
        User::factory()->create(['name' => 'Ali', 'email' => 'ali@example.com']);

        $this->assertSame(['Sara'], (new AdminInsights)->usersWithAiUsage('Sara')->map(fn (array $row) => $row['user']->name)->all());
        $this->assertSame(['Ali'], (new AdminInsights)->usersWithAiUsage('ali@')->map(fn (array $row) => $row['user']->name)->all());
    }

    public function test_a_struggling_learner_is_flagged_as_stuck_on_their_step(): void
    {
        $learner = User::factory()->create(['last_seen_at' => now()]);
        $run = $this->runFor($learner, $this->mission(), now());
        $run->forceFill(['struggle_signal_count' => MissionRun::STRUGGLE_SIGNAL_THRESHOLD])->save();

        $rows = (new AdminInsights)->attentionList();

        $this->assertSame(['Stuck: 3 hard misses on M01 › Writing'], $rows[0]['reasons']);
    }

    public function test_a_learner_who_keeps_coming_back_without_moving_on_is_flagged_as_stuck(): void
    {
        $learner = User::factory()->create(['last_seen_at' => now()]);
        $this->runFor($learner, $this->mission(), now()->subDays(5), ['writing' => now()->subDays(4)]);

        $reasons = (new AdminInsights)->attentionList()[0]['reasons'];

        $this->assertContains('Stuck: on M01 › Reading for 4 days', $reasons);
    }

    public function test_a_learner_who_has_not_come_back_is_not_flagged_as_stuck(): void
    {
        $learner = User::factory()->create(['last_seen_at' => now()->subDays(4)]);
        $this->runFor($learner, $this->mission(), now()->subDays(5), ['writing' => now()->subDays(4)]);

        $reasons = (new AdminInsights)->attentionList()[0]['reasons'] ?? [];

        $this->assertEmpty(array_filter($reasons, fn (string $reason) => str_starts_with($reason, 'Stuck')));
    }

    public function test_a_learner_behind_one_day_a_day_is_flagged(): void
    {
        $learner = User::factory()->create(['last_seen_at' => now()->subDays(9)]);
        $this->runFor($learner, $this->mission(), now()->subDays(10));

        $reasons = (new AdminInsights)->attentionList()[0]['reasons'];

        $this->assertContains('Behind: 10 program days behind one a day', $reasons);
    }

    public function test_a_learner_who_just_started_is_not_flagged(): void
    {
        $learner = User::factory()->create(['last_seen_at' => now()]);
        $this->runFor($learner, $this->mission(), now());

        $this->assertSame([], (new AdminInsights)->attentionList());
    }

    public function test_a_streak_that_just_broke_is_flagged(): void
    {
        $learner = User::factory()->create();
        $learner->forceFill(['program_started_at' => now()->subDays(6)])->save();

        foreach ([6, 5, 4] as $daysAgo) {
            ListeningLog::create(['learner_id' => $learner->id, 'listened_on' => $learner->localToday()->subDays($daysAgo), 'mission_code' => 'M01', 'day_number' => 1]);
        }

        $reasons = (new AdminInsights)->attentionList()[0]['reasons'];

        $this->assertContains('Streak broke: 3 days, last active 4 days ago', $reasons);
    }

    public function test_a_streak_that_broke_long_ago_is_not_flagged(): void
    {
        $learner = User::factory()->create();

        foreach ([12, 11, 10] as $daysAgo) {
            ListeningLog::create(['learner_id' => $learner->id, 'listened_on' => $learner->localToday()->subDays($daysAgo), 'mission_code' => 'M01', 'day_number' => 1]);
        }

        $this->assertSame([], (new AdminInsights)->attentionList());
    }

    public function test_an_unfinished_run_left_for_a_week_counts_against_its_current_step(): void
    {
        $mission = $this->mission();
        $this->runFor(User::factory()->create(), $mission, now()->subDays(10), ['writing' => now()->subDays(9)]);
        $this->runFor(User::factory()->create(), $mission, now()->subDays(2), ['writing' => now()->subDays(2)]);

        $this->assertSame([['mission' => 'M01', 'step' => 'Reading', 'learners' => 1]], (new AdminInsights)->abandonedSteps());
    }

    public function test_step_time_is_the_gap_since_the_previous_step_leaving_out_long_breaks(): void
    {
        $mission = $this->mission();
        $start = now()->subDay()->startOfHour();
        $this->runFor(User::factory()->create(), $mission, $start, ['writing' => $start->copy()->addMinutes(12), 'reading' => $start->copy()->addMinutes(18)]);
        $this->runFor(User::factory()->create(), $mission, $start, ['writing' => $start->copy()->addMinutes(20), 'reading' => $start->copy()->addHours(5)]);

        $times = collect((new AdminInsights)->stepTimes())->keyBy('step');

        $this->assertSame(['mission' => 'M01', 'step' => 'Writing', 'minutes' => 16, 'samples' => 2, 'plannedMinutes' => 10], $times['Writing']);
        $this->assertSame(['mission' => 'M01', 'step' => 'Reading', 'minutes' => 6, 'samples' => 1, 'plannedMinutes' => 8], $times['Reading']);
    }

    public function test_the_hardest_step_has_the_most_ai_checks_per_learner(): void
    {
        $this->mission();
        $first = User::factory()->create();
        $second = User::factory()->create();
        AiUsageLog::factory()->for($first)->count(6)->create(['mission_code' => 'M01', 'step_key' => 'writing']);
        AiUsageLog::factory()->for($second)->count(2)->create(['mission_code' => 'M01', 'step_key' => 'writing']);
        AiUsageLog::factory()->for($first)->count(2)->create(['mission_code' => 'M01', 'step_key' => 'reading']);

        $this->assertSame([
            ['mission' => 'M01', 'step' => 'Writing', 'requestsPerLearner' => 4.0, 'learners' => 2],
            ['mission' => 'M01', 'step' => 'Reading', 'requestsPerLearner' => 2.0, 'learners' => 1],
        ], (new AdminInsights)->hardestSteps());
    }

    public function test_common_mistakes_are_ranked_by_how_many_learners_made_them(): void
    {
        $mission = $this->mission();
        $firstRun = $this->runFor($first = User::factory()->create(), $mission, now());
        $secondRun = $this->runFor($second = User::factory()->create(), $mission, now());
        ErrorLogItem::create(['mission_run_id' => $firstRun->id, 'error' => 'He go.', 'correction' => 'He goes.', 'category' => 'third-person-s']);
        ErrorLogItem::create(['mission_run_id' => $secondRun->id, 'error' => 'She go.', 'correction' => 'She goes.', 'category' => 'third-person-s']);
        ErrorLogItem::create(['mission_run_id' => $firstRun->id, 'error' => 'I goed.', 'correction' => 'I went.', 'category' => 'past-simple']);
        ErrorLogItem::create(['mission_run_id' => $firstRun->id, 'error' => 'I eated.', 'correction' => 'I ate.', 'category' => 'past-simple']);
        NotebookEntry::factory()->for($first, 'learner')->create(['dedupe_key' => 'm:articles', 'label' => 'articles']);
        NotebookEntry::factory()->for($second, 'learner')->create(['dedupe_key' => 'm:articles', 'label' => 'articles']);

        $mistakes = (new AdminInsights)->commonMistakes();

        $this->assertSame([
            ['category' => 'third-person-s', 'learners' => 2, 'items' => 2],
            ['category' => 'past-simple', 'learners' => 1, 'items' => 2],
        ], $mistakes['categories']);
        $this->assertSame('articles', $mistakes['notebook'][0]['label']);
        $this->assertSame(2, $mistakes['notebook'][0]['learners']);
    }
}
