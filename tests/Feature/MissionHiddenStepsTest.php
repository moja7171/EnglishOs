<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MissionHiddenStepsTest extends TestCase
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
                        ['key' => 'listening', 'duration_minutes' => 18],
                    ],
                ],
                [
                    'phase' => 'build',
                    'label' => 'Day 2',
                    'steps' => [
                        ['key' => 'daily_listen_2', 'duration_minutes' => 7],
                        ['key' => 'story_sequence', 'duration_minutes' => 10],
                        ['key' => 'grammar_in_context', 'duration_minutes' => 15],
                    ],
                ],
                [
                    'phase' => 'challenge',
                    'label' => 'Day 3',
                    'steps' => [
                        ['key' => 'picture_description', 'duration_minutes' => 10],
                        'mission_result',
                    ],
                ],
            ],
        ]);
    }

    public function test_the_shipped_list_hides_the_four_light_package_steps(): void
    {
        $shipped = (static fn () => require base_path('config/missions.php'))();

        putenv('MISSIONS_HIDDEN_STEPS');
        unset($_ENV['MISSIONS_HIDDEN_STEPS'], $_SERVER['MISSIONS_HIDDEN_STEPS']);

        $withoutOverride = (static fn () => require base_path('config/missions.php'))();

        $this->assertSame([], $shipped['hidden_steps']);
        $this->assertSame(
            ['story_sequence', 'picture_description', 'daily_listen_2', 'daily_listen_3'],
            $withoutOverride['hidden_steps'],
        );

        putenv('MISSIONS_HIDDEN_STEPS=');
        $_ENV['MISSIONS_HIDDEN_STEPS'] = $_SERVER['MISSIONS_HIDDEN_STEPS'] = '';
    }

    public function test_every_step_shows_when_nothing_is_hidden(): void
    {
        $this->assertSame(
            ['mission_brief', 'listening', 'daily_listen_2', 'story_sequence', 'grammar_in_context', 'picture_description', 'mission_result'],
            $this->makeMission()->stepKeys(),
        );
    }

    public function test_hidden_steps_leave_the_steps_the_days_and_the_minutes(): void
    {
        config(['missions.hidden_steps' => ['story_sequence', 'picture_description', 'daily_listen_2']]);
        $mission = $this->makeMission()->fresh();

        $this->assertSame(['mission_brief', 'listening', 'grammar_in_context', 'mission_result'], $mission->stepKeys());
        $this->assertCount(3, $mission->phases);
        $this->assertSame(23 + 15 + 0, $mission->totalDurationMinutes());
        $this->assertNull($mission->phaseFor('story_sequence'));
    }

    public function test_hiding_never_changes_what_is_stored(): void
    {
        config(['missions.hidden_steps' => ['story_sequence', 'picture_description', 'daily_listen_2']]);
        $mission = $this->makeMission();

        $stored = json_decode((string) DB::table('missions')->where('id', $mission->id)->value('phases'), true);

        $this->assertSame('story_sequence', $stored[1]['steps'][1]['key']);

        $mission->update(['title' => 'Renamed']);

        $this->assertCount(3, json_decode((string) DB::table('missions')->where('id', $mission->id)->value('phases'), true)[1]['steps']);
    }

    public function test_a_day_is_finished_without_its_hidden_steps(): void
    {
        config(['missions.hidden_steps' => ['story_sequence', 'daily_listen_2']]);
        $mission = $this->makeMission()->fresh();
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $mission);

        foreach (['mission_brief', 'listening', 'grammar_in_context'] as $key) {
            Evidence::create(['mission_run_id' => $run->id, 'phase' => $key, 'type' => Evidence::TYPE_TEXT, 'content_ref' => 'done']);
        }

        $days = $run->fresh()->dayProgress();

        $this->assertTrue($days[0]['done']);
        $this->assertTrue($days[1]['done']);
        $this->assertSame('picture_description', $run->fresh()->currentStepKey());
    }
}
