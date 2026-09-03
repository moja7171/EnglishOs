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

        $this->actingAs($learner);

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
            $mock->shouldReceive('chat')->times(4)->andReturn(
                json_encode(['severity' => 'none', 'hint' => '']),
                'Do you always wake up at the same time?',
                json_encode(['severity' => 'none', 'hint' => '']),
                'How do you get to work?',
            );
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
                ->twice()
                ->withArgs(fn ($_messages, $systemPrompt) => str_contains($systemPrompt, 'extra warm and encouraging'))
                ->andReturn(
                    json_encode(['severity' => 'none', 'hint' => '']),
                    'Do you always wake up at the same time?',
                );
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

    public function test_the_current_question_has_a_read_aloud_button_the_learner_must_click(): void
    {
        $run = $this->makeRun(questionCount: 2);

        Livewire::test('missions.steps.ai-conversation1', ['run' => $run])
            ->assertSeeHtml('data-text="What time do you usually wake up?"')
            ->assertSee('Read aloud')
            // Never auto-speaks: no init/mount hook calls eosVoice on its own.
            ->assertDontSeeHtml('x-init');
    }

    public function test_a_mutual_friend_can_be_offered_the_question_to_practice_together(): void
    {
        $run = $this->makeRun(questionCount: 2);
        $friend = User::factory()->create(['name' => 'Priya']);
        $run->learner->follow($friend);
        $friend->acceptFollowRequest($run->learner);

        Livewire::test('missions.steps.ai-conversation1', ['run' => $run])
            ->assertSee('Priya')
            ->assertSeeHtml(route('friends.conversation', [
                'user' => $friend,
                'prefill' => 'Hey — want to help me practice this: "What time do you usually wake up?"',
            ]));
    }

    public function test_without_mutual_friends_it_points_to_the_friends_page_instead(): void
    {
        $run = $this->makeRun(questionCount: 2);

        Livewire::test('missions.steps.ai-conversation1', ['run' => $run])
            ->assertSee('No friends to practice with yet')
            ->assertSeeHtml(route('friends.index'));
    }

    public function test_the_completed_recap_offers_a_partner_session_with_a_mutual_friend(): void
    {
        Storage::fake('local');
        $run = $this->makeRun(questionCount: 1);
        $friend = User::factory()->create(['name' => 'Priya']);
        $run->learner->follow($friend);
        $friend->acceptFollowRequest($run->learner);

        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->once()->andReturn('I wake up at seven.'));
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->twice()->andReturn(
                json_encode(['severity' => 'none', 'hint' => '']),
                'Every day?',
            );
        });

        Livewire::test('missions.steps.ai-conversation1', ['run' => $run])
            ->set('audioFile', UploadedFile::fake()->create('answer1.webm', 100, 'audio/webm'))
            ->call('submitAnswer')
            ->assertSee('Do this with a partner')
            ->assertSee('Priya')
            ->assertSeeHtml(route('missions.practice-with-friend', ['mission' => $run->mission, 'step' => 'ai_conversation_1', 'friend' => $friend]));
    }

    public function test_an_off_topic_answer_is_not_advanced_and_shows_an_encouraging_hint(): void
    {
        Storage::fake('local');
        $run = $this->makeRun(questionCount: 2);

        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->once()->andReturn('Pizza is great.'));
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn(json_encode([
            'severity' => 'major',
            'hint' => "That's not quite about your morning — want to try again?",
        ])));

        Livewire::test('missions.steps.ai-conversation1', ['run' => $run])
            ->set('audioFile', UploadedFile::fake()->create('answer1.webm', 100, 'audio/webm'))
            ->call('submitAnswer')
            ->assertSet('round', 0) // never advances
            ->assertSet('turns', [])
            ->assertSee("That's not quite about your morning — want to try again?");

        $this->assertDatabaseCount('evidences', 0);
    }

    public function test_a_relevant_answer_is_never_flagged_for_grammar(): void
    {
        Storage::fake('local');
        $run = $this->makeRun(questionCount: 1);

        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->once()->andReturn('I wake up early I go work'));
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(fn ($_messages, $systemPrompt) => str_contains($systemPrompt, 'Do NOT judge grammar'))
                ->andReturn(json_encode(['severity' => 'none', 'hint' => '']))
                ->ordered();
            $mock->shouldReceive('chat')
                ->once()
                ->andReturn('What time?')
                ->ordered();
        });

        Livewire::test('missions.steps.ai-conversation1', ['run' => $run])
            ->set('audioFile', UploadedFile::fake()->create('answer1.webm', 100, 'audio/webm'))
            ->call('submitAnswer')
            ->assertSet('round', 1);
    }

    public function test_after_3_off_topic_attempts_an_example_can_be_revealed(): void
    {
        Storage::fake('local');
        $run = $this->makeRun(questionCount: 1);

        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->times(3)->andReturn('a', 'b', 'c'));
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->times(3)->andReturn(
            json_encode(['severity' => 'major', 'hint' => 'Try again.']),
            json_encode(['severity' => 'major', 'hint' => 'Try again.']),
            json_encode(['severity' => 'major', 'hint' => 'Try again.']),
        ));

        $component = Livewire::test('missions.steps.ai-conversation1', ['run' => $run]);

        foreach (['a.webm', 'b.webm', 'c.webm'] as $file) {
            $component->set('audioFile', UploadedFile::fake()->create($file, 100, 'audio/webm'))->call('submitAnswer');
        }

        $component->assertSet('offerReveal.0', true);

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn('I usually wake up at seven.'));

        $component->call('revealExample', 0)
            ->assertSet('exampleAnswer.0', 'I usually wake up at seven.')
            ->assertSet('checkAttempts.0', 0)
            ->assertSee('I usually wake up at seven.');
    }

    public function test_read_only_review_never_wires_up_speech(): void
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

        Livewire::test('missions.steps.ai-conversation1', ['run' => $run, 'readOnly' => true])
            ->assertDontSeeHtml('data-text=');
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
