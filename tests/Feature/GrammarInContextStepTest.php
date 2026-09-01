<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use App\Services\GeminiClient;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Livewire\Livewire;
use Tests\TestCase;

class GrammarInContextStepTest extends TestCase
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
                    'phase' => 'build',
                    'steps' => [
                        [
                            'key' => 'grammar_in_context',
                            'focus' => 'Present Simple + Adverbs of Frequency',
                            'lesson' => [
                                'conjugation_examples' => [
                                    ['base' => 'I wake up early.', 'third_person' => 'She wakes up early.'],
                                ],
                                'question_example' => 'Do you usually wake up early?',
                                'question_example_does' => 'Does she work on Saturdays?',
                                'negative_example' => "I don't usually wake up before seven.",
                                'negative_example_does' => "He doesn't work on Sundays.",
                                'frequency_scale' => ['always', 'usually', 'often', 'sometimes', 'rarely', 'never'],
                                'word_order_examples' => [
                                    ['rule' => 'One-word verb → the adverb goes before it', 'example' => 'I always wake up early.', 'adverb' => 'always'],
                                ],
                                'bridge_note' => "You'll use this next in Activation.",
                            ],
                            'frequency_starters' => ['I usually', 'I often', 'I sometimes', 'I rarely'],
                            'quick_check' => [
                                ['wrong' => 'She go to work.', 'correct' => 'She goes to work.'],
                                ['wrong' => 'He wake up late.', 'correct' => 'He wakes up late.'],
                            ],
                        ],
                        ['key' => 'activation'],
                    ],
                ],
            ],
        ]);

        return MissionRun::findOrStart($learner, $mission);
    }

    public function test_clicking_check_on_an_empty_frequency_sentence_shows_an_error(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldNotReceive('chat');
        });

        Livewire::test('missions.steps.grammar-in-context', ['run' => $run])
            ->call('checkOne', 0)
            ->assertSet('checkErrors.0', 'Write something first.')
            ->assertSee('Write something first.');
    }

    public function test_clicking_check_on_an_empty_correction_shows_an_error(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldNotReceive('chat');
        });

        Livewire::test('missions.steps.grammar-in-context', ['run' => $run])
            ->call('checkCorrection', 0)
            ->assertSet('checkErrors.qc_0', 'Write something first.')
            ->assertSee('Write something first.');
    }

    public function test_requires_three_sentences_before_continuing(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.grammar-in-context', ['run' => $run])
            ->set('frequencySentences.0', 'I usually wake up at 7.')
            ->call('save')
            ->assertHasErrors(['frequencySentences']);

        $this->assertDatabaseCount('evidences', 0);
    }

    public function test_a_major_ai_verdict_on_a_frequency_sentence_blocks_continue(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->once()
                ->andReturn(json_encode(['severity' => 'major', 'hint' => 'That is not the present simple tense.']))
                ->ordered();
            $mock->shouldReceive('chat')
                ->twice()
                ->andReturn(json_encode(['severity' => 'none', 'hint' => '']))
                ->ordered();
        });

        Livewire::test('missions.steps.grammar-in-context', ['run' => $run])
            ->set('frequencySentences.0', 'I woke up at 7.')
            ->set('frequencySentences.1', 'I often cook dinner.')
            ->set('frequencySentences.2', 'I sometimes exercise.')
            ->call('save')
            ->assertHasErrors(['frequencySentences']);

        $this->assertDatabaseCount('evidences', 0);
    }

    public function test_incorrect_quick_check_corrections_block_continue(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(3)->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        Livewire::test('missions.steps.grammar-in-context', ['run' => $run])
            ->set('frequencySentences.0', 'I usually wake up at 7.')
            ->set('frequencySentences.1', 'I often cook dinner.')
            ->set('frequencySentences.2', 'I sometimes exercise.')
            ->set('corrections.0', 'She go to work.') // still wrong — repeats the error
            ->set('corrections.1', 'He wakes up late.')
            ->call('save')
            ->assertHasErrors(['corrections']);

        $this->assertDatabaseCount('evidences', 0);
    }

    public function test_valid_submission_records_evidence_and_advances_the_run(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(3)->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        Livewire::test('missions.steps.grammar-in-context', ['run' => $run])
            ->set('frequencySentences.0', 'I usually wake up at 7.')
            ->set('frequencySentences.1', 'I often cook dinner.')
            ->set('frequencySentences.2', 'I sometimes exercise.')
            ->set('corrections.0', 'She goes to work.')
            ->set('corrections.1', 'He wakes up late.')
            ->call('save')
            ->assertRedirect(route('missions.show', $run->mission));

        $evidence = Evidence::where('phase', 'grammar_in_context')->first();
        $content = json_decode($evidence->content_ref, true);

        $this->assertCount(3, $content['frequency_sentences']);
        $this->assertSame('She goes to work.', $content['corrections'][0]['my_correction']);
        $this->assertTrue($content['corrections'][0]['is_correct']);

        $this->assertSame('activation', $run->fresh()->currentStepKey());
    }

    public function test_checking_a_correction_gives_local_feedback_without_calling_the_ai(): void
    {
        $run = $this->makeRun();

        // No GeminiClient mock at all — checkCorrection must never call it.
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldNotReceive('chat');
        });

        Livewire::test('missions.steps.grammar-in-context', ['run' => $run])
            ->set('corrections.0', 'She go to work.')
            ->call('checkCorrection', 0)
            ->assertSet('correctionFeedback.0.severity', 'minor');
    }

    public function test_the_lesson_highlights_the_adverb_and_shows_the_bridge_note(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.grammar-in-context', ['run' => $run])
            ->assertSeeHtml('<strong class="text-neutral-900 underline decoration-2 underline-offset-2 dark:text-white">always</strong>')
            ->assertSee("You'll use this next in Activation.");
    }

    public function test_the_practice_section_tips_the_learner_to_reuse_their_selected_vocabulary(): void
    {
        $run = $this->makeRun();

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'vocabulary_builder',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode(['selected_words' => ['wake up', 'have a shower', 'go to bed']]),
        ]);

        Livewire::test('missions.steps.grammar-in-context', ['run' => $run])
            ->assertSee('Tip: try using one of your words from earlier')
            ->assertSee('wake up, have a shower, go to bed');
    }

    public function test_starting_practice_is_persisted_so_a_later_render_still_shows_practice(): void
    {
        $run = $this->makeRun();

        $component = Livewire::test('missions.steps.grammar-in-context', ['run' => $run])
            ->call('startPractice')
            ->assertSet('practiceStarted', true);

        // A later Livewire round-trip (e.g. clicking Check) re-renders the
        // component — the Alpine x-data init string must still say
        // 'practice', not 'lesson', or the UI would silently jump back to
        // the start of the lesson on every check.
        $this->assertStringContainsString("phase: 'practice'", $component->html());
    }

    public function test_three_failed_frequency_sentence_checks_offer_to_reveal_the_correction(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(3)->andReturn(json_encode(['severity' => 'major', 'hint' => 'Try again.']));
        });

        $component = Livewire::test('missions.steps.grammar-in-context', ['run' => $run])
            ->set('frequencySentences.0', 'attempt one')
            ->call('checkOne', 0)
            ->assertSet('offerReveal.0', null);

        $component->call('checkOne', 0)
            ->assertSet('offerReveal.0', null)
            ->assertSee('One more try — after that I can write the correct one for you');

        $component->call('checkOne', 0)
            ->assertSet('offerReveal.0', true)
            ->assertDontSee('One more try — after that I can write the correct one for you');
    }

    public function test_accepting_the_reveal_writes_the_ai_correction_into_the_sentence(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(3)->andReturn(json_encode(['severity' => 'major', 'hint' => 'Try again.']));
            $mock->shouldReceive('chat')->once()->andReturn('I usually wake up at seven.');
        });

        $component = Livewire::test('missions.steps.grammar-in-context', ['run' => $run])
            ->set('frequencySentences.0', 'I usually woked up.');

        $component->call('checkOne', 0);
        $component->call('checkOne', 0);
        $component->call('checkOne', 0)->assertSet('offerReveal.0', true);

        $component->call('revealCorrection', 0)
            ->assertSet('frequencySentences.0', 'I usually wake up at seven.')
            ->assertSet('feedback.0.severity', 'none')
            ->assertSet('offerReveal.0', null)
            ->assertSet('checkAttempts.0', null);
    }

    public function test_declining_the_reveal_resets_the_attempt_count_and_can_be_offered_again(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(6)->andReturn(json_encode(['severity' => 'major', 'hint' => 'Try again.']));
        });

        $component = Livewire::test('missions.steps.grammar-in-context', ['run' => $run])
            ->set('frequencySentences.0', 'attempt one');

        $component->call('checkOne', 0);
        $component->call('checkOne', 0);
        $component->call('checkOne', 0)->assertSet('offerReveal.0', true);

        $component->call('declineReveal', 0)
            ->assertSet('offerReveal.0', null)
            ->assertSet('checkAttempts.0', 0);

        // Declining doesn't end the offer forever — 3 more failed attempts
        // brings it back.
        $component->call('checkOne', 0);
        $component->call('checkOne', 0);
        $component->call('checkOne', 0)->assertSet('offerReveal.0', true);
    }

    public function test_three_failed_quick_check_attempts_offer_to_reveal_the_correction_without_ai(): void
    {
        $run = $this->makeRun();

        // No GeminiClient mock — Quick Check's known-answer reveal never calls it.
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldNotReceive('chat');
        });

        $component = Livewire::test('missions.steps.grammar-in-context', ['run' => $run])
            ->set('corrections.0', 'She go to work.');

        $component->call('checkCorrection', 0);
        $component->call('checkCorrection', 0);
        $component->call('checkCorrection', 0)->assertSet('offerReveal.qc_0', true);

        $component->call('revealQuickCheckCorrection', 0)
            ->assertSet('corrections.0', 'She goes to work.')
            ->assertSet('correctionFeedback.0.severity', 'none')
            ->assertSet('offerReveal.qc_0', null);
    }

    public function test_a_request_exception_does_not_leak_the_raw_response_body(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andThrow(new RequestException(
                new Response(new Psr7Response(403, [], '<!DOCTYPE html><html>blocked by network</html>'))
            ));
        });

        Livewire::test('missions.steps.grammar-in-context', ['run' => $run])
            ->set('frequencySentences.0', 'I usually wake up at 7.')
            ->call('checkOne', 0)
            ->assertSet('checkErrors.0', "Couldn't reach the AI service — please try again.");
    }

    public function test_reviewing_a_completed_step_reloads_saved_sentences_and_corrections(): void
    {
        $run = $this->makeRun();

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'grammar_in_context',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode([
                'frequency_sentences' => [
                    ['starter' => 'I usually', 'completion' => 'I usually wake up at 7.'],
                ],
                'corrections' => [
                    ['wrong' => 'She go to work.', 'my_correction' => 'She goes to work.', 'correct' => 'She goes to work.', 'is_correct' => true],
                ],
            ]),
        ]);

        Livewire::test('missions.steps.grammar-in-context', ['run' => $run, 'readOnly' => true])
            ->assertSet('frequencySentences.0', 'I usually wake up at 7.')
            ->assertSet('corrections.0', 'She goes to work.');
    }

    public function test_sentence_and_correction_inputs_carry_a_draft_key_scoped_to_the_run(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.grammar-in-context', ['run' => $run])
            ->assertSeeHtml("eos-draft:{$run->id}:grammar_in_context:frequencySentences.0")
            ->assertSeeHtml("eos-draft:{$run->id}:grammar_in_context:corrections.0");
    }

    public function test_a_successful_save_dispatches_a_clear_draft_event(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(3)->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        Livewire::test('missions.steps.grammar-in-context', ['run' => $run])
            ->set('frequencySentences.0', 'I usually wake up at 7.')
            ->set('frequencySentences.1', 'I often cook dinner.')
            ->set('frequencySentences.2', 'I sometimes exercise.')
            ->set('corrections.0', 'She goes to work.')
            ->set('corrections.1', 'He wakes up late.')
            ->call('save')
            ->assertDispatched('clear-draft', prefix: "eos-draft:{$run->id}:grammar_in_context:");
    }
}
