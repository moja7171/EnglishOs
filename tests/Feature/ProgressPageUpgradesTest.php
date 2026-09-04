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

class ProgressPageUpgradesTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(User $learner, string $code = 'M01'): MissionRun
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

    public function test_the_calendar_defaults_to_the_current_month(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);

        Livewire::test('progress.index')
            ->assertSet('calendarMonth', (int) now()->month)
            ->assertSet('calendarYear', (int) now()->year);
    }

    public function test_previous_month_navigates_back_a_month(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);

        $expected = now()->subMonthNoOverflow();

        Livewire::test('progress.index')
            ->call('previousMonth')
            ->assertSet('calendarMonth', $expected->month)
            ->assertSet('calendarYear', $expected->year);
    }

    public function test_next_month_cannot_go_past_the_real_current_month(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);

        Livewire::test('progress.index')
            ->call('nextMonth')
            ->assertSet('calendarMonth', (int) now()->month)
            ->assertSet('calendarYear', (int) now()->year);
    }

    public function test_previous_then_next_month_returns_to_the_current_month(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);

        Livewire::test('progress.index')
            ->call('previousMonth')
            ->call('nextMonth')
            ->assertSet('calendarMonth', (int) now()->month)
            ->assertSet('calendarYear', (int) now()->year);
    }

    public function test_the_milestone_path_is_shown(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);

        Livewire::test('progress.index')->assertSee('to your 7-day badge');
    }

    public function test_the_skill_radar_shows_once_self_assessments_exist(): void
    {
        $learner = User::factory()->create();
        $run = $this->makeRun($learner);
        $run->update(['status' => MissionRun::STATUS_COMPLETE]);
        SelfAssessment::create(['mission_run_id' => $run->id, 'skill' => 'Speaking', 'before' => 2, 'after' => 4]);
        SelfAssessment::create(['mission_run_id' => $run->id, 'skill' => 'Writing', 'before' => 3, 'after' => 3]);
        SelfAssessment::create(['mission_run_id' => $run->id, 'skill' => 'Listening', 'before' => 3, 'after' => 4]);

        $this->actingAs($learner);

        Livewire::test('progress.index')->assertSee('Speaking');
    }

    public function test_no_skill_radar_section_before_any_self_assessment_exists(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);

        Livewire::test('progress.index')->assertDontSee('Your average self-assessment');
    }

    public function test_time_invested_reflects_recorded_step_durations(): void
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'Test Mission',
            'module' => 'Me',
            'outcome' => 'Outcome.',
            'phases' => [[
                'phase' => 'foundation',
                'steps' => [['key' => 'mission_brief', 'duration_minutes' => 5]],
            ]],
        ]);
        $run = MissionRun::findOrStart($learner, $mission);
        Evidence::create(['mission_run_id' => $run->id, 'phase' => 'mission_brief', 'type' => Evidence::TYPE_TEXT, 'content_ref' => 'x']);

        $this->actingAs($learner);

        Livewire::test('progress.index')->assertSee('5 min');
    }

    public function test_no_vocabulary_growth_chart_with_no_words_yet(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);

        Livewire::test('progress.index')->assertDontSee('Vocabulary growth');
    }

    public function test_vocabulary_growth_chart_shows_once_words_exist(): void
    {
        $learner = User::factory()->create();
        $learner->vocabularyWords()->create(['word' => 'commute', 'meaning' => 'to travel to work', 'next_review_at' => now()]);
        $this->actingAs($learner);

        Livewire::test('progress.index')->assertSee('Vocabulary growth');
    }

    public function test_the_error_trend_line_shows_when_a_category_recurs(): void
    {
        $learner = User::factory()->create();
        $run1 = $this->makeRun($learner, 'M01');
        $run2 = $this->makeRun($learner, 'M02');
        $run1->update(['status' => MissionRun::STATUS_COMPLETE, 'completed_at' => now()->subDay()]);
        $run2->update(['status' => MissionRun::STATUS_COMPLETE, 'completed_at' => now()]);

        ErrorLogItem::create(['mission_run_id' => $run1->id, 'error' => 'x', 'correction' => 'y', 'category' => 'third-person-s']);
        ErrorLogItem::create(['mission_run_id' => $run2->id, 'error' => 'x', 'correction' => 'y', 'category' => 'third-person-s']);

        $this->actingAs($learner);

        Livewire::test('progress.index')->assertSee('times total came from your last 2 missions');
    }
}
