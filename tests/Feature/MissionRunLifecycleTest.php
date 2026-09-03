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

    public function test_all_learner_text_pulls_from_every_prose_bearing_phase(): void
    {
        $learner = User::factory()->create();
        $mission = $this->makeMission();
        $run = MissionRun::findOrStart($learner, $mission);

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'ai_conversation_1',
            'type' => Evidence::TYPE_TRANSCRIPT,
            'content_ref' => json_encode([['question' => 'Q', 'answer' => 'I wake up early.', 'followup' => 'F']]),
        ]);
        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'ai_conversation_2',
            'type' => Evidence::TYPE_TRANSCRIPT,
            'content_ref' => json_encode([
                'rounds' => [['prompt' => 'P', 'answer' => 'I commute by bus.', 'followup' => 'F']],
                'final_transcript' => 'I usually have breakfast at eight.',
            ]),
        ]);
        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'writing',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => 'A typical day starts with a shower.',
        ]);
        // Two Evidence rows for the same phase, text then audio — the
        // audio one (a plain storage URL, not JSON) must never be the one
        // picked for text extraction.
        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'activation',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode(['sentences' => ['I exercise in the evening.'], 'transcript' => 'I go to bed at eleven.']),
        ]);
        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'activation',
            'type' => Evidence::TYPE_AUDIO,
            'content_ref' => 'http://localhost/storage/missions/m01/evidence/speaking.webm',
        ]);
        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'active_recall',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode(['expressions' => ['have a shower'], 'listening_facts' => [], 'present_simple_sentences' => []]),
        ]);

        $text = $run->allLearnerText();

        $this->assertStringContainsString('I wake up early.', $text);
        $this->assertStringContainsString('I commute by bus.', $text);
        $this->assertStringContainsString('I usually have breakfast at eight.', $text);
        $this->assertStringContainsString('A typical day starts with a shower.', $text);
        $this->assertStringContainsString('I exercise in the evening.', $text);
        $this->assertStringContainsString('I go to bed at eleven.', $text);
        $this->assertStringContainsString('have a shower', $text);
        $this->assertStringNotContainsString('speaking.webm', $text);
    }

    public function test_all_learner_text_is_empty_for_a_fresh_run(): void
    {
        $learner = User::factory()->create();
        $mission = $this->makeMission();
        $run = MissionRun::findOrStart($learner, $mission);

        $this->assertSame('', $run->allLearnerText());
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

    public function test_previous_mission_finds_the_prior_seeded_mission(): void
    {
        $m01 = $this->makeMission();
        $m02 = Mission::create([
            'code' => 'M02',
            'title' => 'My Neighborhood',
            'module' => 'Me',
            'outcome' => 'I can describe where I live.',
            'phases' => [],
        ]);

        $this->assertTrue($m02->previousMission()->is($m01));
    }

    public function test_previous_mission_is_null_for_the_first_mission(): void
    {
        $this->assertNull($this->makeMission()->previousMission());
    }

    public function test_previous_mission_is_null_when_the_prior_slot_is_not_seeded(): void
    {
        $m03 = Mission::create([
            'code' => 'M03',
            'title' => 'My Free Time',
            'module' => 'Me',
            'outcome' => 'I can talk about hobbies.',
            'phases' => [],
        ]);

        $this->assertNull($m03->previousMission());
    }

    /**
     * Documents the current, deliberate bypass — see
     * project_testing_unlock_all_steps memory. This test itself must be
     * updated (not just left failing) when TESTING_UNLOCK_ALL_STEPS
     * reverts to false, since it's asserting the bypass, not the gate.
     */
    public function test_gating_mission_bypasses_entirely_while_testing_unlock_all_steps_is_on(): void
    {
        $this->assertTrue(MissionRun::TESTING_UNLOCK_ALL_STEPS);

        $learner = User::factory()->create();
        $m01 = $this->makeMission();
        $m02 = Mission::create([
            'code' => 'M02',
            'title' => 'My Neighborhood',
            'module' => 'Me',
            'outcome' => 'I can describe where I live.',
            'phases' => [],
        ]);

        $this->assertNull(MissionRun::gatingMission($learner, $m02));
    }

    public function test_progress_percent_is_zero_for_a_fresh_run(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission());

        $this->assertSame(0, $run->progressPercent());
    }

    public function test_progress_percent_reflects_recorded_evidence_out_of_all_steps(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission());

        // 5 total steps in this fixture — 2 recorded = 40%.
        Evidence::create(['mission_run_id' => $run->id, 'phase' => 'mission_brief', 'type' => Evidence::TYPE_TEXT, 'content_ref' => 'x']);
        Evidence::create(['mission_run_id' => $run->id, 'phase' => 'vocabulary_builder', 'type' => Evidence::TYPE_TEXT, 'content_ref' => 'x']);

        $this->assertSame(40, $run->progressPercent());
    }

    public function test_progress_percent_is_100_once_every_step_has_evidence(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission());

        foreach (['mission_brief', 'vocabulary_builder', 'listening', 'grammar_in_context', 'activation'] as $key) {
            Evidence::create(['mission_run_id' => $run->id, 'phase' => $key, 'type' => Evidence::TYPE_TEXT, 'content_ref' => 'x']);
        }

        $this->assertSame(100, $run->progressPercent());
    }
}
