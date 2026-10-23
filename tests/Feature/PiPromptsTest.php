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

    private function task(int $day, string $missionCode = 'M01', string $level = 'B1'): array
    {
        return app(PiPrompts::class)->dayTask(User::factory()->make(['cefr_level' => $level]), $missionCode, $day);
    }

    public function test_every_day_is_a_self_contained_short_session_with_a_plan(): void
    {
        $this->makeMission();

        foreach (range(1, 4) as $day) {
            $task = $this->task($day, level: 'A2+');

            $this->assertStringContainsString('at A2+ level', $task['prompt']);
            $this->assertStringContainsString('Be my warm, fun English buddy', $task['prompt']);
            $this->assertStringContainsString("Don't ask how I am. No small talk.", $task['prompt']);
            $this->assertStringContainsString('count only when I say one alone', $task['prompt']);
            $this->assertStringContainsString('reason in 8 words or less', $task['prompt']);
            $this->assertMatchesRegularExpression('/Round 1 of \d+/', $task['prompt']);
            $this->assertStringContainsString('"why?" = explain a bit more', $task['prompt']);
            $this->assertStringContainsString('"transcript" = get all my words in one message', $task['prompt']);
            $this->assertStringContainsString('My transcript: ONE message, never split.', $task['prompt']);
            $this->assertStringContainsString('do NOT fix my mistakes', $task['prompt']);
            $this->assertStringEndsWith('tell me the plan in one short sentence, then begin round 1.', $task['prompt']);
            $this->assertCount(3, $task['plan']);
        }
    }

    public function test_pronunciation_day_uses_the_shadow_lines_without_bold_markers(): void
    {
        $this->makeMission([
            ['key' => 'daily_listen', 'shadow_lines' => ['**Otherwise** I\'m **very grumpy**.', 'Another line.']],
        ]);

        $task = $this->task(1);

        $this->assertStringContainsString("1. Otherwise I'm very grumpy.\n2. Another line.", $task['prompt']);
        $this->assertStringContainsString('Round 1 of 3', $task['prompt']);
        $this->assertStringNotContainsString('**', $task['prompt']);
    }

    public function test_pronunciation_day_keeps_each_line_whole_in_the_transcript_and_is_judged_on_sounds_not_grammar(): void
    {
        $this->makeMission([
            ['key' => 'daily_listen', 'shadow_lines' => ['**Otherwise** I\'m **very grumpy**.']],
        ]);

        $task = $this->task(1);

        $this->assertStringContainsString('Round 1 — Line: (the whole line you said) / Me: (everything I said)', $task['prompt']);
        $this->assertTrue($task['isPronunciationDrill']);
        $this->assertStringContainsString('PRONUNCIATION drill', $task['feedback']);
        $this->assertStringContainsString('NEVER judge their grammar', $task['feedback']);
        $this->assertStringContainsString('"Otherwise I\'m very grumpy."', $task['feedback']);
    }

    public function test_the_other_days_are_judged_against_their_own_goal(): void
    {
        $this->makeMission([
            ['key' => 'listening', 'target_phrases' => [['phrase' => 'oversleep']], 'topic_summary' => 'Neil talks about mornings.'],
        ], [
            ['key' => 'grammar_in_context', 'focus' => 'Present Simple'],
        ]);

        $grammar = $this->task(2);
        $conversation = $this->task(3);
        $retell = $this->task(4);

        $this->assertStringContainsString('Round 1 — Q:', $grammar['prompt']);
        $this->assertFalse($grammar['isPronunciationDrill']);
        $this->assertStringContainsString('GRAMMAR practice: each answer should use "Present Simple"', $grammar['feedback']);
        $this->assertStringContainsString('CONVERSATION about "My Daily Life"', $conversation['feedback']);
        $this->assertStringContainsString('oversleep', $conversation['feedback']);
        $this->assertStringContainsString('RETELL of one story from "My Daily Life" (Neil talks about mornings.)', $retell['feedback']);
    }

    public function test_pronunciation_day_falls_back_to_the_example_sentences_of_the_new_words(): void
    {
        $this->makeMission([
            ['key' => 'vocabulary_builder_1', 'words' => [
                ['phrase' => 'get up', 'example' => 'He gets up at six every morning.'],
                ['phrase' => 'sleep in', 'example' => 'We always sleep in on Sundays.'],
            ]],
        ]);

        $task = $this->task(1);

        $this->assertStringContainsString("1. He gets up at six every morning.\n2. We always sleep in on Sundays.", $task['prompt']);
    }

    public function test_pronunciation_day_hands_over_at_most_six_lines(): void
    {
        $words = collect(range(1, 8))->map(fn (int $n) => ['phrase' => "word {$n}", 'example' => "Example number {$n}."])->all();
        $this->makeMission([['key' => 'vocabulary_builder_1', 'words' => $words]]);

        $task = $this->task(1);

        $this->assertStringContainsString('Example number 6.', $task['prompt']);
        $this->assertStringNotContainsString('Example number 7.', $task['prompt']);
    }

    public function test_pronunciation_day_without_any_content_still_builds_a_prompt_from_the_mission_title(): void
    {
        $this->makeMission();

        $task = $this->task(1);

        $this->assertStringContainsString('Make up 6 short, useful lines about "My Daily Life"', $task['prompt']);
    }

    public function test_grammar_day_is_built_from_the_missions_grammar_focus(): void
    {
        $this->makeMission([], [
            ['key' => 'grammar_in_context', 'focus' => 'Present Simple + Adverbs of Frequency'],
        ]);

        $task = $this->task(2);

        $this->assertStringContainsString('Present Simple + Adverbs of Frequency', $task['prompt']);
    }

    public function test_grammar_day_uses_the_roadmap_grammar_when_the_mission_is_not_seeded(): void
    {
        $task = $this->task(2, 'M05');

        $this->assertStringContainsString('Gerunds vs Infinitives', $task['prompt']);
    }

    public function test_conversation_day_suggests_the_interview_questions_and_the_taught_phrases(): void
    {
        $this->makeMission([
            ['key' => 'listening', 'target_phrases' => [['phrase' => 'get up'], ['phrase' => 'oversleep']]],
        ], [
            ['key' => 'video_shadowing', 'target_phrases' => [['phrase' => 'get up'], ['phrase' => 'pack a lunch']]],
            ['key' => 'ai_conversation_1', 'interview_questions' => ['What time do you usually wake up?']],
        ]);

        $task = $this->task(3);

        $this->assertStringContainsString('casual chat about "My Daily Life". My goal: I can talk about my daily routine.', $task['prompt']);
        $this->assertStringContainsString('- What time do you usually wake up?', $task['prompt']);
        $this->assertStringContainsString('try to use: get up, oversleep, pack a lunch.', $task['prompt']);
        $this->assertStringContainsString('In rounds 3 and 6, I ask you a question', $task['prompt']);
    }

    public function test_conversation_day_omits_the_hints_when_the_mission_has_none(): void
    {
        $this->makeMission();

        $task = $this->task(3);

        $this->assertStringNotContainsString('Questions you can ask me', $task['prompt']);
        $this->assertStringNotContainsString('try to use', $task['prompt']);
    }

    public function test_retell_day_offers_the_missions_stories_and_holds_the_fixes_until_the_end(): void
    {
        $this->makeMission([
            ['key' => 'listening', 'topic_summary' => 'Neil and Georgie talk about mornings. They also talk about breakfast.'],
        ]);

        $task = $this->task(4);

        $this->assertStringContainsString("- Neil and Georgie talk about mornings.\n", $task['prompt']);
        $this->assertStringNotContainsString('They also talk about breakfast.', $task['prompt']);
        $this->assertStringContainsString('no fixing while I talk', $task['prompt']);
        $this->assertStringContainsString('my 3 most useful fixes', $task['prompt']);
    }

    public function test_retell_day_without_stories_falls_back_to_the_mission_title(): void
    {
        $this->makeMission();

        $task = $this->task(4);

        $this->assertStringContainsString('what I learned about "My Daily Life"', $task['prompt']);
        $this->assertStringNotContainsString('Stories:', $task['prompt']);
    }
}
