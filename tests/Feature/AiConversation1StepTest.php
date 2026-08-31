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
            ->assertRedirect(route('missions.show', $run->mission));

        $evidence = Evidence::where('phase', 'ai_conversation_1')->first();
        $this->assertNotNull($evidence);
        $this->assertCount(2, json_decode($evidence->content_ref, true));

        $this->assertSame('ai_feedback_1', $run->fresh()->currentStepKey());
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
}
