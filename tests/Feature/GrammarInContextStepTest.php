<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\GrammarPoint;
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
                                'sections' => [
                                    [
                                        'heading' => 'A · The verb changes with he / she / it',
                                        'blocks' => [
                                            [
                                                'type' => 'pairs',
                                                'pairs' => [
                                                    ['left' => 'I wake up early.', 'right' => 'She wakes up early.'],
                                                ],
                                            ],
                                        ],
                                    ],
                                    [
                                        'heading' => 'B · Questions and negatives use do / does',
                                        'blocks' => [
                                            [
                                                'type' => 'examples',
                                                'groups' => [
                                                    [
                                                        'items' => [
                                                            'Do you usually wake up early?',
                                                            'Does she work on Saturdays?',
                                                            "I don't usually wake up before seven.",
                                                            "He doesn't work on Sundays.",
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                    [
                                        'heading' => 'C · Where the frequency word goes',
                                        'blocks' => [
                                            [
                                                'type' => 'chips',
                                                'groups' => [
                                                    ['words' => ['always', 'usually', 'often', 'sometimes', 'rarely', 'never']],
                                                ],
                                            ],
                                            [
                                                'type' => 'rule_examples',
                                                'items' => [
                                                    ['rule' => 'One-word verb → the adverb goes before it', 'example' => 'I always wake up early.', 'highlight' => 'always'],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                                'bridge_note' => "You'll use this next in Activation.",
                            ],
                            'frequency_starters' => ['I usually', 'I often', 'I sometimes', 'I rarely'],
                            'grammar_judgment' => 'Judge whether the learner finished this sentence starter into a '
                                .'true, natural personal sentence, correctly using the present simple tense.',
                            'grammar_major_criteria' => 'the verb is not in the present simple tense, the sentence '
                                .'does not actually continue the given starter, or it is not a genuine personal statement',
                            'grammar_context' => 'continues in the present simple tense',
                            'quick_check' => [
                                ['wrong' => 'She go to work.', 'options' => ['She goes to work.', 'She gos to work.'], 'correct' => 0, 'difficulty' => 'easy'],
                                ['wrong' => 'He wake up late.', 'options' => ['He wakes up late.', 'He waking up late.'], 'correct' => 0, 'difficulty' => 'hard'],
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

    public function test_completing_the_step_enrolls_the_grammar_focus_into_spaced_repetition(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(3)->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        Livewire::test('missions.steps.grammar-in-context', ['run' => $run])
            ->set('frequencySentences.0', 'I usually wake up at 7.')
            ->set('frequencySentences.1', 'I often cook dinner.')
            ->set('frequencySentences.2', 'I sometimes exercise.')
            ->call('save');

        $point = GrammarPoint::where('learner_id', $run->learner_id)->first();

        $this->assertNotNull($point);
        $this->assertSame('Present Simple + Adverbs of Frequency', $point->focus);
        $this->assertSame('I usually wake up at 7.', $point->example_sentence);
        $this->assertSame('M01', $point->mission_code);
        $this->assertSame($run->id, $point->source_mission_run_id);
        $this->assertTrue($point->isDue());
    }

    public function test_re_completing_grammar_in_context_refreshes_the_same_grammar_point(): void
    {
        $run = $this->makeRun();
        $existing = GrammarPoint::create([
            'learner_id' => $run->learner_id,
            'mission_code' => 'M01',
            'focus' => 'Present Simple + Adverbs of Frequency',
            'example_sentence' => 'An old example.',
            'rule_reminder' => 'An old reminder.',
            'next_review_at' => now()->addDays(5),
        ]);

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(3)->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        Livewire::test('missions.steps.grammar-in-context', ['run' => $run])
            ->set('frequencySentences.0', 'I usually wake up at 7.')
            ->set('frequencySentences.1', 'I often cook dinner.')
            ->set('frequencySentences.2', 'I sometimes exercise.')
            ->call('save');

        $this->assertSame(1, GrammarPoint::where('learner_id', $run->learner_id)->count());
        $this->assertSame($existing->id, GrammarPoint::first()->id);
        $this->assertSame('I usually wake up at 7.', $existing->fresh()->example_sentence);
    }

    public function test_the_quick_check_is_optional_and_never_blocks_continue(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(3)->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        Livewire::test('missions.steps.grammar-in-context', ['run' => $run])
            ->set('frequencySentences.0', 'I usually wake up at 7.')
            ->set('frequencySentences.1', 'I often cook dinner.')
            ->set('frequencySentences.2', 'I sometimes exercise.')
            // quickCheckScore left untouched — the learner skipped the round.
            ->call('save')
            ->assertRedirect(route('missions.show', $run->mission));

        $evidence = Evidence::where('phase', 'grammar_in_context')->first();
        $content = json_decode($evidence->content_ref, true);

        $this->assertCount(3, $content['frequency_sentences']);
        $this->assertNull($content['quick_check_score']);
        $this->assertSame('activation', $run->fresh()->currentStepKey());
    }

    public function test_a_completed_quick_check_score_is_recorded_in_evidence(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(3)->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        Livewire::test('missions.steps.grammar-in-context', ['run' => $run])
            ->set('frequencySentences.0', 'I usually wake up at 7.')
            ->set('frequencySentences.1', 'I often cook dinner.')
            ->set('frequencySentences.2', 'I sometimes exercise.')
            ->set('quickCheckScore', ['correct' => 2, 'total' => 2])
            ->call('save');

        $evidence = Evidence::where('phase', 'grammar_in_context')->first();
        $content = json_decode($evidence->content_ref, true);

        $this->assertSame(['correct' => 2, 'total' => 2], $content['quick_check_score']);
    }

    public function test_the_quick_check_is_rendered_as_a_quick_round(): void
    {
        $run = $this->makeRun();

        $html = Livewire::test('missions.steps.grammar-in-context', ['run' => $run])->html();

        $this->assertStringContainsString('She go to work.', $html);
        $this->assertStringContainsString('quick-round-completed', $html);
    }

    public function test_the_seeded_difficulty_tag_is_threaded_through_to_the_quick_check_cards(): void
    {
        $run = $this->makeRun();

        $cards = Livewire::test('missions.steps.grammar-in-context', ['run' => $run])
            ->instance()
            ->quickCheckCards();

        $this->assertSame('easy', $cards[0]['difficulty']);
        $this->assertSame('hard', $cards[1]['difficulty']);
    }

    public function test_the_lesson_highlights_the_adverb_and_shows_the_bridge_note(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.grammar-in-context', ['run' => $run])
            ->assertSeeHtml('<strong class="text-ink underline decoration-2 underline-offset-2 dark:text-ink-dark">always</strong>')
            ->assertSee("You'll use this next in Activation.");
    }

    public function test_the_practice_section_offers_clickable_vocabulary_chips(): void
    {
        $run = $this->makeRun();

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'vocabulary_builder',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode(['selected_words' => ['wake up', 'have a shower', 'go to bed']]),
        ]);

        Livewire::test('missions.steps.grammar-in-context', ['run' => $run])
            ->assertSee('Tap a word to drop it into your next sentence')
            ->assertSee('wake up')
            ->assertSee('have a shower')
            ->assertSee('go to bed')
            ->assertSeeHtml('$wire.frequencySentences.findIndex')
            ->assertSeeHtml("\$wire.set('frequencySentences.' + idx, 'Wake up')");
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

    public function test_reviewing_a_completed_step_reloads_saved_sentences_and_quick_check_score(): void
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
                'quick_check_score' => ['correct' => 2, 'total' => 2],
            ]),
        ]);

        Livewire::test('missions.steps.grammar-in-context', ['run' => $run, 'readOnly' => true])
            ->assertSet('frequencySentences.0', 'I usually wake up at 7.')
            ->assertSet('quickCheckScore', ['correct' => 2, 'total' => 2])
            ->assertSee('You scored 2 of 2.');
    }

    public function test_sentence_inputs_carry_a_draft_key_scoped_to_the_run(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.grammar-in-context', ['run' => $run])
            ->assertSeeHtml("eos-draft:{$run->id}:grammar_in_context:frequencySentences.0");
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
            ->call('save')
            ->assertDispatched('clear-draft', prefix: "eos-draft:{$run->id}:grammar_in_context:");
    }

    /**
     * Proves the step is genuinely generic — not just still working for
     * M01's own present-simple content — by seeding a completely different
     * grammar point (past simple vs present perfect, a plausible future M03)
     * and checking it renders through the same generic section/block shape:
     * grouped examples with labels, grouped chips with labels, and a
     * rule_examples item that deliberately omits 'highlight' (M01's fixture
     * always supplies one) to prove that path degrades gracefully.
     */
    private function makeGenericGrammarRun(): MissionRun
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M-GENERIC',
            'title' => 'A Different Grammar Point',
            'module' => 'Test',
            'outcome' => 'I can talk about the past.',
            'phases' => [
                [
                    'phase' => 'build',
                    'steps' => [
                        [
                            'key' => 'grammar_in_context',
                            'focus' => 'Past Simple vs Present Perfect',
                            'lesson' => [
                                'intro' => 'A different lesson entirely.',
                                'sections' => [
                                    [
                                        'heading' => 'A · What each tense is for',
                                        'body' => 'Use <strong>Past Simple</strong> for a finished time. Use <strong>Present Perfect</strong> for a link to now.',
                                        'blocks' => [
                                            [
                                                'type' => 'examples',
                                                'groups' => [
                                                    ['label' => 'Past Simple', 'items' => ['I visited Paris last year.']],
                                                    ['label' => 'Present Perfect', 'items' => ["I've visited Paris."]],
                                                ],
                                            ],
                                        ],
                                    ],
                                    [
                                        'heading' => 'B · Time words',
                                        'blocks' => [
                                            [
                                                'type' => 'chips',
                                                'groups' => [
                                                    ['label' => 'Past Simple', 'words' => ['yesterday', 'last week']],
                                                    ['label' => 'Present Perfect', 'words' => ['ever', 'already']],
                                                ],
                                            ],
                                        ],
                                    ],
                                    [
                                        'heading' => 'C · Choosing the right one',
                                        'blocks' => [
                                            [
                                                'type' => 'rule_examples',
                                                // Deliberately no 'highlight' key — proves the
                                                // no-highlight path renders plain, escaped text.
                                                'items' => [
                                                    ['rule' => 'A specific finished time → Past Simple', 'example' => 'I saw that film last night.'],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                                'bridge_note' => 'Bridge note for a different grammar point.',
                            ],
                            'frequency_starters' => ['I have', 'Last week I'],
                            'grammar_judgment' => 'Judge whether the learner correctly chose between the past '
                                .'simple and the present perfect for this sentence starter.',
                            'grammar_major_criteria' => 'the wrong tense is used for the described time reference, '
                                .'or the sentence does not continue the given starter',
                            'grammar_context' => 'continues using either the past simple or the present perfect '
                                .'tense, whichever fits',
                            'quick_check' => [
                                ['wrong' => 'I have seen that film yesterday.', 'options' => ['I saw that film yesterday.', 'I have seen that film yesterday.'], 'correct' => 0],
                            ],
                        ],
                        ['key' => 'activation'],
                    ],
                ],
            ],
        ]);

        return MissionRun::findOrStart($learner, $mission);
    }

    public function test_a_different_grammar_points_lesson_renders_via_the_generic_section_shape(): void
    {
        $run = $this->makeGenericGrammarRun();

        $component = Livewire::test('missions.steps.grammar-in-context', ['run' => $run]);

        $component
            ->assertSee('A · What each tense is for')
            ->assertSee('B · Time words')
            ->assertSee('C · Choosing the right one')
            ->assertSeeHtml('Use <strong>Past Simple</strong> for a finished time.')
            ->assertSee('Past Simple') // group label
            ->assertSee('Present Perfect') // group label
            ->assertSee('I visited Paris last year.')
            ->assertSee("I've visited Paris.")
            ->assertSee('yesterday')
            ->assertSee('already')
            ->assertSee('A specific finished time → Past Simple')
            ->assertSee('I saw that film last night.')
            ->assertSee('Bridge note for a different grammar point.');

        // No 'highlight' key was supplied for the rule_examples item — the
        // highlight <strong> wrapper (used elsewhere, e.g. M01's "always")
        // must not appear for this one, proving the guard in highlightWord()
        // degrades to plain escaped text instead of a broken regex.
        $component->assertDontSeeHtml(
            '<strong class="text-ink underline decoration-2 underline-offset-2 dark:text-ink-dark">'
        );

        // The bridge note is attached to the lesson as a whole, not
        // per-section — it must render exactly once even though 3 sections
        // are all present in the DOM (only one visible at a time via
        // x-show/x-cloak).
        $this->assertSame(1, substr_count($component->html(), 'Bridge note for a different grammar point.'));
    }

    public function test_the_seeded_judgment_and_major_criteria_drive_the_ai_check_for_a_different_grammar_point(): void
    {
        $run = $this->makeGenericGrammarRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(function (array $messages, ?string $systemPrompt) {
                    return str_contains($messages[0]['text'], 'continues using either the past simple or the present perfect tense, whichever fits')
                        && str_contains($systemPrompt, 'Judge whether the learner correctly chose between the past simple and the present perfect')
                        && str_contains($systemPrompt, 'the wrong tense is used for the described time reference')
                        // The M01-specific wording must NOT leak into a
                        // different mission's check — proves this isn't
                        // still hardcoded.
                        && ! str_contains($systemPrompt, 'present simple tense');
                })
                ->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        Livewire::test('missions.steps.grammar-in-context', ['run' => $run])
            ->set('frequencySentences.0', "I've visited Paris three times.")
            ->call('checkOne', 0);
    }

    public function test_the_seeded_context_is_used_when_revealing_a_correction_for_a_different_grammar_point(): void
    {
        $run = $this->makeGenericGrammarRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(3)->andReturn(json_encode(['severity' => 'major', 'hint' => 'Try again.']));
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(fn (array $messages) => str_contains(
                    $messages[0]['text'],
                    'continues using either the past simple or the present perfect tense, whichever fits'
                ))
                ->andReturn('I have visited Paris three times.');
        });

        $component = Livewire::test('missions.steps.grammar-in-context', ['run' => $run])
            ->set('frequencySentences.0', 'bad fragment');

        $component->call('checkOne', 0);
        $component->call('checkOne', 0);
        $component->call('checkOne', 0)->assertSet('offerReveal.0', true);

        $component->call('revealCorrection', 0)
            ->assertSet('frequencySentences.0', 'I have visited Paris three times.');
    }
}
