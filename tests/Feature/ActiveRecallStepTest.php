<?php

namespace Tests\Feature;

use App\Models\ErrorLogItem;
use App\Models\ErrorPatternReview;
use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use App\Services\GeminiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ActiveRecallStepTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The real active_recall section shapes M01 uses (mirroring
     * MissionSeeder.php) — present_simple_sentences declares its own
     * 'judgment' (moved verbatim from the old hardcoded PHP case) plus
     * 'major_criteria'/'context'/'recap_label' overrides, proving the
     * generic checkOpenField() dispatch above reproduces the exact old
     * behavior from seeded content alone, not a hardcoded PHP case.
     *
     * @return list<array<string, mixed>>
     */
    private function activeRecallSections(): array
    {
        return [
            ['key' => 'expressions', 'label' => '5 expressions I learned', 'count' => 5],
            ['key' => 'listening_facts', 'label' => '3 things I learned from the listening', 'count' => 3],
            [
                'key' => 'present_simple_sentences',
                'label' => '3 Present Simple sentences',
                'count' => 3,
                'judgment' => 'Judge whether the learner wrote a genuine, natural personal sentence, correctly '
                    .'using the present simple tense.',
                'major_criteria' => 'the verb is not in the present simple tense, or it is not a genuine personal '
                    .'statement',
                'context' => 'a personal sentence using the present simple tense',
                'recap_label' => 'sentences correctly used the present simple',
            ],
        ];
    }

    private function makeRun(?User $learner = null): MissionRun
    {
        $learner ??= User::factory()->create();
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
                            'key' => 'active_recall',
                            'instruction' => 'Without looking at the previous pages.',
                            'sections' => $this->activeRecallSections(),
                        ],
                        ['key' => 'error_log'],
                    ],
                ],
                [
                    // A separate phase so it doesn't sit between active_recall
                    // and error_log in currentStepKey()'s sequential order —
                    // only its content (the topic summary) matters here.
                    'phase' => 'earlier',
                    'steps' => [
                        ['key' => 'listening', 'topic_summary' => 'Neil and Georgie talk about their morning routines.'],
                    ],
                ],
            ],
        ]);

        $run = MissionRun::findOrStart($learner, $mission);

        // The real "listening" step already happened earlier in the
        // mission — mark it done so it doesn't affect currentStepKey()
        // progression through active_recall -> error_log below.
        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'listening',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => '{}',
        ]);

        return $run;
    }

    /**
     * Fills the two required-non-empty sections that aren't the focus of a
     * given test, so save() gets past the "at least one per section" gate.
     */
    private function fillOtherSections($component): void
    {
        $component
            ->set('answers.listening_facts.0', 'They talked about morning routines.')
            ->set('answers.present_simple_sentences.0', 'I wake up at seven.');
    }

    public function test_an_empty_section_is_rejected(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.active-recall', ['run' => $run])
            ->set('answers.expressions.0', 'get up')
            ->call('save')
            ->assertHasErrors(['answers']);

        $this->assertDatabaseMissing('evidences', ['mission_run_id' => $run->id, 'phase' => 'active_recall']);
    }

    public function test_at_least_one_answer_per_section_saves_evidence_and_shows_the_recap(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(2)->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        $component = Livewire::test('missions.steps.active-recall', ['run' => $run])
            ->set('answers.expressions.0', 'get up')
            ->set('answers.expressions.1', 'sleep in');
        $this->fillOtherSections($component);

        $component->call('save')
            ->assertSet('completed', true)
            ->assertSee('Active Recall complete');

        $evidence = Evidence::where('phase', 'active_recall')->first();
        $content = json_decode($evidence->content_ref, true);

        $this->assertCount(2, $content['expressions']);
        $this->assertCount(1, $content['listening_facts']);
        $this->assertCount(1, $content['present_simple_sentences']);

        // Evidence is already saved — the recap is just a courtesy screen
        // before navigating away.
        $this->assertSame('error_log', $run->fresh()->currentStepKey());

        $component->call('proceed')->assertRedirect(route('missions.show', $run->mission));
    }

    public function test_the_expressions_section_is_sized_to_what_the_learner_actually_picked(): void
    {
        $run = $this->makeRun();

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'vocabulary_builder',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode([
                'selected_words' => ['wake up', 'get up', 'have a shower'], // 3, not the seeded 5
                'examples' => [],
            ]),
        ]);

        Livewire::test('missions.steps.active-recall', ['run' => $run])
            ->assertSee('you picked 3')
            ->assertSet('answers.expressions', ['', '', '']);
    }

    public function test_the_expressions_section_falls_back_to_the_seeded_default_without_a_selection(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.active-recall', ['run' => $run])
            ->assertSee('5 expressions I learned')
            ->assertSet('answers.expressions', ['', '', '', '', '']);
    }

    public function test_answer_inputs_carry_a_draft_key_scoped_to_the_run(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.active-recall', ['run' => $run])
            ->assertSeeHtml("eos-draft:{$run->id}:active_recall:answers.expressions.0");
    }

    public function test_a_successful_save_dispatches_a_clear_draft_event(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(2)->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        $component = Livewire::test('missions.steps.active-recall', ['run' => $run])
            ->set('answers.expressions.0', 'get up')
            ->set('answers.expressions.1', 'sleep in');
        $this->fillOtherSections($component);

        $component->call('save')
            ->assertDispatched('clear-draft', prefix: "eos-draft:{$run->id}:active_recall:");
    }

    public function test_shows_a_progress_bar_per_section(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.active-recall', ['run' => $run])
            ->assertSeeHtml('h-1.5 w-full overflow-hidden rounded-full')
            ->assertSee('of 5 written')
            ->assertSee('of 3 written');
    }

    private function makeRunWithSelectedWords(): MissionRun
    {
        $run = $this->makeRun();

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'vocabulary_builder',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode(['selected_words' => ['wake up', 'get up', 'have a shower']]),
        ]);

        return $run;
    }

    /**
     * Unlike makeRunWithSelectedWords() above, this also embeds a real
     * 'vocabulary_builder' step (with 'story_words', each optionally
     * carrying 'accepted_paraphrases') in the mission's own phases —
     * checkExpression()'s acceptedParaphrasesFor() reads that content
     * directly from the mission, separately from the Evidence-based
     * $selectedWords list, so tests that exercise accepted_paraphrases
     * need both. Placed in the same 'earlier' phase as 'listening' (both
     * marked done via Evidence) so it doesn't sit between active_recall
     * and error_log in currentStepKey()'s sequential order.
     */
    private function makeRunWithVocabularyContent(array $storyWords, array $selectedWords): MissionRun
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
                            'key' => 'active_recall',
                            'instruction' => 'Without looking at the previous pages.',
                            'sections' => $this->activeRecallSections(),
                        ],
                        ['key' => 'error_log'],
                    ],
                ],
                [
                    'phase' => 'earlier',
                    'steps' => [
                        ['key' => 'vocabulary_builder', 'story_words' => $storyWords],
                        ['key' => 'listening', 'topic_summary' => 'Neil and Georgie talk about their morning routines.'],
                    ],
                ],
            ],
        ]);

        $run = MissionRun::findOrStart($learner, $mission);

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'vocabulary_builder',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode(['selected_words' => $selectedWords]),
        ]);

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'listening',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => '{}',
        ]);

        return $run;
    }

    public function test_checking_an_expression_that_matches_a_real_selected_word_is_marked_correct(): void
    {
        $run = $this->makeRunWithSelectedWords();

        Livewire::test('missions.steps.active-recall', ['run' => $run])
            ->set('answers.expressions.0', 'Get Up') // case-different, should still match
            ->call('checkExpression', 0)
            ->assertSet('expressionFeedback.0.severity', 'none');
    }

    public function test_checking_an_expression_that_does_not_match_any_real_word_is_marked_incorrect(): void
    {
        $run = $this->makeRunWithSelectedWords();

        Livewire::test('missions.steps.active-recall', ['run' => $run])
            ->set('answers.expressions.0', 'go swimming') // not one of the learner's real words
            ->call('checkExpression', 0)
            ->assertSet('expressionFeedback.0.severity', 'minor');
    }

    /**
     * Regression test for the exact-match bug — final design (3rd round):
     * an article-only difference ("skip the breakfast" for "skip
     * breakfast") is handled by normalization alone; a genuine paraphrase
     * of a DIFFERENT word ("shower" for "have a shower", "sleep late" for
     * "sleep in") is only ever accepted via an explicit, hand-authored
     * 'accepted_paraphrases' entry on that exact vocabulary item (see
     * MissionSeeder) — never inferred generically. This is a local (no
     * AI call) check — the step stays intentionally non-AI.
     */
    public function test_checking_an_expression_tolerates_common_paraphrases(): void
    {
        $run = $this->makeRunWithVocabularyContent(
            storyWords: [
                ['phrase' => 'have a shower', 'meaning' => 'to wash', 'difficulty' => 'easy', 'accepted_paraphrases' => ['shower']],
                ['phrase' => 'sleep in', 'meaning' => 'to sleep late', 'difficulty' => 'hard', 'accepted_paraphrases' => ['sleep late']],
                ['phrase' => 'skip breakfast', 'meaning' => 'to not eat breakfast', 'difficulty' => 'easy'],
            ],
            selectedWords: ['have a shower', 'sleep in', 'skip breakfast'],
        );

        Livewire::test('missions.steps.active-recall', ['run' => $run])
            ->set('answers.expressions.0', 'shower')
            ->call('checkExpression', 0)
            ->assertSet('expressionFeedback.0.severity', 'none')
            ->set('answers.expressions.1', 'sleep late')
            ->call('checkExpression', 1)
            ->assertSet('expressionFeedback.1.severity', 'none')
            ->set('answers.expressions.2', 'skip the breakfast') // article-only difference, no paraphrase needed
            ->call('checkExpression', 2)
            ->assertSet('expressionFeedback.2.severity', 'none');
    }

    /**
     * Regression test for a genuine embedded-idiom sentence built around
     * a 2-word target — accepted via the literal whole-word substring
     * check. This rule is opt-in per word now (round 4): only "come
     * round" and "once a week" carry 'allow_embedded_match' => true in
     * MissionSeeder, since they're the two words explicitly verified safe
     * (see test_an_embedded_match_is_never_credited_without_the_opt_in_flag
     * below for why this can't be a blanket default).
     */
    public function test_checking_an_expression_accepts_the_target_embedded_in_a_natural_sentence(): void
    {
        $run = $this->makeRunWithVocabularyContent(
            storyWords: [
                ['phrase' => 'come round', 'meaning' => 'to visit someone at their home', 'difficulty' => 'hard', 'allow_embedded_match' => true],
                ['phrase' => 'once a week', 'meaning' => 'happening one time every week', 'difficulty' => 'easy', 'allow_embedded_match' => true],
            ],
            selectedWords: ['come round', 'once a week'],
        );

        Livewire::test('missions.steps.active-recall', ['run' => $run])
            ->set('answers.expressions.0', 'he might come round later')
            ->call('checkExpression', 0)
            ->assertSet('expressionFeedback.0.severity', 'none')
            ->set('answers.expressions.1', 'we do it once a week usually')
            ->call('checkExpression', 1)
            ->assertSet('expressionFeedback.1.severity', 'none');
    }

    /**
     * Regression test for the false positive QA caught live in round 4:
     * target "stay in" (real M01 vocabulary, meaning "spend an evening
     * at home") must NOT be credited by "I want to stay in touch with my
     * friends" — a completely different, extremely common idiom ("stay
     * in touch") that just happens to start with the same two words.
     * "stay in" deliberately carries no 'allow_embedded_match' flag (only
     * "come round"/"once a week" do), so the substring rule never even
     * runs for it — exact match or an authored paraphrase only.
     */
    public function test_an_embedded_match_is_never_credited_without_the_opt_in_flag(): void
    {
        $run = $this->makeRunWithVocabularyContent(
            storyWords: [
                ['phrase' => 'stay in', 'meaning' => 'to spend your evening at home instead of going out', 'difficulty' => 'easy'],
            ],
            selectedWords: ['stay in'],
        );

        Livewire::test('missions.steps.active-recall', ['run' => $run])
            ->set('answers.expressions.0', 'I want to stay in touch with my friends')
            ->call('checkExpression', 0)
            ->assertSet('expressionFeedback.0.severity', 'minor');
    }

    /**
     * Regression test for the overcorrection QA caught live in round 2
     * against the real seeded vocabulary (database/seeders/MissionSeeder.php):
     * a generic content-word-stripping heuristic let a single common word
     * ("sleep", "go") turn up in a totally unrelated, much longer
     * sentence, AND (round 3's finding) collide with a neighbouring
     * vocabulary item that legitimately shares that same word ("go out"
     * vs "go to bed"/"go to sleep" — a phrasal verb's particle is
     * load-bearing, not strippable). The final design has no generic
     * word-stripping at all, so all of these correctly stay NOT matching.
     */
    public function test_an_unrelated_or_neighbouring_vocabulary_answer_does_not_match(): void
    {
        $run = $this->makeRunWithVocabularyContent(
            storyWords: [
                ['phrase' => 'sleep in', 'meaning' => 'to sleep late', 'difficulty' => 'hard', 'accepted_paraphrases' => ['sleep late']],
                ['phrase' => 'go out', 'meaning' => 'to leave home for fun', 'difficulty' => 'easy'],
                ['phrase' => 'morning person', 'meaning' => 'someone energetic in the morning', 'difficulty' => 'medium'],
                ['phrase' => 'go to bed', 'meaning' => 'to get into bed to sleep', 'difficulty' => 'easy'],
                ['phrase' => 'go to sleep', 'meaning' => 'to start sleeping', 'difficulty' => 'easy'],
            ],
            selectedWords: ['sleep in', 'go out', 'morning person'],
        );

        $component = Livewire::test('missions.steps.active-recall', ['run' => $run])
            ->set('answers.expressions.0', 'I need more sleep')
            ->call('checkExpression', 0)
            ->assertSet('expressionFeedback.0.severity', 'minor')
            ->set('answers.expressions.1', 'I go to school by bus')
            ->call('checkExpression', 1)
            ->assertSet('expressionFeedback.1.severity', 'minor')
            ->set('answers.expressions.2', 'I met a person this morning')
            ->call('checkExpression', 2)
            ->assertSet('expressionFeedback.2.severity', 'minor');

        // The neighbouring-vocabulary collision QA specifically flagged:
        // "go to bed"/"go to sleep" must not be accepted as recall of the
        // unrelated target "go out", even though both share the word "go"
        // (a fixed light-word-stripping heuristic previously let this
        // through — "go out" and "go to bed" both reduced to just "go").
        $component
            ->set('answers.expressions.3', 'go to bed')
            ->call('checkExpression', 3)
            ->assertSet('expressionFeedback.3.severity', 'minor')
            ->set('answers.expressions.4', 'go to sleep')
            ->call('checkExpression', 4)
            ->assertSet('expressionFeedback.4.severity', 'minor');
    }

    /**
     * A genuine near-miss (recalls PART of a multi-word target, not
     * enough to fully accept) gets softer, more specific copy naming the
     * real phrase, instead of a flat "doesn't match" — still not accepted
     * (severity stays 'minor'), but reads as encouraging rather than as
     * simply being told you were wrong.
     */
    public function test_a_near_miss_expression_gets_softer_copy_naming_the_real_phrase(): void
    {
        $run = $this->makeRun();

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'vocabulary_builder',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode(['selected_words' => ['skip breakfast']]),
        ]);

        Livewire::test('missions.steps.active-recall', ['run' => $run])
            ->set('answers.expressions.0', 'skip') // recalls half the idiom, not the whole thing
            ->call('checkExpression', 0)
            ->assertSet('expressionFeedback.0.severity', 'minor')
            ->assertSet('expressionFeedback.0.hint', 'Close — the exact phrase was "skip breakfast".');
    }

    /**
     * A totally unrelated answer still gets the plain "doesn't match"
     * copy — the softer "close" copy is reserved for genuine near-misses.
     */
    public function test_a_completely_unrelated_expression_keeps_the_plain_hint(): void
    {
        $run = $this->makeRunWithSelectedWords(); // 'wake up', 'get up', 'have a shower'

        Livewire::test('missions.steps.active-recall', ['run' => $run])
            ->set('answers.expressions.0', 'go swimming')
            ->call('checkExpression', 0)
            ->assertSet('expressionFeedback.0.severity', 'minor')
            ->assertSet('expressionFeedback.0.hint', "That doesn't match one of your own words — try again.");
    }

    public function test_checking_without_any_known_selection_does_not_falsely_mark_answers_wrong(): void
    {
        $run = $this->makeRun(); // no vocabulary_builder Evidence — nothing to verify against yet

        Livewire::test('missions.steps.active-recall', ['run' => $run])
            ->set('answers.expressions.0', 'get up')
            ->call('checkExpression', 0)
            ->assertSet('expressionFeedback.0', null)
            ->assertSee('Finish Vocabulary Builder first');
    }

    public function test_clicking_check_on_an_empty_expression_shows_an_error_instead_of_doing_nothing(): void
    {
        $run = $this->makeRunWithSelectedWords();

        Livewire::test('missions.steps.active-recall', ['run' => $run])
            ->call('checkExpression', 0)
            ->assertSet('expressionFeedback.0', null)
            ->assertSee('Write something first.');
    }

    public function test_incorrect_expressions_do_not_block_continue(): void
    {
        $run = $this->makeRunWithSelectedWords();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(2)->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        $component = Livewire::test('missions.steps.active-recall', ['run' => $run])
            ->set('answers.expressions.0', 'go swimming'); // wrong, but recall is self-testing, not gated
        $this->fillOtherSections($component);

        $component->call('save')
            ->assertHasNoErrors()
            ->assertSet('completed', true);
    }

    public function test_the_recap_shows_how_many_words_were_correctly_recalled(): void
    {
        $run = $this->makeRunWithSelectedWords();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(2)->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        $component = Livewire::test('missions.steps.active-recall', ['run' => $run])
            ->set('answers.expressions.0', 'wake up') // correct
            ->set('answers.expressions.1', 'go swimming'); // wrong
        $this->fillOtherSections($component);

        $component->call('save')
            ->assertSee('You correctly recalled 1 of 2 of your own words.');
    }

    public function test_read_only_mode_shows_the_recall_recap(): void
    {
        $run = $this->makeRunWithSelectedWords();

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'active_recall',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode([
                'expressions' => ['wake up', 'go swimming'],
                'listening_facts' => ['They talked about morning routines.'],
                'present_simple_sentences' => ['I wake up at seven.'],
            ]),
        ]);

        Livewire::test('missions.steps.active-recall', ['run' => $run, 'readOnly' => true])
            ->assertSee('You correctly recalled 1 of 2 of your own words.')
            ->assertDontSee('Continue');
    }

    public function test_checking_a_listening_fact_uses_the_real_topic_summary(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(fn ($_messages, $systemPrompt) => str_contains($systemPrompt, 'B1-level listening')
                    || str_contains($_messages[0]['text'] ?? '', 'morning routines'))
                ->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        Livewire::test('missions.steps.active-recall', ['run' => $run])
            ->set('answers.listening_facts.0', 'They talked about morning routines.')
            ->call('checkListeningFact', 0)
            ->assertSet('aiFeedback.listening_facts.0.severity', 'none');
    }

    public function test_checking_a_present_simple_sentence_flags_the_wrong_tense(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->once()
                ->andReturn(json_encode(['severity' => 'major', 'hint' => 'That is past tense, not present simple.']));
        });

        Livewire::test('missions.steps.active-recall', ['run' => $run])
            ->set('answers.present_simple_sentences.0', 'I woke up at seven.')
            ->call('checkPresentSimpleSentence', 0)
            ->assertSet('aiFeedback.present_simple_sentences.0.severity', 'major');
    }

    /**
     * Regression test for the exact bug class this generalization fixes:
     * before checkOpenField() dispatched generically off a declared
     * 'judgment' key, ANY section besides the two hardcoded ones
     * ('listening_facts', 'present_simple_sentences') silently rendered as
     * a plain input with NO Check button and NO AI verdict — a learner
     * could type anything and it would be accepted with no feedback at
     * all. This proves a THIRD, differently-declared section (M02's real
     * present_continuous_sentences, with its own real judgment text) gets
     * a real Check button in the rendered markup AND a real SentenceChecker
     * call carrying that exact judgment text — not just that the one
     * pre-existing present_simple_sentences case still works.
     */
    public function test_a_newly_declared_grammar_check_section_gets_a_real_check_button_and_ai_call(): void
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M02',
            'title' => 'Test Mission',
            'module' => 'Me',
            'outcome' => 'Outcome.',
            'phases' => [
                [
                    'phase' => 'mission',
                    'steps' => [
                        [
                            'key' => 'active_recall',
                            'instruction' => 'Without looking at the previous pages.',
                            'sections' => [
                                ...$this->activeRecallSections(),
                                [
                                    'key' => 'present_continuous_sentences',
                                    'label' => '1 Present Continuous sentence about what someone is doing these days',
                                    'count' => 1,
                                    'judgment' => 'Judge whether the learner wrote a true, natural sentence correctly '
                                        .'using present continuous tense for something happening now or temporarily '
                                        .'(not a general habit).',
                                ],
                            ],
                        ],
                        ['key' => 'error_log'],
                    ],
                ],
                [
                    'phase' => 'earlier',
                    'steps' => [
                        ['key' => 'listening', 'topic_summary' => 'Neil and Georgie talk about their morning routines.'],
                    ],
                ],
            ],
        ]);
        $run = MissionRun::findOrStart($learner, $mission);
        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'listening',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => '{}',
        ]);

        // Proves the bug is fixed at the rendering level: a real Check
        // button (wired to the generic checkGrammarSentence dispatch,
        // carrying this section's own key) exists for this brand-new
        // section — not a plain, silently-accepting input.
        Livewire::test('missions.steps.active-recall', ['run' => $run])
            ->assertSeeHtml("\$wire.checkGrammarSentence(0, 'present_continuous_sentences')");

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(fn ($_messages, $systemPrompt) => str_contains(
                    $systemPrompt,
                    'using present continuous tense for something happening now or temporarily'
                ))
                ->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        Livewire::test('missions.steps.active-recall', ['run' => $run])
            ->set('answers.present_continuous_sentences.0', 'She is studying for her exam this week.')
            ->call('checkGrammarSentence', 0, 'present_continuous_sentences')
            ->assertSet('aiFeedback.present_continuous_sentences.0.severity', 'none');
    }

    public function test_clicking_check_on_an_empty_listening_fact_shows_an_error(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldNotReceive('chat'));

        Livewire::test('missions.steps.active-recall', ['run' => $run])
            ->call('checkListeningFact', 0)
            ->assertSet('checkErrors.listening_facts.0', 'Write something first.')
            ->assertSee('Write something first.');
    }

    public function test_a_major_verdict_on_an_ai_checked_section_does_not_block_continue(): void
    {
        $run = $this->makeRunWithSelectedWords();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->times(2)
                ->andReturn(json_encode(['severity' => 'major', 'hint' => 'Just a fragment.']));
        });

        $component = Livewire::test('missions.steps.active-recall', ['run' => $run])
            ->set('answers.expressions.0', 'wake up');
        $this->fillOtherSections($component);

        $component->call('save')
            ->assertHasNoErrors()
            ->assertSet('completed', true)
            ->assertSee('0 of 1 things you recalled about the listening were clear and on-topic.')
            ->assertSee('0 of 1 sentences correctly used the present simple.');
    }

    /**
     * Logs the same error category against 2 separate, already-finished
     * mission runs for this learner — the minimum for
     * User::recurringErrorCategories() to flag it (see UserRecurringErrorsTest).
     */
    private function seedRecurringError(User $learner): void
    {
        foreach (['M-OLD-1', 'M-OLD-2'] as $i => $code) {
            $mission = Mission::create([
                'code' => $code,
                'title' => 'Earlier Mission',
                'module' => 'Me',
                'outcome' => 'Outcome.',
                'phases' => [],
            ]);
            $run = MissionRun::findOrStart($learner, $mission);

            ErrorLogItem::create([
                'mission_run_id' => $run->id,
                'error' => "He walk fast {$i}.",
                'correction' => "He walks fast {$i}.",
                'category' => 'third-person-s',
            ]);
        }

        // Normally done by Error Log's save() the moment recurrence is
        // detected (see User::syncErrorPatternReview()) — done directly
        // here since these ErrorLogItem rows are seeded straight into the
        // database, bypassing that step entirely.
        $learner->syncErrorPatternReview('third-person-s', 'He walk fast 1.', 'He walks fast 1.');
    }

    public function test_no_spaced_practice_card_shows_without_a_recurring_error(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.active-recall', ['run' => $run])
            ->assertDontSee('Spaced practice');
    }

    public function test_a_recurring_error_shows_the_spaced_practice_card_with_the_concrete_example(): void
    {
        $learner = User::factory()->create();
        $this->seedRecurringError($learner);
        $run = $this->makeRun($learner);

        Livewire::test('missions.steps.active-recall', ['run' => $run])
            ->assertSee('Spaced practice')
            ->assertSee('He walk fast 1.')
            ->assertSee('He walks fast 1.');
    }

    public function test_checking_the_spaced_practice_sentence_grounds_the_ai_in_the_real_mistake(): void
    {
        $learner = User::factory()->create();
        $this->seedRecurringError($learner);
        $run = $this->makeRun($learner);

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(fn ($_messages, $systemPrompt) => str_contains($systemPrompt, 'He walk fast 1.')
                    && str_contains($systemPrompt, 'He walks fast 1.'))
                ->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        Livewire::test('missions.steps.active-recall', ['run' => $run])
            ->set('recurringPracticeAnswer', 'She works hard every day.')
            ->call('checkRecurringPractice')
            ->assertSet('recurringPracticeFeedback.severity', 'none');
    }

    public function test_leaving_the_spaced_practice_blank_never_blocks_continue(): void
    {
        $learner = User::factory()->create();
        $this->seedRecurringError($learner);
        $run = $this->makeRun($learner);

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(2)->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        $component = Livewire::test('missions.steps.active-recall', ['run' => $run])
            ->set('answers.expressions.0', 'get up');
        $this->fillOtherSections($component);

        $component->call('save')
            ->assertHasNoErrors()
            ->assertSet('completed', true);

        $this->assertDatabaseMissing('evidences', [
            'mission_run_id' => $run->id,
            'phase' => 'active_recall_spaced_practice',
        ]);
    }

    public function test_an_answered_spaced_practice_is_saved_as_its_own_evidence_phase(): void
    {
        $learner = User::factory()->create();
        $this->seedRecurringError($learner);
        $run = $this->makeRun($learner);

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(3)->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        $component = Livewire::test('missions.steps.active-recall', ['run' => $run])
            ->set('answers.expressions.0', 'get up')
            ->set('recurringPracticeAnswer', 'She works hard every day.');
        $this->fillOtherSections($component);

        $component->call('save')->assertSet('completed', true);

        $evidence = Evidence::where('mission_run_id', $run->id)
            ->where('phase', 'active_recall_spaced_practice')
            ->first();

        $this->assertNotNull($evidence);
        $content = json_decode($evidence->content_ref, true);
        $this->assertSame('third-person-s', $content['category']);
        $this->assertSame('She works hard every day.', $content['answer']);

        // This extra phase is never a real step key, so it can't block or
        // advance mission progress.
        $this->assertSame('error_log', $run->fresh()->currentStepKey());
    }

    public function test_completing_the_spaced_practice_advances_its_real_review_schedule(): void
    {
        $learner = User::factory()->create();
        $this->seedRecurringError($learner);
        $run = $this->makeRun($learner);

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(3)->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        $component = Livewire::test('missions.steps.active-recall', ['run' => $run])
            ->set('answers.expressions.0', 'get up')
            ->set('recurringPracticeAnswer', 'She works hard every day.');
        $this->fillOtherSections($component);

        $component->call('save');

        $review = ErrorPatternReview::where('learner_id', $learner->id)->firstOrFail();
        $this->assertSame(1, $review->repetitions);
        $this->assertFalse($review->isDue());
    }

    public function test_no_spaced_practice_card_when_the_recurring_pattern_is_not_yet_due(): void
    {
        $learner = User::factory()->create();
        $this->seedRecurringError($learner);
        ErrorPatternReview::where('learner_id', $learner->id)->update(['next_review_at' => now()->addWeek()]);
        $run = $this->makeRun($learner);

        Livewire::test('missions.steps.active-recall', ['run' => $run])
            ->assertDontSee('Spaced practice');
    }
}
