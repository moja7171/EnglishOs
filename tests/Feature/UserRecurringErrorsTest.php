<?php

namespace Tests\Feature;

use App\Models\ErrorLogItem;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserRecurringErrorsTest extends TestCase
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

    public function test_a_category_seen_in_only_one_mission_run_does_not_count_as_recurring(): void
    {
        $learner = User::factory()->create();
        $run = $this->makeRun($learner, 'M01');

        ErrorLogItem::create([
            'mission_run_id' => $run->id,
            'error' => 'She go to work.',
            'correction' => 'She goes to work.',
            'category' => 'third-person-s',
        ]);
        ErrorLogItem::create([
            'mission_run_id' => $run->id,
            'error' => 'He walk fast.',
            'correction' => 'He walks fast.',
            'category' => 'third-person-s',
        ]);

        $this->assertTrue($learner->recurringErrorCategories()->isEmpty());
        $this->assertNull($learner->topRecurringError());
    }

    public function test_a_category_seen_across_two_different_mission_runs_is_recurring(): void
    {
        $learner = User::factory()->create();
        $run1 = $this->makeRun($learner, 'M01');
        $run2 = $this->makeRun($learner, 'M02');

        ErrorLogItem::create([
            'mission_run_id' => $run1->id,
            'error' => 'She go to work.',
            'correction' => 'She goes to work.',
            'category' => 'third-person-s',
        ]);
        ErrorLogItem::create([
            'mission_run_id' => $run2->id,
            'error' => 'He walk fast.',
            'correction' => 'He walks fast.',
            'category' => 'third-person-s',
        ]);

        $this->assertSame(['third-person-s'], $learner->recurringErrorCategories()->all());

        $top = $learner->topRecurringError();
        $this->assertSame('He walk fast.', $top->error);
        $this->assertSame('He walks fast.', $top->correction);
    }

    public function test_recurrence_is_scoped_to_the_learner(): void
    {
        $learner = User::factory()->create();
        $other = User::factory()->create();
        $run1 = $this->makeRun($learner, 'M01');
        $run2 = $this->makeRun($other, 'M02');

        ErrorLogItem::create([
            'mission_run_id' => $run1->id,
            'error' => 'She go to work.',
            'correction' => 'She goes to work.',
            'category' => 'third-person-s',
        ]);
        ErrorLogItem::create([
            'mission_run_id' => $run2->id,
            'error' => 'He walk fast.',
            'correction' => 'He walks fast.',
            'category' => 'third-person-s',
        ]);

        $this->assertTrue($learner->recurringErrorCategories()->isEmpty());
    }

    public function test_a_null_category_never_counts_as_recurring(): void
    {
        $learner = User::factory()->create();
        $run1 = $this->makeRun($learner, 'M01');
        $run2 = $this->makeRun($learner, 'M02');

        ErrorLogItem::create(['mission_run_id' => $run1->id, 'error' => 'a', 'correction' => 'b', 'category' => null]);
        ErrorLogItem::create(['mission_run_id' => $run2->id, 'error' => 'c', 'correction' => 'd', 'category' => null]);

        $this->assertTrue($learner->recurringErrorCategories()->isEmpty());
        $this->assertNull($learner->topRecurringError());
    }

    public function test_the_most_frequently_recurring_category_is_returned_first(): void
    {
        $learner = User::factory()->create();
        $run1 = $this->makeRun($learner, 'M01');
        $run2 = $this->makeRun($learner, 'M02');
        $run3 = $this->makeRun($learner, 'M03');

        // "article-usage" recurs across all 3 runs, "third-person-s" only across 2.
        ErrorLogItem::create(['mission_run_id' => $run1->id, 'error' => 'a1', 'correction' => 'a1c', 'category' => 'article-usage']);
        ErrorLogItem::create(['mission_run_id' => $run1->id, 'error' => 't1', 'correction' => 't1c', 'category' => 'third-person-s']);
        ErrorLogItem::create(['mission_run_id' => $run2->id, 'error' => 'a2', 'correction' => 'a2c', 'category' => 'article-usage']);
        ErrorLogItem::create(['mission_run_id' => $run2->id, 'error' => 't2', 'correction' => 't2c', 'category' => 'third-person-s']);
        ErrorLogItem::create(['mission_run_id' => $run3->id, 'error' => 'a3', 'correction' => 'a3c', 'category' => 'article-usage']);

        $this->assertSame(['article-usage', 'third-person-s'], $learner->recurringErrorCategories()->all());
        $this->assertSame('a3', $learner->topRecurringError()->error);
    }
}
