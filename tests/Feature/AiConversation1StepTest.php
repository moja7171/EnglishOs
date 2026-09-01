<?php

namespace Tests\Feature;

use App\Models\Evidence;
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

class AiConversation1StepTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(int $questionCount = 2): MissionRun
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [
                [
                    'phase' => 'mission',
                    'steps' => [
                        [
                            'key' => 'ai_conversation_1',
                            'interview_questions' => array_slice([
                                'What time do you usually wake up?',
                                'What do you normally do in the morning?',
                                'How often do you exercise?',
                            ], 0, $questionCount),
                        ],
                        ['key' => 'ai_feedback_1'],
                    ],
                ],
            ],
        ]);

        return MissionRun::findOrStart($learner, $mission);
    }

    public function test_each_answer_is_transcribed_and_gets_one_ai_followup(): void
    {
        Storage::fake('local');
        $run = $this->makeRun(questionCount: 2);

        $this->mock(GroqClient::class, function ($mock) {
            $mock->shouldReceive('transcribe')->twice()->andReturn('I wake up at seven.', 'I have breakfast and go to work.');
        });
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->twice()->andReturn('Do you always wake up at the same time?', 'How do you get to work?');
        });

        $component = Livewire::test('missions.steps.ai-conversation1', ['run' => $run])
            ->set('audioFile', UploadedFile::fake()->create('answer1.webm', 100, 'audio/webm'))
            ->call('submitAnswer');

        $component->assertSet('round', 1);
        $this->assertCount(1, $component->get('turns'));
        $this->assertSame('I wake up at seven.', $component->get('turns')[0]['answer']);
        $this->assertSame('Do you always wake up at the same time?', $component->get('turns')[0]['followup']);

        $component
            ->set('audioFile', UploadedFile::fake()->create('answer2.webm', 100, 'audio/webm'))
            ->call('submitAnswer')
            ->assertSet('completed', true)
            ->assertSee('AI Conversation #1 complete')
            ->assertSee('How do you get to work?'); // the final follow-up must still be visible

        $evidence = Evidence::where('phase', 'ai_conversation_1')->first();
        $this->assertNotNull($evidence);
        $this->assertCount(2, json_decode($evidence->content_ref, true));

        // Evidence is already saved — currentStepKey has already advanced,
        // the recap is just a courtesy screen before navigating away.
        $this->assertSame('ai_feedback_1', $run->fresh()->currentStepKey());

        $component->call('proceed')->assertRedirect(route('missions.show', $run->mission));
    }

    public function test_a_failed_ai_call_shows_an_error_without_losing_progress(): void
    {
        Storage::fake('local');
        $run = $this->makeRun(questionCount: 2);

        $this->mock(GroqClient::class, function ($mock) {
            $mock->shouldReceive('transcribe')->once()->andThrow(new \RuntimeException('service unavailable'));
        });

        Livewire::test('missions.steps.ai-conversation1', ['run' => $run])
            ->set('audioFile', UploadedFile::fake()->create('answer1.webm', 100, 'audio/webm'))
            ->call('submitAnswer')
            ->assertSet('round', 0)
            ->assertSet('error', fn ($error) => str_contains($error, 'service unavailable'));

        $this->assertDatabaseCount('evidences', 0);
    }

    public function test_a_low_starting_confidence_makes_the_followup_prompt_warmer(): void
    {
        Storage::fake('local');
        $run = $this->makeRun(questionCount: 1);

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'mission_brief',
            'type' => Evidence::TYPE_SCORE,
            'content_ref' => '1',
        ]);

        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->once()->andReturn('I wake up at seven.'));
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->withArgs(fn ($_messages, $systemPrompt) => str_contains($systemPrompt, 'extra warm and encouraging'))
                ->once()
                ->andReturn('Do you always wake up at the same time?');
        });

        Livewire::test('missions.steps.ai-conversation1', ['run' => $run])
            ->set('audioFile', UploadedFile::fake()->create('answer1.webm', 100, 'audio/webm'))
            ->call('submitAnswer');
    }

    public function test_read_only_mode_reloads_the_transcript_without_calling_groq_or_gemini(): void
    {
        $run = $this->makeRun(questionCount: 2);

        $turns = [
            ['question' => 'What time do you usually wake up?', 'answer' => 'Seven.', 'followup' => 'Every day?'],
            ['question' => 'What do you normally do in the morning?', 'answer' => 'Breakfast.', 'followup' => 'What do you eat?'],
        ];
        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'ai_conversation_1',
            'type' => Evidence::TYPE_TRANSCRIPT,
            'content_ref' => json_encode($turns),
        ]);

        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldNotReceive('transcribe'));
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldNotReceive('chat'));

        Livewire::test('missions.steps.ai-conversation1', ['run' => $run, 'readOnly' => true])
            ->assertSet('turns', $turns)
            ->assertSet('currentQuestion', null) // no more questions -> record UI is hidden
            ->assertDontSee('Record');
    }

    public function test_shows_a_progress_bar_and_the_selected_vocabulary(): void
    {
        $run = $this->makeRun(questionCount: 2);

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'vocabulary_builder',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode(['selected_words' => ['wake up', 'have a shower']]),
        ]);

        Livewire::test('missions.steps.ai-conversation1', ['run' => $run])
            ->assertSee('Question 1 of 2')
            ->assertSeeHtml('h-1.5 w-full overflow-hidden rounded-full')
            ->assertSee('Words you picked')
            ->assertSee('wake up')
            ->assertSee('have a shower');
    }

    public function test_shows_the_shared_thinking_indicator_while_submitting(): void
    {
        $run = $this->makeRun(questionCount: 2);

        Livewire::test('missions.steps.ai-conversation1', ['run' => $run])
            ->assertSeeHtml('wire:target="submitAnswer"')
            ->assertSee('Transcribing and thinking of a follow-up…');
    }
}
