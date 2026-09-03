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
                ->times(6)
                ->andReturn(
                    json_encode(['severity' => 'none', 'hint' => '']),
                    'What time do you leave for work?',
                    json_encode(['severity' => 'none', 'hint' => '']),
                    'What do you enjoy most about weekends?',
                    json_encode(['severity' => 'none', 'hint' => '']),
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
            $mock->shouldReceive('chat')
                ->times(5)
                ->andReturn(
                    json_encode(['severity' => 'none', 'hint' => '']),
                    'a follow-up question',
                    json_encode(['severity' => 'none', 'hint' => '']),
                    'a follow-up question',
                    json_encode(['severity' => 'none', 'hint' => '']),
                )
                ->ordered();
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
                ]))
                ->ordered();
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

    public function test_the_round_prompt_has_a_read_aloud_button_the_learner_must_click(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.ai-conversation2', ['run' => $run])
            ->assertSeeHtml('data-text="Describe your typical weekday."')
            ->assertSee('Read aloud')
            ->assertDontSeeHtml('x-init');
    }

    public function test_the_checklist_recap_offers_a_partner_session_with_a_mutual_friend(): void
    {
        Storage::fake('local');
        $run = $this->makeRun();
        $friend = User::factory()->create(['name' => 'Priya']);
        $run->learner->follow($friend);
        $friend->acceptFollowRequest($run->learner);

        $this->mock(GroqClient::class, function ($mock) {
            $mock->shouldReceive('transcribe')
                ->times(3)
                ->andReturn('I get up and go to work.', 'Weekends are more relaxed.', 'I usually wake up at seven.');
        });
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->times(6)
                ->andReturn(
                    json_encode(['severity' => 'none', 'hint' => '']),
                    'What time do you leave for work?',
                    json_encode(['severity' => 'none', 'hint' => '']),
                    'What do you enjoy most about weekends?',
                    json_encode(['severity' => 'none', 'hint' => '']),
                    json_encode(['requirements' => ['Present Simple' => true, '5+ vocabulary expressions' => false], 'note' => 'Good job.'])
                );
        });

        $component = Livewire::test('missions.steps.ai-conversation2', ['run' => $run]);

        foreach (['r1.webm', 'r2.webm'] as $file) {
            $component->set('audioFile', UploadedFile::fake()->create($file, 100, 'audio/webm'))->call('submitRoundAnswer');
        }

        $component
            ->set('audioFile', UploadedFile::fake()->create('final.webm', 100, 'audio/webm'))
            ->call('submitFinalChallenge')
            ->assertSee('Do this with a partner')
            ->assertSee('Priya')
            ->assertSeeHtml(route('missions.practice-with-friend', ['mission' => $run->mission, 'step' => 'ai_conversation_2', 'friend' => $friend]));
    }

    public function test_the_final_challenge_prompt_has_a_read_aloud_button_the_learner_must_click(): void
    {
        Storage::fake('local');
        $run = $this->makeRun();

        $this->mock(GroqClient::class, function ($mock) {
            $mock->shouldReceive('transcribe')
                ->times(2)
                ->andReturn('I get up and go to work.', 'Weekends are more relaxed.');
        });
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(4)->andReturn(
                json_encode(['severity' => 'none', 'hint' => '']),
                'Reaction one.',
                json_encode(['severity' => 'none', 'hint' => '']),
                'Reaction two.',
            );
        });

        $component = Livewire::test('missions.steps.ai-conversation2', ['run' => $run]);

        foreach (['recording-1.webm', 'recording-2.webm'] as $file) {
            $component
                ->set('audioFile', UploadedFile::fake()->create($file, 100, 'audio/webm'))
                ->call('submitRoundAnswer');
        }

        $component
            ->assertSeeHtml('data-text="Speak for 3 minutes about your daily life."')
            ->assertSee('Read aloud')
            ->assertDontSeeHtml('x-init');
    }

    public function test_an_off_topic_round_answer_is_not_advanced_and_shows_an_encouraging_hint(): void
    {
        Storage::fake('local');
        $run = $this->makeRun();

        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->once()->andReturn('Pizza is great.'));
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn(json_encode([
            'severity' => 'major',
            'hint' => "That's not quite about your weekday — want to try again?",
        ])));

        Livewire::test('missions.steps.ai-conversation2', ['run' => $run])
            ->set('audioFile', UploadedFile::fake()->create('r1.webm', 100, 'audio/webm'))
            ->call('submitRoundAnswer')
            ->assertSet('roundIndex', 0)
            ->assertSet('turns', [])
            ->assertSee("That's not quite about your weekday — want to try again?");
    }

    public function test_an_off_topic_final_challenge_is_not_graded_and_shows_an_encouraging_hint(): void
    {
        Storage::fake('local');
        $run = $this->makeRun();

        $this->mock(GroqClient::class, function ($mock) {
            $mock->shouldReceive('transcribe')
                ->times(3)
                ->andReturn('I get up and go to work.', 'Weekends are more relaxed.', 'Pizza is my favorite food.');
        });
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(5)->andReturn(
                json_encode(['severity' => 'none', 'hint' => '']),
                'What time do you leave for work?',
                json_encode(['severity' => 'none', 'hint' => '']),
                'What do you enjoy most about weekends?',
                json_encode(['severity' => 'major', 'hint' => "That's not about your daily life — want to try again?"]),
            );
        });

        $component = Livewire::test('missions.steps.ai-conversation2', ['run' => $run]);

        foreach (['r1.webm', 'r2.webm'] as $file) {
            $component->set('audioFile', UploadedFile::fake()->create($file, 100, 'audio/webm'))->call('submitRoundAnswer');
        }

        $component
            ->set('audioFile', UploadedFile::fake()->create('final.webm', 100, 'audio/webm'))
            ->call('submitFinalChallenge')
            ->assertSet('checklist', null)
            ->assertSee("That's not about your daily life — want to try again?");
    }

    public function test_after_3_off_topic_final_challenge_attempts_an_example_can_be_revealed(): void
    {
        Storage::fake('local');
        $run = $this->makeRun();

        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->times(3)->andReturn('a', 'b', 'c'));
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->times(3)->andReturn(
            json_encode(['severity' => 'major', 'hint' => 'Try again.']),
            json_encode(['severity' => 'major', 'hint' => 'Try again.']),
            json_encode(['severity' => 'major', 'hint' => 'Try again.']),
        ));

        $component = Livewire::test('missions.steps.ai-conversation2', ['run' => $run]);
        $component->set('roundIndex', 2); // skip straight to the final stage

        foreach (['a.webm', 'b.webm', 'c.webm'] as $file) {
            $component->set('audioFile', UploadedFile::fake()->create($file, 100, 'audio/webm'))->call('submitFinalChallenge');
        }

        $component->assertSet('offerReveal.final', true);

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn('I usually wake up early and go to work.'));

        $component->call('revealExample', 'final')
            ->assertSet('exampleAnswer.final', 'I usually wake up early and go to work.')
            ->assertSet('checkAttempts.final', 0)
            ->assertSee('I usually wake up early and go to work.');
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
