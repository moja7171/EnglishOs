<?php

namespace Tests\Feature;

use App\Models\AIFeedback;
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

/**
 * Mission structure redesign, Epic E: the old standalone Activation step
 * (2 minutes of solo recording, with a Persian reflection) is now this
 * step's own warm-up round, and AI Feedback #1 (the report card on the
 * interview) is generated automatically as part of this step's own
 * completion recap — see ⚡ai-conversation1.blade.php. The warm-up used to
 * also require writing 5 personal sentences first; that writing sub-step
 * was removed, leaving just the recording.
 */
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
                            'warm_up_task' => 'Record 2 minutes of solo speaking about your daily life, without reading.',
                            'interview_questions' => array_slice([
                                'What time do you usually wake up?',
                                'What do you normally do in the morning?',
                                'How often do you exercise?',
                            ], 0, $questionCount),
                        ],
                        ['key' => 'writing'],
                    ],
                ],
            ],
        ]);

        $this->actingAs($learner);

        return MissionRun::findOrStart($learner, $mission);
    }

    /**
     * Mocks the exact call sequence finishWarmUp() makes (transcribe, then
     * reflect) and drives the component past the warm-up round into the
     * interview phase.
     */
    private function completeWarmUp($component, string $reflectionHighlight = 'خیلی روان صحبت کردی.', string $reflectionTip = 'ادامه بده.'): void
    {
        $this->mock(GroqClient::class, function ($mock) {
            $mock->shouldReceive('transcribeWithConfidence')->once()->andReturn([
                'text' => 'I usually wake up at seven and have breakfast.',
                'duration' => 90.0,
                'segments' => [
                    ['text' => 'I usually wake up at seven', 'confidence' => 'high'],
                    ['text' => 'and have breakfast.', 'confidence' => 'low'],
                ],
            ]);
        });
        $this->mock(GeminiClient::class, function ($mock) use ($reflectionHighlight, $reflectionTip) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode(['highlight' => $reflectionHighlight, 'tip' => $reflectionTip]));
        });

        $component->set('warmUpAudioFile', UploadedFile::fake()->create('speaking.webm', 500, 'audio/webm'))
            ->call('finishWarmUp');
    }

    // -----------------------------------------------------------------
    // Warm-up round (was Activation)
    // -----------------------------------------------------------------

    public function test_warm_up_recording_is_required(): void
    {
        Storage::fake('public');
        $run = $this->makeRun();

        $component = Livewire::test('missions.steps.ai-conversation1', ['run' => $run]);
        $component->call('finishWarmUp')->assertHasErrors(['warmUpAudioFile']);

        $this->assertDatabaseCount('evidences', 0);
        $component->assertSet('warmUpDone', false);
    }

    public function test_the_warm_up_shows_the_selected_vocabulary_as_a_speaking_reminder(): void
    {
        $run = $this->makeRun();

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'vocabulary_builder_1',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode(['selected_words' => ['wake up', 'have a shower', 'go to bed']]),
        ]);

        Livewire::test('missions.steps.ai-conversation1', ['run' => $run])
            ->assertSee('Words you picked — try to use some while you speak')
            ->assertSee('wake up');
    }

    public function test_finishing_the_warm_up_moves_into_the_interview_without_saving_evidence_yet(): void
    {
        Storage::fake('public');
        $run = $this->makeRun(questionCount: 2);

        $component = Livewire::test('missions.steps.ai-conversation1', ['run' => $run]);
        $this->completeWarmUp($component);

        $component->assertSet('warmUpDone', true)
            ->assertSet('completed', false)
            ->assertSet('transcript', 'I usually wake up at seven and have breakfast.')
            ->assertSee('Question 1 of 2');

        $this->assertDatabaseCount('evidences', 0);
        $this->assertSame('ai_conversation_1', $run->fresh()->currentStepKey());
    }

    public function test_a_failed_warm_up_transcription_does_not_block_moving_into_the_interview(): void
    {
        Storage::fake('public');
        $run = $this->makeRun();

        $this->mock(GroqClient::class, function ($mock) {
            $mock->shouldReceive('transcribeWithConfidence')->once()->andThrow(new \RuntimeException('Groq is down.'));
        });
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldNotReceive('chat'));

        $component = Livewire::test('missions.steps.ai-conversation1', ['run' => $run]);
        $component->set('warmUpAudioFile', UploadedFile::fake()->create('speaking.webm', 500, 'audio/webm'))
            ->call('finishWarmUp')
            ->assertSet('warmUpDone', true)
            ->assertSet('transcript', null)
            ->assertSet('reflection', null);
    }

    public function test_a_long_enough_warm_up_recording_gets_a_real_pace_signal_in_the_reflection_prompt(): void
    {
        Storage::fake('public');
        $run = $this->makeRun();

        $this->mock(GroqClient::class, function ($mock) {
            $mock->shouldReceive('transcribeWithConfidence')->once()->andReturn([
                'text' => 'I usually um wake up at seven and uh have breakfast then I go to work by bus every day',
                'duration' => 60.0,
                'segments' => [],
            ]);
        });
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(fn (array $messages, ?string $systemPrompt) => str_contains($systemPrompt, '20 words per minute')
                    && str_contains($systemPrompt, '60 seconds')
                    && str_contains($systemPrompt, 'with 2 filler words'))
                ->andReturn(json_encode(['highlight' => 'خوب بود.', 'tip' => 'ادامه بده.']));
        });

        $component = Livewire::test('missions.steps.ai-conversation1', ['run' => $run]);
        $component->set('warmUpAudioFile', UploadedFile::fake()->create('speaking.webm', 500, 'audio/webm'))
            ->call('finishWarmUp');
    }

    public function test_the_same_warm_up_questions_from_mission_brief_are_shown_before_recording(): void
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [
                ['phase' => 'foundation', 'steps' => [['key' => 'mission_brief', 'warm_up_questions' => ['What time do you usually wake up?']]]],
                ['phase' => 'mission', 'steps' => [['key' => 'ai_conversation_1', 'interview_questions' => ['Q1']]]],
            ],
        ]);
        $this->actingAs($learner);
        $run = MissionRun::findOrStart($learner, $mission);

        Livewire::test('missions.steps.ai-conversation1', ['run' => $run])
            ->assertSee('Same questions as Day 1')
            ->assertSee('What time do you usually wake up?');
    }

    public function test_finishing_the_warm_up_dispatches_a_clear_draft_event(): void
    {
        Storage::fake('public');
        $run = $this->makeRun();

        $component = Livewire::test('missions.steps.ai-conversation1', ['run' => $run]);
        $this->completeWarmUp($component);

        $component->assertDispatched('clear-draft', prefix: "eos-draft:{$run->id}:ai_conversation_1:");
    }

    // -----------------------------------------------------------------
    // Interview round (unchanged AI Conversation #1 behavior)
    // -----------------------------------------------------------------

    public function test_each_answer_is_transcribed_and_gets_one_ai_followup_then_finishes_with_feedback(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $run = $this->makeRun(questionCount: 2);

        $component = Livewire::test('missions.steps.ai-conversation1', ['run' => $run]);
        $this->completeWarmUp($component);

        $this->mock(GroqClient::class, function ($mock) {
            $mock->shouldReceive('transcribe')->twice()->andReturn('I wake up at seven.', 'I have breakfast and go to work.');
        });
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->andReturn(
                json_encode(['severity' => 'none', 'hint' => '']),
                'Do you always wake up at the same time?',
                json_encode(['severity' => 'none', 'hint' => '']),
                'How do you get to work?',
                json_encode([
                    'strength' => 'نقطه قوت تو این بود که واضح جواب دادی.',
                    'expression' => 'از عبارت wake up درست استفاده کردی.',
                    'correction' => [
                        'original' => 'I wake up at seven.',
                        'corrected' => 'I usually wake up at seven.',
                        'why' => 'برای توصیف عادت‌های روزمره باید از قید تکرار استفاده کنی.',
                        'suggestion' => 'برای تمرین بیشتر، چند جمله با usually بنویس.',
                    ],
                    'severity' => 'minor',
                ]),
            );
        });

        $component
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
            ->assertSee('Talk It Out complete')
            ->assertSee('How do you get to work?')
            ->assertSet('fbStrength', 'نقطه قوت تو این بود که واضح جواب دادی.')
            ->assertSee('نقطه قوت تو این بود که واضح جواب دادی.');

        $this->assertDatabaseHas('evidences', ['mission_run_id' => $run->id, 'phase' => 'ai_conversation_1', 'type' => Evidence::TYPE_TEXT]);
        $this->assertDatabaseHas('evidences', ['mission_run_id' => $run->id, 'phase' => 'ai_conversation_1', 'type' => Evidence::TYPE_AUDIO]);

        $textEvidence = Evidence::where('phase', 'ai_conversation_1')->where('type', Evidence::TYPE_TEXT)->first();
        $content = json_decode($textEvidence->content_ref, true);
        $this->assertCount(2, $content['turns']);
        $this->assertSame('I usually wake up at seven and have breakfast.', $content['transcript']);
        $this->assertSame('I wake up at seven.', $content['feedback']['correction']['original']);

        $this->assertDatabaseCount('ai_feedbacks', 1);
        $this->assertSame($textEvidence->id, AIFeedback::first()->evidence_id);

        // Evidence is already saved — currentStepKey has already advanced,
        // the recap is just a courtesy screen before navigating away.
        $this->assertSame('writing', $run->fresh()->currentStepKey());

        $component->call('proceed')->assertRedirect(route('missions.show', $run->mission));
    }

    public function test_a_major_feedback_severity_records_a_struggle_signal(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $run = $this->makeRun(questionCount: 1);

        $component = Livewire::test('missions.steps.ai-conversation1', ['run' => $run]);
        $this->completeWarmUp($component);

        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->once()->andReturn('I wake up at seven.'));
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->andReturn(
                json_encode(['severity' => 'none', 'hint' => '']),
                'Every day?',
                json_encode(['strength' => 'x', 'expression' => 'y', 'correction' => ['original' => 'a', 'corrected' => 'b', 'why' => 'c', 'suggestion' => 'd'], 'severity' => 'major']),
            );
        });

        $component->set('audioFile', UploadedFile::fake()->create('answer1.webm', 100, 'audio/webm'))->call('submitAnswer');

        $this->assertGreaterThanOrEqual(1, $run->fresh()->struggle_signal_count);
    }

    public function test_a_malformed_feedback_response_does_not_block_completion(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $run = $this->makeRun(questionCount: 1);

        $component = Livewire::test('missions.steps.ai-conversation1', ['run' => $run]);
        $this->completeWarmUp($component);

        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->once()->andReturn('I wake up at seven.'));
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->andReturn(
                json_encode(['severity' => 'none', 'hint' => '']),
                'Every day?',
                'not valid json',
            );
        });

        $component->set('audioFile', UploadedFile::fake()->create('answer1.webm', 100, 'audio/webm'))
            ->call('submitAnswer')
            ->assertSet('completed', true)
            ->assertSet('fbStrength', null);

        $this->assertDatabaseHas('evidences', ['mission_run_id' => $run->id, 'phase' => 'ai_conversation_1', 'type' => Evidence::TYPE_TEXT]);
        $this->assertDatabaseCount('ai_feedbacks', 0);
        $this->assertSame('writing', $run->fresh()->currentStepKey());
    }

    public function test_a_failed_ai_call_shows_an_error_without_losing_progress(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $run = $this->makeRun(questionCount: 2);

        $component = Livewire::test('missions.steps.ai-conversation1', ['run' => $run]);
        $this->completeWarmUp($component);

        $this->mock(GroqClient::class, function ($mock) {
            $mock->shouldReceive('transcribe')->once()->andThrow(new \RuntimeException('service unavailable'));
        });

        $component
            ->set('audioFile', UploadedFile::fake()->create('answer1.webm', 100, 'audio/webm'))
            ->call('submitAnswer')
            ->assertSet('round', 0)
            ->assertSet('error', fn ($error) => str_contains($error, 'service unavailable'));

        $this->assertDatabaseCount('evidences', 0);
    }

    public function test_the_current_question_has_a_read_aloud_button_the_learner_must_click(): void
    {
        Storage::fake('public');
        $run = $this->makeRun(questionCount: 2);

        $component = Livewire::test('missions.steps.ai-conversation1', ['run' => $run]);
        $this->completeWarmUp($component);

        $component
            ->assertSeeHtml('data-text="What time do you usually wake up?"')
            ->assertSee('Read aloud')
            ->assertDontSeeHtml('x-init');
    }

    public function test_a_mutual_friend_can_be_offered_the_question_to_practice_together(): void
    {
        Storage::fake('public');
        $run = $this->makeRun(questionCount: 2);
        $friend = User::factory()->create(['name' => 'Priya']);
        $run->learner->follow($friend);
        $friend->acceptFollowRequest($run->learner);

        $component = Livewire::test('missions.steps.ai-conversation1', ['run' => $run]);
        $this->completeWarmUp($component);

        $component
            ->assertSee('Priya')
            ->assertSeeHtml(route('friends.conversation', [
                'user' => $friend,
                'prefill' => 'Hey — want to help me practice this: "What time do you usually wake up?"',
            ]));
    }

    public function test_an_off_topic_answer_is_not_advanced_and_shows_an_encouraging_hint(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $run = $this->makeRun(questionCount: 2);

        $component = Livewire::test('missions.steps.ai-conversation1', ['run' => $run]);
        $this->completeWarmUp($component);

        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->once()->andReturn('Pizza is great.'));
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn(json_encode([
            'severity' => 'major',
            'hint' => "That's not quite about your morning — want to try again?",
        ])));

        $component
            ->set('audioFile', UploadedFile::fake()->create('answer1.webm', 100, 'audio/webm'))
            ->call('submitAnswer')
            ->assertSet('round', 0)
            ->assertSet('turns', [])
            ->assertSee("That's not quite about your morning — want to try again?");

        $this->assertDatabaseCount('evidences', 0);
    }

    public function test_after_3_off_topic_attempts_an_example_can_be_revealed(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $run = $this->makeRun(questionCount: 1);

        $component = Livewire::test('missions.steps.ai-conversation1', ['run' => $run]);
        $this->completeWarmUp($component);

        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->times(3)->andReturn('a', 'b', 'c'));
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->times(3)->andReturn(
            json_encode(['severity' => 'major', 'hint' => 'Try again.']),
            json_encode(['severity' => 'major', 'hint' => 'Try again.']),
            json_encode(['severity' => 'major', 'hint' => 'Try again.']),
        ));

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

    public function test_shows_a_progress_bar_and_the_selected_vocabulary(): void
    {
        Storage::fake('public');
        $run = $this->makeRun(questionCount: 2);

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'vocabulary_builder_1',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode(['selected_words' => ['wake up', 'have a shower']]),
        ]);

        $component = Livewire::test('missions.steps.ai-conversation1', ['run' => $run]);
        $this->completeWarmUp($component);

        $component
            ->assertSee('Question 1 of 2')
            ->assertSeeHtml('h-1.5 w-full overflow-hidden rounded-full')
            ->assertSee('Words you picked')
            ->assertSee('wake up')
            ->assertSee('have a shower');
    }

    public function test_shows_the_shared_thinking_indicator_while_submitting(): void
    {
        Storage::fake('public');
        $run = $this->makeRun(questionCount: 2);

        $component = Livewire::test('missions.steps.ai-conversation1', ['run' => $run]);
        $this->completeWarmUp($component);

        $component
            ->assertSeeHtml('wire:target="submitAnswer"')
            ->assertSee('Transcribing and thinking of a follow-up…');
    }

    // -----------------------------------------------------------------
    // Read-only review
    // -----------------------------------------------------------------

    private function saveCompletedEvidence(MissionRun $run, array $overrides = []): Evidence
    {
        return Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'ai_conversation_1',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode(array_merge([
                'transcript' => 'I usually wake up at seven and have breakfast.',
                'segments' => [['text' => 'I usually wake up at seven', 'confidence' => 'high']],
                'reflection' => ['highlight' => 'خیلی روان صحبت کردی.', 'tip' => 'دفعه‌ی بعد یه جزئیات بیشتر اضافه کن.'],
                'turns' => [
                    ['question' => 'What time do you usually wake up?', 'answer' => 'Seven.', 'followup' => 'Every day?'],
                    ['question' => 'What do you normally do in the morning?', 'answer' => 'Breakfast.', 'followup' => 'What do you eat?'],
                ],
                'feedback' => [
                    'strength' => 'نقطه قوت تو این بود که واضح جواب دادی.',
                    'expression' => 'از عبارت wake up درست استفاده کردی.',
                    'correction' => [
                        'original' => 'I wake up at seven.',
                        'corrected' => 'I usually wake up at seven.',
                        'why' => 'برای توصیف عادت‌های روزمره باید از قید تکرار استفاده کنی.',
                        'suggestion' => 'برای تمرین بیشتر، چند جمله با usually بنویس.',
                    ],
                    'severity' => 'minor',
                ],
            ], $overrides)),
        ]);
    }

    public function test_read_only_mode_reloads_everything_without_calling_groq_or_gemini(): void
    {
        $run = $this->makeRun(questionCount: 2);
        $this->saveCompletedEvidence($run);
        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'ai_conversation_1',
            'type' => Evidence::TYPE_AUDIO,
            'content_ref' => 'http://localhost/storage/missions/m01/evidence/speaking.webm',
        ]);

        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldNotReceive('transcribe'));
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldNotReceive('chat'));

        Livewire::test('missions.steps.ai-conversation1', ['run' => $run, 'readOnly' => true])
            ->assertSet('transcript', 'I usually wake up at seven and have breakfast.')
            ->assertSet('completed', true)
            ->assertSeeHtml('http://localhost/storage/missions/m01/evidence/speaking.webm')
            ->assertSee('خیلی روان صحبت کردی.')
            ->assertSee('نقطه قوت تو این بود که واضح جواب دادی.')
            ->assertSee('Every day?') // the final follow-up must still be visible
            ->assertDontSeeHtml('data-text=')
            ->assertDontSeeHtml('field="warmUpAudioFile"');
    }

    /**
     * Regression: Evidence saved under the OLD flat-string `correction`
     * shape (before it became the nested {original, corrected, why,
     * suggestion} object, back when this was a separate AI Feedback #1
     * step) used to still render an empty, alarming "SOMETHING TO FIX"
     * card with no body text. Must still be omitted entirely.
     */
    public function test_read_only_mode_with_old_flat_string_correction_shape_omits_the_empty_card(): void
    {
        $run = $this->makeRun(questionCount: 1);
        $this->saveCompletedEvidence($run, [
            'feedback' => [
                'strength' => 'نقطه قوت تو این بود که واضح جواب دادی.',
                'expression' => 'از عبارت wake up درست استفاده کردی.',
                'correction' => 'I wake up at seven. -> I usually wake up at seven.', // old flat-string shape
                'severity' => 'minor',
            ],
        ]);

        Livewire::test('missions.steps.ai-conversation1', ['run' => $run, 'readOnly' => true])
            ->assertSee('نقطه قوت تو این بود که واضح جواب دادی.')
            ->assertDontSee('Something to fix');
    }
}
