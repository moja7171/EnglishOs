<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MissionRunLifecycleTest extends TestCase
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
                    'steps' => [
                        ['key' => 'mission_brief'],
                        ['key' => 'vocabulary_builder'],
                        ['key' => 'listening'],
                    ],
                ],
                [
                    'phase' => 'build',
                    'steps' => [
                        ['key' => 'grammar_in_context'],
                        ['key' => 'activation'],
                    ],
                ],
            ],
        ]);
    }

    public function test_step_keys_are_flattened_in_order(): void
    {
        $mission = $this->makeMission();

        $this->assertSame(
            ['mission_brief', 'vocabulary_builder', 'listening', 'grammar_in_context', 'activation'],
            $mission->stepKeys()
        );
    }

    public function test_find_or_start_reuses_the_learners_in_progress_run(): void
    {
        $learner = User::factory()->create();
        $mission = $this->makeMission();

        $first = MissionRun::findOrStart($learner, $mission);
        $second = MissionRun::findOrStart($learner, $mission);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, MissionRun::count());
    }

    public function test_current_step_key_advances_only_after_evidence_is_recorded(): void
    {
        $learner = User::factory()->create();
        $mission = $this->makeMission();
        $run = MissionRun::findOrStart($learner, $mission);

        $this->assertSame('mission_brief', $run->currentStepKey());

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'mission_brief',
            'type' => Evidence::TYPE_SCORE,
            'content_ref' => '3',
        ]);

        $this->assertSame('vocabulary_builder', $run->fresh()->currentStepKey());
    }

    public function test_current_step_key_is_null_once_every_step_has_evidence(): void
    {
        $learner = User::factory()->create();
        $mission = $this->makeMission();
        $run = MissionRun::findOrStart($learner, $mission);

        foreach ($mission->stepKeys() as $key) {
            Evidence::create([
                'mission_run_id' => $run->id,
                'phase' => $key,
                'type' => Evidence::TYPE_TEXT,
                'content_ref' => 'done',
            ]);
        }

        $this->assertNull($run->fresh()->currentStepKey());
    }
}
