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

    private function task(int $day, string $missionCode = 'M01', string $level = 'B1', ?int $seed = 7): array
    {
        return app(PiPrompts::class)->dayTask(User::factory()->make(['cefr_level' => $level]), $missionCode, $day, $seed);
    }

    public function test_every_day_is_a_self_contained_short_chat_where_the_ai_and_the_learner_take_turns_asking(): void
    {
        $this->makeMission();

        foreach (range(1, 4) as $day) {
            $task = $this->task($day, level: 'A2+');

            $this->assertStringContainsString('at A2+ level', $task['prompt']);
            $this->assertStringContainsString('Be my warm, fun English buddy', $task['prompt']);
            $this->assertStringContainsString("Don't ask how I am. No small talk.", $task['prompt']);
            $this->assertStringContainsString('count only when I say one alone', $task['prompt']);
            $this->assertStringContainsString('reason in 8 words or less', $task['prompt']);
            $this->assertStringContainsString('Round 1 of 8', $task['prompt']);
            $this->assertStringContainsString('Odd rounds (1, 3, 5, 7): ask me ONE new, short question.', $task['prompt']);
            $this->assertStringContainsString('Even rounds (2, 4, 6, 8): say "Your turn!"', $task['prompt']);
            $this->assertStringContainsString('Never repeat a question you already asked me', $task['prompt']);
            $this->assertStringContainsString('every question is about "My Daily Life".', $task['prompt']);
            $this->assertStringContainsString('"transcript" = get all my words in one message', $task['prompt']);
            $this->assertStringContainsString('"Round 2 — Me: (my question)"', $task['prompt']);
            $this->assertStringContainsString('do NOT fix my mistakes', $task['prompt']);
            $this->assertStringEndsWith('then begin round 1.', $task['prompt']);
            $this->assertCount(3, $task['plan']);
            $this->assertStringContainsString('did they ask their 4 questions', $task['feedback']);
        }
    }

    public function test_no_day_uses_written_questions_or_the_missions_listening_video_or_reading(): void
    {
        $this->makeMission([
            ['key' => 'listening', 'target_phrases' => [['phrase' => 'oversleep']], 'topic_summary' => 'Neil talks about mornings.'],
            ['key' => 'daily_listen', 'shadow_lines' => ['Otherwise I\'m very grumpy.']],
        ], [
            ['key' => 'ai_conversation_1', 'interview_questions' => ['What time do you usually wake up?']],
        ]);

        foreach (range(1, 4) as $day) {
            $prompt = $this->task($day)['prompt'];

            $this->assertStringNotContainsString('What time do you usually wake up?', $prompt);
            $this->assertStringNotContainsString('oversleep', $prompt);
            $this->assertStringNotContainsString('Neil talks about mornings.', $prompt);
            $this->assertStringNotContainsString('grumpy', $prompt);
        }
    }

    public function test_each_day_gives_the_questions_its_own_flavor(): void
    {
        $this->makeMission();

        $this->assertStringContainsString('Ask about my own life and habits', $this->task(1)['prompt']);
        $this->assertStringContainsString('Ask what I think', $this->task(3)['prompt']);
        $this->assertStringContainsString('I ask what you think.', $this->task(3)['prompt']);
        $this->assertStringContainsString('Have you ever…?', $this->task(4)['prompt']);
        $this->assertStringContainsString('past simple', $this->task(4)['feedback']);
    }

    public function test_grammar_day_asks_questions_that_need_the_missions_grammar_focus(): void
    {
        $this->makeMission([], [
            ['key' => 'grammar_in_context', 'focus' => 'Present Simple + Adverbs of Frequency'],
        ]);

        $task = $this->task(2);

        $this->assertStringContainsString('natural answer needs "Present Simple + Adverbs of Frequency"', $task['prompt']);
        $this->assertStringContainsString('I try to use "Present Simple + Adverbs of Frequency" in my question too.', $task['prompt']);
        $this->assertStringContainsString('Each answer should use "Present Simple + Adverbs of Frequency"', $task['feedback']);
    }

    public function test_grammar_day_uses_the_roadmap_grammar_when_the_mission_is_not_seeded(): void
    {
        $task = $this->task(2, 'M05');

        $this->assertStringContainsString('Gerunds vs Infinitives', $task['prompt']);
    }

    public function test_the_same_seed_keeps_the_prompt_and_a_new_one_gives_four_fresh_angles(): void
    {
        $this->makeMission();

        $this->assertSame($this->task(1, seed: 42)['prompt'], $this->task(1, seed: 42)['prompt']);

        $angleSets = collect(range(1, 10))->map(function (int $seed) {
            preg_match('/one each, in this order: (.+)\.\n/', $this->task(1, seed: $seed)['prompt'], $match);

            return explode(', ', $match[1]);
        });

        $angleSets->each(fn (array $angles) => $this->assertCount(4, array_unique($angles)));
        $this->assertGreaterThan(1, $angleSets->unique()->count());
    }
}
