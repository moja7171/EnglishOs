<?php

namespace Tests\Feature;

use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use App\Services\PiPrompts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PiPromptsTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(array $steps): MissionRun
    {
        $learner = User::factory()->create(['cefr_level' => 'B1']);
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [['phase' => 'build', 'steps' => $steps]],
        ]);

        return MissionRun::findOrStart($learner, $mission);
    }

    public function test_onboarding_roles_interpolate_the_learners_real_level(): void
    {
        $learner = User::factory()->create(['cefr_level' => 'A2+']);

        $roles = app(PiPrompts::class)->onboardingRoles($learner);

        $this->assertSame(['teacher', 'partner', 'coach'], array_keys($roles));
        foreach ($roles as $role) {
            $this->assertArrayHasKey('label', $role);
            $this->assertArrayHasKey('when', $role);
            $this->assertStringContainsString('A2+', $role['setupMessage']);
        }
    }

    public function test_teacher_task_is_built_from_the_grammar_focus(): void
    {
        $run = $this->makeRun([
            ['key' => 'grammar_in_context', 'focus' => 'Present Simple + Adverbs of Frequency'],
        ]);

        $task = app(PiPrompts::class)->teacherTask($run->mission);

        $this->assertNotNull($task);
        $this->assertStringContainsString('Teacher chat', $task['instruction']);
        $this->assertStringContainsString('Present Simple + Adverbs of Frequency', $task['prompt']);
    }

    public function test_teacher_task_is_null_without_a_grammar_focus(): void
    {
        $run = $this->makeRun([['key' => 'grammar_in_context']]);

        $this->assertNull(app(PiPrompts::class)->teacherTask($run->mission));
    }

    public function test_coach_task_lists_the_phases_own_shadow_lines_without_bold_markers(): void
    {
        $run = $this->makeRun([
            ['key' => 'video_shadowing', 'shadow_lines' => ['**Otherwise** I\'m **very grumpy**.', 'Another line.']],
        ]);

        $task = app(PiPrompts::class)->coachTask($run, 'video_shadowing');

        $this->assertNotNull($task);
        $this->assertStringContainsString('Pronunciation Coach chat', $task['instruction']);
        $this->assertStringContainsString("Otherwise I'm very grumpy.", $task['prompt']);
        $this->assertStringNotContainsString('**', $task['prompt']);
    }

    public function test_coach_task_is_null_without_shadow_lines(): void
    {
        $run = $this->makeRun([['key' => 'video_shadowing']]);

        $this->assertNull(app(PiPrompts::class)->coachTask($run, 'video_shadowing'));
    }

    public function test_partner_task_uses_the_mission_title_and_grounds_target_phrases(): void
    {
        $run = $this->makeRun([
            ['key' => 'listening', 'target_phrases' => [
                ['phrase' => 'get up', 'meaning' => 'to stand up and leave your bed'],
                ['phrase' => 'oversleep', 'meaning' => 'to sleep longer than you should'],
            ]],
        ]);

        $task = app(PiPrompts::class)->partnerTask($run, 'listening');

        $this->assertNotNull($task);
        $this->assertStringContainsString('Language Partner chat', $task['instruction']);
        $this->assertStringContainsString('My Daily Life', $task['prompt']);
        $this->assertStringContainsString('get up, oversleep', $task['prompt']);
    }

    public function test_partner_task_omits_the_phrase_hint_when_the_step_has_none(): void
    {
        $run = $this->makeRun([['key' => 'ai_conversation_1']]);

        $task = app(PiPrompts::class)->partnerTask($run, 'ai_conversation_1');

        $this->assertNotNull($task);
        $this->assertStringNotContainsString('Try to use these words', $task['prompt']);
    }
}
