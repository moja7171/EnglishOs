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
}
