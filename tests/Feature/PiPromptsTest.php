<?php

namespace Tests\Feature;

use App\Models\Mission;
use App\Models\User;
use App\Services\PiPrompts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PiPromptsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<array<string, mixed>>  $dayOneSteps
     * @param  list<array<string, mixed>>  $laterSteps
     */
    private function makeMission(array $dayOneSteps = [], array $laterSteps = []): Mission
    {
        return Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [
                ['phase' => 'foundation', 'steps' => $dayOneSteps],
                ['phase' => 'build', 'steps' => $laterSteps],
                ['phase' => 'practice', 'steps' => $laterSteps],
                ['phase' => 'challenge', 'steps' => []],
            ],
        ]);
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

    public function test_each_day_of_a_mission_has_its_own_fixed_chat(): void
    {
        $this->makeMission();

        $chats = collect(range(1, 4))
            ->map(fn (int $day) => app(PiPrompts::class)->dayTask('M01', $day)['instruction'])
            ->all();

        $this->assertSame([
            'Go to your Pronunciation Coach chat and try this:',
            'Go to your Teacher chat and try this:',
            'Go to your Language Partner chat and try this:',
            'Go to your Teacher chat and try this:',
        ], $chats);
    }

    public function test_pronunciation_day_uses_the_shadow_lines_without_bold_markers(): void
    {
        $this->makeMission([
            ['key' => 'daily_listen', 'shadow_lines' => ['**Otherwise** I\'m **very grumpy**.', 'Another line.']],
        ]);

        $task = app(PiPrompts::class)->dayTask('M01', 1);

        $this->assertStringContainsString("Otherwise I'm very grumpy. / Another line.", $task['prompt']);
        $this->assertStringNotContainsString('**', $task['prompt']);
    }

    public function test_pronunciation_day_falls_back_to_the_example_sentences_of_the_new_words(): void
    {
        $this->makeMission([
            ['key' => 'vocabulary_builder_1', 'words' => [
                ['phrase' => 'get up', 'example' => 'He gets up at six every morning.'],
                ['phrase' => 'sleep in', 'example' => 'We always sleep in on Sundays.'],
            ]],
        ]);

        $task = app(PiPrompts::class)->dayTask('M01', 1);

        $this->assertStringContainsString('He gets up at six every morning. / We always sleep in on Sundays.', $task['prompt']);
    }

    public function test_pronunciation_day_hands_over_at_most_six_lines(): void
    {
        $words = collect(range(1, 8))->map(fn (int $n) => ['phrase' => "word {$n}", 'example' => "Example number {$n}."])->all();
        $this->makeMission([['key' => 'vocabulary_builder_1', 'words' => $words]]);

        $task = app(PiPrompts::class)->dayTask('M01', 1);

        $this->assertStringContainsString('Example number 6.', $task['prompt']);
        $this->assertStringNotContainsString('Example number 7.', $task['prompt']);
    }

    public function test_pronunciation_day_without_any_content_still_builds_a_prompt_from_the_mission_title(): void
    {
        $this->makeMission();

        $task = app(PiPrompts::class)->dayTask('M01', 1);

        $this->assertStringContainsString('"My Daily Life"', $task['prompt']);
    }

    public function test_grammar_day_is_built_from_the_missions_grammar_focus(): void
    {
        $this->makeMission([], [
            ['key' => 'grammar_in_context', 'focus' => 'Present Simple + Adverbs of Frequency'],
        ]);

        $task = app(PiPrompts::class)->dayTask('M01', 2);

        $this->assertStringContainsString('Present Simple + Adverbs of Frequency', $task['prompt']);
    }

    public function test_grammar_day_uses_the_roadmap_grammar_when_the_mission_is_not_seeded(): void
    {
        $task = app(PiPrompts::class)->dayTask('M05', 2);

        $this->assertStringContainsString('Gerunds vs Infinitives', $task['prompt']);
    }

    public function test_conversation_day_uses_the_mission_title_and_grounds_target_phrases(): void
    {
        $this->makeMission([], [
            ['key' => 'ai_conversation_1', 'target_phrases' => [
                ['phrase' => 'get up', 'meaning' => 'to stand up and leave your bed'],
                ['phrase' => 'oversleep', 'meaning' => 'to sleep longer than you should'],
            ]],
        ]);

        $task = app(PiPrompts::class)->dayTask('M01', 3);

        $this->assertStringContainsString('My Daily Life', $task['prompt']);
        $this->assertStringContainsString('get up, oversleep', $task['prompt']);
        $this->assertStringNotContainsString('real challenge', $task['prompt']);
    }

    public function test_conversation_day_omits_the_phrase_hint_when_the_mission_has_none(): void
    {
        $this->makeMission();

        $task = app(PiPrompts::class)->dayTask('M01', 3);

        $this->assertStringNotContainsString('try working in', $task['prompt']);
    }

    public function test_retell_day_holds_the_fixes_until_the_end(): void
    {
        $this->makeMission();

        $task = app(PiPrompts::class)->dayTask('M01', 4);

        $this->assertStringContainsString('retell game about "My Daily Life"', $task['prompt']);
        $this->assertStringContainsString('no fixing while I talk', $task['prompt']);
        $this->assertStringContainsString('3 most useful fixes', $task['prompt']);
    }
}
