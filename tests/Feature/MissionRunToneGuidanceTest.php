<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MissionRunToneGuidanceTest extends TestCase
{
    use RefreshDatabase;

    private function makeRunWithScore(?int $score): MissionRun
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [['phase' => 'foundation', 'steps' => [['key' => 'mission_brief']]]],
        ]);
        $run = MissionRun::findOrStart($learner, $mission);

        if ($score !== null) {
            Evidence::create([
                'mission_run_id' => $run->id,
                'phase' => 'mission_brief',
                'type' => Evidence::TYPE_SCORE,
                'content_ref' => (string) $score,
            ]);
        }

        return $run->fresh();
    }

    public function test_starting_confidence_is_null_before_mission_brief_is_answered(): void
    {
        $run = $this->makeRunWithScore(null);

        $this->assertNull($run->startingConfidence());
        $this->assertSame('', $run->aiToneGuidance());
    }

    public function test_low_score_produces_encouraging_guidance(): void
    {
        $run = $this->makeRunWithScore(1);

        $this->assertSame(1, $run->startingConfidence());
        $this->assertStringContainsString('extra warm and encouraging', $run->aiToneGuidance());
        $this->assertStringContainsString('simple', $run->aiToneGuidance());
    }

    public function test_middle_score_produces_no_special_guidance(): void
    {
        $run = $this->makeRunWithScore(3);

        $this->assertSame('', $run->aiToneGuidance());
    }

    public function test_high_score_produces_more_challenging_guidance(): void
    {
        $run = $this->makeRunWithScore(5);

        $this->assertStringContainsString('more challenging', $run->aiToneGuidance());
    }
}
