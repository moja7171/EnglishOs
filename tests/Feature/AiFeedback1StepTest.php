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

    /** @return array{strength: string, expression: string, correction: array{original: string, corrected: string, why: string, suggestion: string}, severity: string} */
    private function sampleAiResponse(string $severity = 'minor'): array
    {
        return [
            'strength' => 'نقطه قوت تو این بود که واضح جواب دادی.',
            'expression' => 'از عبارت wake up درست استفاده کردی.',
            'correction' => [
                'original' => 'I wake up at seven.',
                'corrected' => 'I usually wake up at seven.',
                'why' => 'برای توصیف عادت‌های روزمره باید از قید تکرار استفاده کنی.',
                'suggestion' => 'برای تمرین بیشتر، چند جمله با usually بنویس.',
            ],
            'severity' => $severity,
        ];
    }

    public function test_mount_does_not_call_gemini(): void
    {
        $run = $this->makeRunWithConversation();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldNotReceive('chat');
        });

        Livewire::test('missions.steps.ai-feedback1', ['run' => $run])
            ->assertSet('generated', false)
            ->assertSet('strength', null)
            ->assertSee('Get my feedback');
    }

    public function test_clicking_generate_calls_gemini_and_fills_the_report_card(): void
    {
        $run = $this->makeRunWithConversation();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode($this->sampleAiResponse()));
        });

        Livewire::test('missions.steps.ai-feedback1', ['run' => $run])
            ->call('generate')
            ->assertSet('generated', true)
            ->assertSet('strength', 'نقطه قوت تو این بود که واضح جواب دادی.')
            ->assertSet('correctionOriginal', 'I wake up at seven.')
            ->assertSet('correctionCorrected', 'I usually wake up at seven.')
            ->assertSet('error', null);
    }

    public function test_major_severity_records_a_struggle_signal_for_tone_adaptation(): void
    {
        $run = $this->makeRunWithConversation();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode($this->sampleAiResponse('major')));
        });

        Livewire::test('missions.steps.ai-feedback1', ['run' => $run])
            ->call('generate');

        $this->assertSame(1, $run->fresh()->struggle_signal_count);
    }

    public function test_minor_severity_does_not_record_a_struggle_signal(): void
    {
        $run = $this->makeRunWithConversation();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode($this->sampleAiResponse('minor')));
        });

        Livewire::test('missions.steps.ai-feedback1', ['run' => $run])
            ->call('generate');

        $this->assertSame(0, $run->fresh()->struggle_signal_count);
    }

    public function test_continue_saves_evidence_and_ai_feedback_and_advances_the_run(): void
    {
        $run = $this->makeRunWithConversation();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode($this->sampleAiResponse()));
        });

        Livewire::test('missions.steps.ai-feedback1', ['run' => $run])
            ->call('generate')
            ->call('continueMission')
            ->assertRedirect(route('missions.show', $run->mission));

        $this->assertDatabaseHas('evidences', ['mission_run_id' => $run->id, 'phase' => 'ai_feedback_1']);
        $this->assertDatabaseCount('ai_feedbacks', 1);

        $conversationEvidence = Evidence::where('phase', 'ai_conversation_1')->first();
        $this->assertSame($conversationEvidence->id, AIFeedback::first()->evidence_id);

        $savedEvidence = Evidence::where('phase', 'ai_feedback_1')->first();
        $saved = json_decode($savedEvidence->content_ref, true);
        $this->assertSame('I wake up at seven.', $saved['correction']['original']);
        $this->assertSame('I usually wake up at seven.', $saved['correction']['corrected']);

        $this->assertSame('writing', $run->fresh()->currentStepKey());
    }

    public function test_malformed_ai_response_shows_an_error(): void
    {
        $run = $this->makeRunWithConversation();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('not valid json');
        });

        Livewire::test('missions.steps.ai-feedback1', ['run' => $run])
            ->call('generate')
            ->assertSet('error', fn ($error) => str_contains($error, "Couldn't get feedback"))
            ->assertSet('strength', null)
            ->assertSet('generated', false);

        $this->assertDatabaseCount('evidences', 1); // only the conversation evidence from setup
    }

    public function test_read_only_mode_loads_saved_feedback_without_calling_gemini(): void
    {
        $run = $this->makeRunWithConversation();

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'ai_feedback_1',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode($this->sampleAiResponse()),
        ]);

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldNotReceive('chat');
        });

        Livewire::test('missions.steps.ai-feedback1', ['run' => $run, 'readOnly' => true])
            ->assertSet('generated', true)
            ->assertSet('strength', 'نقطه قوت تو این بود که واضح جواب دادی.')
            ->assertSet('correctionCorrected', 'I usually wake up at seven.')
            ->assertDontSee('Continue');
    }
}
