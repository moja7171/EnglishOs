<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use App\Services\GeminiClient;
use App\Services\PexelsClient;
use Database\Seeders\MissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReadingComprehensionStepTest extends TestCase
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
                    'phase' => 'practice',
                    'steps' => [
                        [
                            'key' => 'reading_comprehension',
                            'hook' => 'Meet Aisha — her morning looks a lot like yours.',
                            'passage_title' => 'Meet Aisha',
                            'passage' => 'Aisha lives in Manchester and works at a hospital. She wakes up at six.',
                            'topic_summary' => "A short profile of Aisha's daily routine.",
                            'comprehension_check' => [
                                ['statement' => 'Aisha works at a hospital.', 'correct' => true],
                                ['statement' => 'Aisha wakes up at nine.', 'correct' => false],
                            ],
                            'questions' => [
                                "What is Aisha's morning usually like on a weekday?",
                                "What is different about Aisha's Sunday, compared to a normal weekday?",
                            ],
                        ],
                        ['key' => 'writing'],
                    ],
                ],
            ],
        ]);

        return MissionRun::findOrStart($learner, $mission);
    }

    private function makeRunWithImageQuery(): MissionRun
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [[
                'phase' => 'practice',
                'steps' => [[
                    'key' => 'reading_comprehension',
                    'passage_title' => 'Meet Aisha',
                    'passage' => 'Aisha lives in Manchester.',
                    'image_query' => 'young woman morning portrait smiling',
                    'questions' => ['What is Aisha like?'],
                ]],
            ]],
        ]);

        $this->actingAs($learner);

        return MissionRun::findOrStart($learner, $mission);
    }

    private function makeRunWithChoiceQuestions(): MissionRun
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [[
                'phase' => 'practice',
                'steps' => [
                    [
                        'key' => 'reading_comprehension',
                        'passage' => 'Aisha lives in Manchester and works at a hospital. She wakes up at six and feels exhausted after a long shift.',
                        'highlighted_phrases' => [['phrase' => 'exhausted', 'type' => 'new', 'definition' => 'very tired', 'pos' => 'adjective', 'example' => 'I was exhausted after the long shift.']],
                        'comprehension_check' => [['statement' => 'Aisha works at a hospital.', 'correct' => true]],
                        'questions' => ['What is Aisha like?'],
                        'choice_questions' => [
                            ['prompt' => 'What time does Aisha wake up?', 'options' => ['At seven', 'At six', 'At ten'], 'correct' => 1, 'difficulty' => 'easy'],
                            ['prompt' => 'Where does Aisha work?', 'options' => ['At a school', 'At a cafe', 'At a hospital'], 'correct' => 2, 'difficulty' => 'medium'],
                        ],
                    ],
                    ['key' => 'writing'],
                ],
            ]],
        ]);
        $this->actingAs($learner);

        return MissionRun::findOrStart($learner, $mission);
    }

    public function test_a_mission_with_choice_questions_asks_them_as_multiple_choice_not_as_written_answers(): void
    {
        $run = $this->makeRunWithChoiceQuestions();

        Livewire::test('missions.steps.reading-comprehension', ['run' => $run])
            ->assertSee('What time does Aisha wake up?')
            ->assertSee('At six')
            ->assertSee('Choose the answer')
            ->assertDontSee('Answer in a full sentence')
            ->assertDontSee('What is Aisha like?');
    }

    public function test_finishing_the_multiple_choice_round_saves_the_score_and_moves_on_without_asking_the_ai(): void
    {
        $run = $this->makeRunWithChoiceQuestions();
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldNotReceive('chat'));

        Livewire::test('missions.steps.reading-comprehension', ['run' => $run])
            ->call('finishChoices', 2, 2)
            ->assertRedirect(route('missions.show', $run->mission));

        $content = json_decode(Evidence::where('phase', 'reading_comprehension')->sole()->content_ref, true);
        $this->assertSame(['correct' => 2, 'total' => 2], $content['choices']);
        $this->assertSame('writing', $run->fresh()->currentStepKey());
    }

    public function test_every_built_mission_asks_its_reading_questions_as_well_formed_multiple_choice(): void
    {
        $this->seed(MissionSeeder::class);

        foreach (Mission::orderBy('code')->get() as $mission) {
            $questions = $mission->stepContent('reading_comprehension')['choice_questions'] ?? [];

            $this->assertCount(3, $questions, "{$mission->code} has three multiple-choice questions");

            foreach ($questions as $question) {
                $this->assertCount(3, $question['options'], "{$mission->code}: {$question['prompt']}");
                $this->assertCount(3, array_unique($question['options']), "{$mission->code}: options differ");
                $this->assertArrayHasKey($question['correct'], $question['options'], "{$mission->code}: the right answer is one of the options");
            }
        }
    }

    public function test_it_shows_the_passage(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.reading-comprehension', ['run' => $run])
            ->assertSee('Meet Aisha')
            ->assertSee('Aisha lives in Manchester and works at a hospital.');
    }

    public function test_the_passage_and_each_question_get_their_own_screen(): void
    {
        $run = $this->makeRun();

        $html = Livewire::test('missions.steps.reading-comprehension', ['run' => $run])->html();

        // Lesson design 9: the passage alone, then one question per screen.
        $this->assertStringContainsString('screen === 1', $html);
        $this->assertStringContainsString('Answer · 1 of 2', $html);
        $this->assertStringContainsString('Answer · 2 of 2', $html);
    }

    public function test_check_waits_for_an_answer_in_the_box(): void
    {
        $run = $this->makeRun();

        // Check waits for something in the box, read from the live value of
        // the box itself — so a restored draft or a said sentence counts too.
        Livewire::test('missions.steps.reading-comprehension', ['run' => $run])
            ->assertSeeHtml('$wire.get(&#039;answers.0&#039;)')
            ->assertSeeHtml('$wire.get(&#039;answers.1&#039;)');
    }

    public function test_a_passage_with_an_image_query_shows_a_header_image(): void
    {
        $run = $this->makeRunWithImageQuery();

        $this->mock(PexelsClient::class, fn ($mock) => $mock->shouldReceive('imageUrlFor')
            ->with('M01-reading', 'young woman morning portrait smiling')
            ->once()
            ->andReturn('http://localhost/storage/vocabulary-images/m01-reading.jpg'));

        Livewire::test('missions.steps.reading-comprehension', ['run' => $run])
            ->assertSeeHtml('http://localhost/storage/vocabulary-images/m01-reading.jpg');
    }

    public function test_a_passage_without_an_image_query_shows_no_header_image(): void
    {
        $run = $this->makeRun();

        $this->mock(PexelsClient::class, function ($mock) {
            $mock->shouldNotReceive('imageUrlFor');
            $mock->shouldReceive('cachedImageUrl')->andReturn(null);
        });

        Livewire::test('missions.steps.reading-comprehension', ['run' => $run])
            ->assertDontSeeHtml('<img');
    }

    public function test_a_failed_image_fetch_never_breaks_the_step(): void
    {
        $run = $this->makeRunWithImageQuery();

        $this->mock(PexelsClient::class, function ($mock) {
            $mock->shouldReceive('imageUrlFor')->andReturn(null);
            $mock->shouldReceive('cachedImageUrl')->andReturn(null);
        });

        Livewire::test('missions.steps.reading-comprehension', ['run' => $run])
            ->assertOk()
            ->assertDontSeeHtml('<img');
    }

    public function test_it_shows_an_ungraded_true_false_warm_up(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.reading-comprehension', ['run' => $run])
            ->assertSee('Aisha works at a hospital.')
            ->assertSee('True')
            ->assertSee('False');
    }

    public function test_clicking_check_on_an_empty_answer_shows_an_error(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldNotReceive('chat');
        });

        Livewire::test('missions.steps.reading-comprehension', ['run' => $run])
            ->call('checkOne', 0)
            ->assertSet('checkErrors.0', 'Write something first.')
            ->assertSee('Write something first.');
    }

    public function test_both_questions_must_be_answered_before_continuing(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.reading-comprehension', ['run' => $run])
            ->set('answers.0', 'She wakes up early and never skips breakfast.')
            ->call('save')
            ->assertHasErrors(['answers']);

        $this->assertDatabaseCount('evidences', 0);
    }

    public function test_a_major_ai_verdict_blocks_continue(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->once()
                ->andReturn(json_encode(['severity' => 'major', 'hint' => 'That is off-topic.']))
                ->ordered();
            $mock->shouldReceive('chat')
                ->once()
                ->andReturn(json_encode(['severity' => 'none', 'hint' => '']))
                ->ordered();
        });

        Livewire::test('missions.steps.reading-comprehension', ['run' => $run])
            ->set('answers.0', 'I like pizza on Fridays.')
            ->set('answers.1', 'On Sunday she sleeps in until ten.')
            ->call('save')
            ->assertHasErrors(['answers']);

        $this->assertDatabaseCount('evidences', 0);
    }

    public function test_a_valid_submission_stores_evidence_and_advances(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->twice()
                ->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        $component = Livewire::test('missions.steps.reading-comprehension', ['run' => $run])
            ->set('answers.0', 'She wakes up early and never skips breakfast.')
            ->set('answers.1', 'On Sunday she sleeps in and does not set an alarm.')
            ->call('save')
            ->assertRedirect(route('missions.show', $run->mission));

        $this->assertDatabaseCount('evidences', 1);
        $this->assertDatabaseHas('evidences', ['mission_run_id' => $run->id, 'phase' => 'reading_comprehension']);

        $evidence = Evidence::where('phase', 'reading_comprehension')->first();
        $content = json_decode($evidence->content_ref, true);
        $this->assertSame('She wakes up early and never skips breakfast.', $content['answers'][0]);

        $this->assertSame('writing', $run->fresh()->currentStepKey());
    }

    /**
     * Epic H: severity is now persisted alongside each answer so a later
     * attempt (?retry=1) can be compared against this one, and the
     * immediate score is shown right on this completion.
     */
    public function test_each_answers_severity_is_saved(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->twice()->andReturn(
                json_encode(['severity' => 'none', 'hint' => '']),
                json_encode(['severity' => 'minor', 'hint' => 'Small tweak.']),
            );
        });

        Livewire::test('missions.steps.reading-comprehension', ['run' => $run])
            ->set('answers.0', 'She wakes up early and never skips breakfast.')
            ->set('answers.1', 'On Sunday she sleeps in, a bit differently.')
            ->call('save');

        $evidence = Evidence::where('phase', 'reading_comprehension')->first();
        $content = json_decode($evidence->content_ref, true);
        $this->assertSame(['none', 'minor'], $content['severities']);
    }

    public function test_three_failed_checks_offer_to_reveal_the_correction(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(3)->andReturn(json_encode(['severity' => 'major', 'hint' => 'Try again.']));
        });

        $component = Livewire::test('missions.steps.reading-comprehension', ['run' => $run])
            ->set('answers.0', 'pizza');

        $component->call('checkOne', 0);
        $component->call('checkOne', 0);
        $component->call('checkOne', 0)
            ->assertSet('offerReveal.0', true);
    }

    public function test_a_seeded_difficulty_tag_is_threaded_through_to_the_comprehension_cards(): void
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [[
                'phase' => 'practice',
                'steps' => [[
                    'key' => 'reading_comprehension',
                    'passage' => 'Aisha lives in Manchester.',
                    'comprehension_check' => [
                        ['statement' => 'Aisha works at a hospital.', 'correct' => true, 'difficulty' => 'easy'],
                        ['statement' => 'She goes shopping on Saturdays.', 'correct' => true, 'difficulty' => 'hard'],
                    ],
                    'questions' => ['What is Aisha like?'],
                ]],
            ]],
        ]);
        $this->actingAs($learner);
        $run = MissionRun::findOrStart($learner, $mission);

        $cards = Livewire::test('missions.steps.reading-comprehension', ['run' => $run])
            ->instance()
            ->comprehensionCards();

        $this->assertSame('easy', $cards[0]['difficulty']);
        $this->assertSame('hard', $cards[1]['difficulty']);
    }

    public function test_reused_and_new_phrases_are_highlighted_with_distinct_tooltips(): void
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [[
                'phase' => 'practice',
                'steps' => [[
                    'key' => 'reading_comprehension',
                    'passage' => 'Aisha wakes up at six and feels exhausted after a long shift.',
                    'highlighted_phrases' => [
                        ['phrase' => 'wakes up', 'type' => 'reused'],
                        ['phrase' => 'exhausted', 'type' => 'new', 'definition' => 'very tired'],
                    ],
                    'questions' => ['What is Aisha like?'],
                ]],
            ]],
        ]);
        $this->actingAs($learner);
        $run = MissionRun::findOrStart($learner, $mission);

        Livewire::test('missions.steps.reading-comprehension', ['run' => $run])
            ->assertSeeHtml('<mark')
            ->assertSeeHtml('title="این کلمه رو توی New Words دیدی"')
            ->assertSeeHtml('title="very tired"')
            // The rest of the passage around the highlights must still render, unescaped-HTML-safe.
            ->assertSee('Aisha')
            ->assertSee('after a long shift');
    }

    public function test_a_passage_without_highlighted_phrases_renders_plainly(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.reading-comprehension', ['run' => $run])
            ->assertDontSeeHtml('<mark')
            ->assertDontSee('From New Words');
    }

    public function test_the_passage_has_a_colour_legend_and_a_listen_button(): void
    {
        $run = $this->makeRunWithNewWords();

        Livewire::test('missions.steps.reading-comprehension', ['run' => $run])
            ->assertSee('From New Words')
            ->assertSee('New word')
            ->assertSee('Listen')
            ->assertSeeHtml('data-text="Aisha wakes up at six and feels exhausted after a long shift."');
    }

    public function test_the_passage_is_cut_into_sentences_for_the_follow_along_and_marks_have_no_underline(): void
    {
        $run = $this->makeRunWithNewWords();

        Livewire::test('missions.steps.reading-comprehension', ['run' => $run])
            ->assertSeeHtml('data-sentence="0"')
            ->assertSeeHtml('Aisha <mark')
            ->assertDontSeeHtml('underline decoration-accent')
            ->assertDontSeeHtml('underline decoration-success');
    }

    public function test_a_phrase_stays_inside_its_own_sentence(): void
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [[
                'phase' => 'practice',
                'steps' => [[
                    'key' => 'reading_comprehension',
                    'passage' => 'Aisha wakes up at six. She feels exhausted! Is she tired?',
                    'highlighted_phrases' => [['phrase' => 'exhausted', 'type' => 'new', 'definition' => 'very tired']],
                    'questions' => ['What is Aisha like?'],
                ]],
            ]],
        ]);
        $this->actingAs($learner);
        $run = MissionRun::findOrStart($learner, $mission);

        Livewire::test('missions.steps.reading-comprehension', ['run' => $run])
            ->assertSeeHtml('data-sentence="2"')
            ->assertDontSeeHtml('data-sentence="3"')
            ->assertSeeHtml('<span data-sentence="1" class="box-decoration-clone">She feels <mark');
    }

    public function test_a_passage_without_its_own_photo_falls_back_to_the_mission_photo_behind_the_card(): void
    {
        $run = $this->makeRun();

        $this->mock(PexelsClient::class, fn ($mock) => $mock->shouldReceive('cachedImageUrl')
            ->with('M01-brief')
            ->andReturn('http://localhost/storage/vocabulary-images/m01-brief.jpg'));

        Livewire::test('missions.steps.reading-comprehension', ['run' => $run])
            ->assertSeeHtml('http://localhost/storage/vocabulary-images/m01-brief.jpg')
            ->assertSeeHtml('x-on:eos-follow="follow($event.detail.index)"');
    }

    private function makeRunWithNewWords(): MissionRun
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [[
                'phase' => 'practice',
                'steps' => [[
                    'key' => 'reading_comprehension',
                    'passage' => 'Aisha wakes up at six and feels exhausted after a long shift.',
                    'highlighted_phrases' => [
                        ['phrase' => 'wakes up', 'type' => 'reused'],
                        ['phrase' => 'exhausted', 'type' => 'new', 'definition' => 'very tired', 'pos' => 'adjective', 'example' => 'I was exhausted after the long shift.'],
                    ],
                    'questions' => ['What is Aisha like?'],
                ]],
            ]],
        ]);
        $this->actingAs($learner);

        return MissionRun::findOrStart($learner, $mission);
    }

    /**
     * Epic F: new words get their own section below the passage — plain
     * meaning + part of speech + example, not just a hover-only tooltip
     * (never worked on mobile — same fix already made for Vocabulary
     * Builder).
     */
    public function test_new_words_are_marked_in_the_passage_with_no_separate_list(): void
    {
        $run = $this->makeRunWithNewWords();

        // Lesson design 9: no separate list — the new words are marked in
        // the passage, a tap shows the meaning.
        Livewire::test('missions.steps.reading-comprehension', ['run' => $run])
            ->assertDontSee('New words')
            ->assertSeeHtml('title="very tired"');
    }

    /**
     * Epic F: new words hook into My Words the same way Vocabulary
     * Builder/Listening already do — offered, never silently enrolled.
     */
    public function test_finishing_adds_the_new_words_to_my_words(): void
    {
        $run = $this->makeRunWithNewWords();

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn(json_encode(['severity' => 'none', 'hint' => ''])));

        $component = Livewire::test('missions.steps.reading-comprehension', ['run' => $run])
            ->set('answers.0', 'She seems tired but hardworking.')
            ->call('save')
            ->assertRedirect(route('missions.show', $run->mission));

        $this->assertDatabaseHas('vocabulary_words', [
            'learner_id' => $run->learner_id,
            'word' => 'exhausted',
            'meaning' => 'very tired',
        ]);
    }

    public function test_read_only_mode_reloads_the_saved_answers_and_hides_the_warm_up(): void
    {
        $run = $this->makeRun();

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'reading_comprehension',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode(['answers' => [
                'She wakes up early and never skips breakfast.',
                'On Sunday she sleeps in and does not set an alarm.',
            ]]),
        ]);

        Livewire::test('missions.steps.reading-comprehension', ['run' => $run, 'readOnly' => true])
            ->assertSet('answers.0', 'She wakes up early and never skips breakfast.')
            ->assertSet('answers.1', 'On Sunday she sleeps in and does not set an alarm.')
            ->assertDontSee('Quick check')
            ->assertDontSeeHtml('x-draft');
    }

    public function test_verdicts_survive_leaving_so_save_does_not_check_the_answers_again(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->twice()->andReturn(json_encode(['severity' => 'none', 'hint' => 'Clear.'])));

        $answers = ['She wakes up early and never skips breakfast.', 'She works at a hospital in Manchester.'];
        $first = Livewire::test('missions.steps.reading-comprehension', ['run' => $run]);

        foreach ($answers as $index => $answer) {
            $first->set("answers.$index", $answer)->call('checkOne', $index);
        }

        $second = Livewire::test('missions.steps.reading-comprehension', ['run' => $run]);

        foreach ($answers as $index => $answer) {
            $second->set("answers.$index", $answer);
        }

        $second->call('save')->assertHasNoErrors();
    }
}
