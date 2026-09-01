<?php

namespace Tests\Feature;

use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MissionDurationTest extends TestCase
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
                    'label' => 'Day 1',
                    'steps' => [
                        ['key' => 'mission_brief', 'duration_minutes' => 5],
                        ['key' => 'vocabulary_builder', 'duration_minutes' => 15],
                    ],
                ],
                [
                    'phase' => 'build',
                    'label' => 'Day 2',
                    'steps' => [
                        ['key' => 'grammar_in_context', 'duration_minutes' => 12],
                        // No estimate authored yet — must not blow up or be
                        // counted as anything but 0.
                        ['key' => 'activation'],
                    ],
                ],
            ],
        ]);
    }

    public function test_step_duration_reads_the_authored_estimate(): void
    {
        $mission = $this->makeMission();

        $this->assertSame(5, $mission->stepDuration('mission_brief'));
        $this->assertSame(15, $mission->stepDuration('vocabulary_builder'));
    }

    public function test_step_duration_defaults_to_zero_when_not_authored(): void
    {
        $mission = $this->makeMission();

        $this->assertSame(0, $mission->stepDuration('activation'));
        $this->assertSame(0, $mission->stepDuration('not_a_real_step'));
    }

    public function test_total_duration_sums_every_step(): void
    {
        $mission = $this->makeMission();

        $this->assertSame(5 + 15 + 12 + 0, $mission->totalDurationMinutes());
    }

    public function test_format_duration_stays_in_minutes_under_an_hour(): void
    {
        $this->assertSame('8 min', Mission::formatDuration(8));
        $this->assertSame('59 min', Mission::formatDuration(59));
    }

    public function test_format_duration_switches_to_hours_and_minutes_at_an_hour(): void
    {
        $this->assertSame('1h', Mission::formatDuration(60));
        $this->assertSame('1h 5m', Mission::formatDuration(65));
        $this->assertSame('2h', Mission::formatDuration(120));
    }

    public function test_day_progress_reports_each_days_estimated_minutes(): void
    {
        $run = MissionRun::findOrStart(User::factory()->create(), $this->makeMission());

        $days = $run->dayProgress();

        $this->assertSame(20, $days[0]['estimatedMinutes']); // 5 + 15
        $this->assertSame(12, $days[1]['estimatedMinutes']); // 12 + 0
    }
}
