<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MissionRunDayProgressTest extends TestCase
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
                ['phase' => 'foundation', 'label' => 'Day 1', 'steps' => [['key' => 'mission_brief'], ['key' => 'vocabulary_builder']]],
                ['phase' => 'build', 'label' => 'Day 2', 'steps' => [['key' => 'grammar_in_context']]],
                ['phase' => 'mission', 'label' => 'Day 3', 'steps' => [['key' => 'writing']]],
            ],
        ]);
    }

    public function test_a_fresh_run_has_only_the_first_day_current_and_the_rest_locked(): void
    {
        $run = MissionRun::findOrStart(User::factory()->create(), $this->makeMission());

        $days = $run->dayProgress();

        $this->assertTrue($days[0]['current']);
        $this->assertFalse($days[0]['done']);
        $this->assertNull($days[0]['startedAt']);

        $this->assertTrue($days[1]['locked']);
        $this->assertTrue($days[2]['locked']);

        $this->assertTrue($run->isAtTheStartOfAFreshDay());
    }

    public function test_partial_progress_in_a_day_marks_it_started_but_not_done(): void
    {
        $run = MissionRun::findOrStart(User::factory()->create(), $this->makeMission());

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'mission_brief',
            'type' => Evidence::TYPE_SCORE,
            'content_ref' => '3',
        ]);

        $days = $run->fresh()->dayProgress();

        $this->assertTrue($days[0]['current']);
        $this->assertFalse($days[0]['done']);
        $this->assertNotNull($days[0]['startedAt']);
        $this->assertNull($days[0]['completedAt']);

        $this->assertFalse($run->fresh()->isAtTheStartOfAFreshDay());
    }

    public function test_finishing_a_day_unlocks_the_next_one_as_current_with_a_fresh_start(): void
    {
        $run = MissionRun::findOrStart(User::factory()->create(), $this->makeMission());

        Evidence::create(['mission_run_id' => $run->id, 'phase' => 'mission_brief', 'type' => Evidence::TYPE_SCORE, 'content_ref' => '3']);
        Evidence::create(['mission_run_id' => $run->id, 'phase' => 'vocabulary_builder', 'type' => Evidence::TYPE_TEXT, 'content_ref' => '[]']);

        $run = $run->fresh();
        $days = $run->dayProgress();

        $this->assertTrue($days[0]['done']);
        $this->assertNotNull($days[0]['completedAt']);
        $this->assertFalse($days[0]['current']);

        $this->assertTrue($days[1]['current']);
        $this->assertFalse($days[1]['locked']);
        $this->assertTrue($days[2]['locked']);

        $this->assertTrue($run->isAtTheStartOfAFreshDay()); // fresh into Day 2
    }
}
