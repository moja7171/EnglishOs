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

class AiConversation2StepTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(): MissionRun
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
                            'key' => 'ai_conversation_2',
                            'rounds' => ['Describe your typical weekday.', 'Compare weekday and weekend.'],
                            'final_prompt' => 'Speak for 3 minutes about your daily life.',
                            'requirements' => ['Present Simple', '5+ vocabulary expressions'],
                        ],
                        ['key' => 'active_recall'],
                    ],
                ],
            ],
        ]);

        $this->actingAs($learner);

        return MissionRun::findOrStart($learner, $mission);
    }

    public function test_full_flow_records_two_rounds_then_the_final_challenge_and_advances_the_run(): void
    {
        Storage::fake('local');
        $run = $this->makeRun();

        $this->mock(GroqClient::class, function ($mock) {
            $mock->shouldReceive('transcribe')
                ->times(3)
                ->andReturn('I get up and go to work.', 'Weekends are more relaxed.', 'I usually wake up at seven and I often cook dinner.');
        });
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->times(3)
                ->andReturn(
                    'What time do you leave for work?',
                    'What do you enjoy most about weekends?',
                    json_encode([
                        'requirements' => ['Present Simple' => true, '5+ vocabulary expressions' => false],
                        'note' => 'Good use of Present Simple, try more vocabulary next time.',
                    ])
                );
        });

        $component = Livewire::test('missions.steps.ai-conversation2', ['run' => $run]);

        $component->set('audioFile', UploadedFile::fake()->create('r1.webm', 100, 'audio/webm'))
            ->call('submitRoundAnswer')
            ->assertSet('roundIndex', 1);

        $component->set('audioFile', UploadedFile::fake()->create('r2.webm', 100, 'audio/webm'))
            ->call('submitRoundAnswer')
            ->assertSet('roundIndex', 2)
            ->assertSet('inFinalStage', true);

        $component->set('audioFile', UploadedFile::fake()->create('final.webm', 100, 'audio/webm'))
            ->call('submitFinalChallenge')
            ->assertSet('checklist', ['Present Simple' => true, '5+ vocabulary expressions' => false]);

        $component->call('finishConversation')
            ->assertRedirect(route('missions.show', $run->mission));

        $evidence = Evidence::where('phase', 'ai_conversation_2')->first();
        $this->assertNotNull($evidence);
        $content = json_decode($evidence->content_ref, true);
        $this->assertCount(2, $content['rounds']);
        $this->assertTrue($content['requirements']['Present Simple']);

        $this->assertSame('active_recall', $run->fresh()->currentStepKey());
    }

    public function test_final_challenge_grading_is_grounded_in_the_learners_own_selected_words(): void
    {
        Storage::fake('local');
        $run = $this->makeRun();

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'vocabulary_builder',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode([
                'selected_words' => ['wake up', 'have a shower', 'do the housework'],
                'examples' => [],
            ]),
        ]);

        $this->mock(GroqClient::class, function ($mock) {
            $mock->shouldReceive('transcribe')
                ->times(3)
                ->andReturn('answer 1', 'answer 2', 'final answer');
        });
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(2)->andReturn('a follow-up question');
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(function (array $messages, ?string $systemPrompt) {
                    return str_contains($systemPrompt, 'wake up')
                        && str_contains($systemPrompt, 'have a shower')
                        && str_contains($systemPrompt, 'do the housework')
                        && str_contains($systemPrompt, 'target vocabulary words');
                })
                ->andReturn(json_encode([
                    'requirements' => ['Present Simple' => true, '5+ vocabulary expressions' => true],
                    'note' => 'Great use of your target words.',
                ]));
        });

        $component = Livewire::test('missions.steps.ai-conversation2', ['run' => $run]);
        $component->set('audioFile', UploadedFile::fake()->create('r1.webm', 100, 'audio/webm'))->call('submitRoundAnswer');
        $component->set('audioFile', UploadedFile::fake()->create('r2.webm', 100, 'audio/webm'))->call('submitRoundAnswer');
        $component->set('audioFile', UploadedFile::fake()->create('final.webm', 100, 'audio/webm'))
            ->call('submitFinalChallenge')
            ->assertSet('checklist.5+ vocabulary expressions', true);
    }

    public function test_shows_its_hook_alongside_the_alpine_recorder_widget(): void
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
                    'steps' => [[
                        'key' => 'ai_conversation_2',
                        'hook' => "This one's harder on purpose.",
                        'rounds' => ['Describe your typical weekday.'],
                        'final_prompt' => 'Speak for 3 minutes.',
                        'requirements' => ['Present Simple'],
                    ]],
                ],
            ],
        ]);
        $this->actingAs($learner);
        $run = MissionRun::findOrStart($learner, $mission);

        Livewire::test('missions.steps.ai-conversation2', ['run' => $run])
            ->assertSee("This one's harder on purpose.");
    }

    public function test_the_round_prompt_is_wired_to_be_spoken_aloud(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.ai-conversation2', ['run' => $run])
            ->assertSeeHtml('data-text="Describe your typical weekday."')
            ->assertSeeHtml('wire:key="speak-round-0"');
    }

    public function test_the_final_challenge_prompt_is_wired_to_be_spoken_aloud(): void
    {
        Storage::fake('local');
        $run = $this->makeRun();

        $this->mock(GroqClient::class, function ($mock) {
            $mock->shouldReceive('transcribe')
                ->times(2)
                ->andReturn('I get up and go to work.', 'Weekends are more relaxed.');
        });
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(2)->andReturn('Reaction one.', 'Reaction two.');
        });

        $component = Livewire::test('missions.steps.ai-conversation2', ['run' => $run]);

        foreach (['recording-1.webm', 'recording-2.webm'] as $file) {
            $component
                ->set('audioFile', UploadedFile::fake()->create($file, 100, 'audio/webm'))
                ->call('submitRoundAnswer');
        }

        $component
            ->assertSeeHtml('data-text="Speak for 3 minutes about your daily life."')
            ->assertSeeHtml('wire:key="speak-final"');
    }

    public function test_read_only_review_never_wires_up_speech(): void
    {
        $run = $this->makeRun();

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'ai_conversation_2',
            'type' => Evidence::TYPE_TRANSCRIPT,
            'content_ref' => json_encode([
                'rounds' => [
                    ['prompt' => 'Describe your typical weekday.', 'answer' => 'Work then home.', 'followup' => 'Anything else?'],
                    ['prompt' => 'Compare weekday and weekend.', 'answer' => 'Weekends are calmer.', 'followup' => 'Nice.'],
                ],
                'final_transcript' => 'I usually wake up at seven.',
                'requirements' => ['Present Simple' => true, '5+ vocabulary expressions' => false],
                'note' => 'Good job.',
            ]),
        ]);

        Livewire::test('missions.steps.ai-conversation2', ['run' => $run, 'readOnly' => true])
            ->assertDontSeeHtml('data-text=');
    }
}
