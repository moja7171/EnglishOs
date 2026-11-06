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

    public function test_every_day_is_a_self_contained_free_chat_with_no_rounds_or_transcript(): void
    {
        $this->makeMission();

        foreach (range(1, 4) as $day) {
            $task = $this->task($day, level: 'A2+');

            $this->assertStringContainsString('at A2+ level', $task['prompt']);
            $this->assertStringContainsString('like two friends', $task['prompt']);
            $this->assertStringContainsString('not a teacher or an interviewer', $task['prompt']);
            $this->assertStringContainsString('starting point is "My Daily Life"', $task['prompt']);
            $this->assertStringContainsString('The chat can go anywhere.', $task['prompt']);
            $this->assertStringContainsString('Ask one question at most in a turn. Sometimes ask none.', $task['prompt']);
            $this->assertStringContainsString('Never ask something I already answered', $task['prompt']);
            $this->assertStringContainsString('say "You can say:" and the corrected sentence', $task['prompt']);
            $this->assertStringContainsString('after about 10 minutes', $task['prompt']);
            $this->assertStringContainsString('this is enough for today', $task['prompt']);
            $this->assertStringContainsString('If I ask to keep going, keep going.', $task['prompt']);
            $this->assertStringContainsString('If I say "stop", end the chat.', $task['prompt']);
            $this->assertStringNotContainsString('Round', $task['prompt']);
            $this->assertStringNotContainsString('transcript', $task['prompt']);
            $this->assertCount(3, $task['plan']);
            $this->assertArrayNotHasKey('feedback', $task);
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

    public function test_the_days_differ_only_in_how_the_chat_opens(): void
    {
        $this->makeMission();

        $this->assertStringContainsString('Begin from my own daily life.', $this->task(1)['prompt']);
        $this->assertStringContainsString('very short story of your own', $this->task(2)['prompt']);
        $this->assertStringContainsString('agree or disagree', $this->task(3)['prompt']);
        $this->assertStringContainsString('something I have really done', $this->task(4)['prompt']);
    }

    public function test_the_missions_grammar_is_not_forced_into_the_chat(): void
    {
        $this->makeMission([], [
            ['key' => 'grammar_in_context', 'focus' => 'Present Simple + Adverbs of Frequency'],
        ]);

        foreach (range(1, 4) as $day) {
            $this->assertStringNotContainsString('Present Simple', $this->task($day)['prompt']);
        }
    }

    public function test_a_mission_not_seeded_yet_uses_its_roadmap_title(): void
    {
        $this->assertStringContainsString('starting point is "'.Mission::roadmapCatalog()['M05']['title'].'"', $this->task(1, 'M05')['prompt']);
    }

    public function test_the_same_seed_keeps_the_prompt_and_new_ones_bring_new_life_areas(): void
    {
        $this->makeMission();

        $this->assertSame($this->task(1, seed: 42)['prompt'], $this->task(1, seed: 42)['prompt']);

        $areas = collect(range(1, 20))->map(function (int $seed) {
            preg_match("/Today's side of it: (.+?)\\. After/", $this->task(1, seed: $seed)['prompt'], $match);

            return $match[1];
        });

        $this->assertGreaterThan(1, $areas->unique()->count());
    }
}
