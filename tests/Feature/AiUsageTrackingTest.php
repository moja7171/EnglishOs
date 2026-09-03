<?php

namespace Tests\Feature;

use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use App\Services\GeminiClient;
use App\Services\GroqClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A representative sample, not every single AI call site — see
 * App\Livewire\Concerns\TracksAiUsage and MissionRun's gemini_calls/
 * groq_calls columns. Grew out of a real 2026-09-03 end-to-end walkthrough
 * of M01 that made ~40 real AI calls with zero visibility into that
 * number beforehand.
 */
class AiUsageTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_sentence_checker_based_step_records_one_gemini_call_per_check(): void
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'Outcome.',
            'phases' => [[
                'phase' => 'foundation',
                'steps' => [[
                    'key' => 'vocabulary_builder',
                    'story' => [['heading' => 'Sleep', 'text' => 'I **wake up** early.']],
                    'story_words' => [['phrase' => 'wake up', 'meaning' => 'to stop sleeping']],
                ]],
            ]],
        ]);
        $this->actingAs($learner);
        $run = MissionRun::findOrStart($learner, $mission);

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn(json_encode(['severity' => 'none', 'hint' => ''])));

        Livewire::test('missions.steps.vocabulary-builder', ['run' => $run])
            ->call('toggleWord', 'wake up')
            ->set('examples.0', 'I wake up at seven every day.')
            ->call('checkOne', 0);

        $this->assertSame(1, $run->fresh()->gemini_calls);
        $this->assertSame(0, $run->fresh()->groq_calls);
    }

    public function test_the_shared_reveal_correction_path_also_records_a_gemini_call(): void
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'Outcome.',
            'phases' => [[
                'phase' => 'build',
                'steps' => [[
                    'key' => 'grammar_in_context',
                    'frequency_starters' => ['I usually'],
                ]],
            ]],
        ]);
        $this->actingAs($learner);
        $run = MissionRun::findOrStart($learner, $mission);

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(3)->andReturn(json_encode(['severity' => 'major', 'hint' => 'Try again.']));
            $mock->shouldReceive('chat')->once()->andReturn('I usually wake up early.');
        });

        $component = Livewire::test('missions.steps.grammar-in-context', ['run' => $run])
            ->call('startPractice')
            ->set('frequencySentences.0', 'bad fragment');

        $component->call('checkOne', 0);
        $component->call('checkOne', 0);
        $component->call('checkOne', 0);
        $component->call('revealCorrection', 0);

        // 3 checks + 1 reveal = 4 real Gemini calls total.
        $this->assertSame(4, $run->fresh()->gemini_calls);
    }

    public function test_activation_records_one_groq_call_and_one_gemini_call_for_the_recording(): void
    {
        Storage::fake('public');
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'Outcome.',
            'phases' => [[
                'phase' => 'build',
                'steps' => [['key' => 'activation', 'task' => 'Write 5 personal sentences.']],
            ]],
        ]);
        $this->actingAs($learner);
        $run = MissionRun::findOrStart($learner, $mission);

        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribeWithConfidence')->once()->andReturn([
            'text' => 'I wake up early.', 'duration' => 90.0, 'segments' => [],
        ]));
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(5)->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
            $mock->shouldReceive('chat')->once()->andReturn(json_encode(['highlight' => 'خوب.', 'tip' => 'ادامه بده.']));
        });

        Livewire::test('missions.steps.activation', ['run' => $run])
            ->set('sentences.0', 'I usually wake up at 7.')
            ->set('sentences.1', 'I have breakfast at 8.')
            ->set('sentences.2', 'I go to work by bus.')
            ->set('sentences.3', 'I exercise in the evening.')
            ->set('sentences.4', 'I go to bed at 11.')
            ->set('audioFile', UploadedFile::fake()->create('speaking.webm', 500, 'audio/webm'))
            ->call('save');

        $this->assertSame(1, $run->fresh()->groq_calls);
        // 5 sentence checks + 1 reflection = 6 real Gemini calls.
        $this->assertSame(6, $run->fresh()->gemini_calls);
    }

    public function test_mission_result_records_one_gemini_call_for_getresult(): void
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'Outcome.',
            'phases' => [[
                'phase' => 'mission',
                'steps' => [[
                    'key' => 'mission_result',
                    'skills' => ['Speaking'],
                    'reflection_questions' => [],
                ]],
            ]],
        ]);
        $this->actingAs($learner);
        $run = MissionRun::findOrStart($learner, $mission);

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn(json_encode([
            'status' => 'complete', 'reason' => 'Nice work.',
        ])));

        Livewire::test('missions.steps.mission-result', ['run' => $run])
            ->set('scores.Speaking.before', 2)->set('scores.Speaking.after', 4)
            ->call('getResult');

        $this->assertSame(1, $run->fresh()->gemini_calls);
    }
}
