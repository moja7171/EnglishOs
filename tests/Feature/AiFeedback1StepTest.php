<?php

namespace Tests\Feature;

use App\Models\AIFeedback;
use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use App\Services\GeminiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AiFeedback1StepTest extends TestCase
{
    use RefreshDatabase;

    private function makeRunWithConversation(): MissionRun
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
                        ['key' => 'ai_conversation_1'],
                        ['key' => 'ai_feedback_1'],
                        ['key' => 'writing'],
                    ],
                ],
            ],
        ]);
        $run = MissionRun::findOrStart($learner, $mission);

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'ai_conversation_1',
            'type' => Evidence::TYPE_TRANSCRIPT,
            'content_ref' => json_encode([
                ['question' => 'What time do you wake up?', 'answer' => 'I wake up at seven.', 'followup' => 'Every day?'],
            ]),
        ]);

        return $run;
    }

    public function test_mount_generates_feedback_from_the_conversation(): void
    {
        $run = $this->makeRunWithConversation();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode([
                'strength' => 'You answered clearly and confidently.',
                'expression' => 'wake up',
                'correction' => 'Try using "usually" to describe routines.',
            ]));
        });

        Livewire::test('missions.steps.ai-feedback1', ['run' => $run])
            ->assertSet('strength', 'You answered clearly and confidently.')
            ->assertSet('expression', 'wake up')
            ->assertSet('error', null);
    }

    public function test_continue_saves_evidence_and_ai_feedback_and_advances_the_run(): void
    {
        $run = $this->makeRunWithConversation();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode([
                'strength' => 'Good clear answer.',
                'expression' => 'wake up',
                'correction' => 'Add more detail next time.',
            ]));
        });

        Livewire::test('missions.steps.ai-feedback1', ['run' => $run])
            ->call('continueMission')
            ->assertRedirect(route('missions.show', $run->mission));

        $this->assertDatabaseHas('evidences', ['mission_run_id' => $run->id, 'phase' => 'ai_feedback_1']);
        $this->assertDatabaseCount('ai_feedbacks', 1);

        $conversationEvidence = Evidence::where('phase', 'ai_conversation_1')->first();
        $this->assertSame($conversationEvidence->id, AIFeedback::first()->evidence_id);

        $this->assertSame('writing', $run->fresh()->currentStepKey());
    }

    public function test_malformed_ai_response_shows_an_error(): void
    {
        $run = $this->makeRunWithConversation();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('not valid json');
        });

        Livewire::test('missions.steps.ai-feedback1', ['run' => $run])
            ->assertSet('error', fn ($error) => str_contains($error, "Couldn't get feedback"))
            ->assertSet('strength', null);

        $this->assertDatabaseCount('evidences', 1); // only the conversation evidence from setup
    }
}
