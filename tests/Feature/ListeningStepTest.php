<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use App\Models\VocabularyWord;
use App\Services\GeminiClient;
use App\Services\GroqClient;
use App\Services\PexelsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Mission structure redesign, Epic C: Day 1's Listening is now exactly 3
 * sub-steps — first listen + true/false, second listen + gap-fill from a
 * word bank (no free typing), and full transcript + mandatory, leniently
 * AI-graded shadowing. The old 6-sentence gist/expression free-writing is
 * gone entirely.
 */
class ListeningStepTest extends TestCase
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
                    'phase' => 'foundation',
                    'steps' => [
                        [
                            'key' => 'listening',
                            'source' => 'BBC Learning English — Real Easy English: Mornings',
                            'audio_url' => 'http://localhost/storage/missions/m01/mornings.mp3',
                            'topic_summary' => 'Neil and Georgie talk about their morning routines.',
                            'target_phrases' => [
                                ['phrase' => 'sleep in', 'meaning' => 'to stay in bed and sleep later than usual', 'gap_before' => 'I like to ', 'gap_after' => ' at weekends.'],
                                ['phrase' => 'morning person', 'meaning' => 'someone with lots of energy in the morning', 'gap_before' => "I'm a ", 'gap_after' => '.'],
                            ],
                            'shadow_lines' => ['Do you **like** to **get up** early?', 'Are you a **morning person**?'],
                        ],
                        ['key' => 'grammar_in_context'],
                    ],
                ],
            ],
        ]);

        $this->actingAs($learner);

        return MissionRun::findOrStart($learner, $mission);
    }

    private function mockLenientShadowCheck(int $times = 2): void
    {
        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->times($times)->andReturn('Do you like to get up early?'));
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->times($times)->andReturn(json_encode(['severity' => 'none', 'hint' => ''])));
    }

    private function shadowEveryLine($component): void
    {
        $component
            ->set('shadowRecordings.0', UploadedFile::fake()->create('shadow-0.webm', 200, 'audio/webm'))
            ->call('checkShadowLine', 0)
            ->set('shadowRecordings.1', UploadedFile::fake()->create('shadow-1.webm', 200, 'audio/webm'))
            ->call('checkShadowLine', 1);
    }

    private function fillGapsCorrectly($component): void
    {
        $component
            ->set('gapFillSelections.0', 'sleep in')
            ->call('checkGapFill', 0)
            ->set('gapFillSelections.1', 'morning person')
            ->call('checkGapFill', 1);
    }

    public function test_the_wrap_up_offers_discussing_the_topic_with_a_mutual_friend(): void
    {
        $run = $this->makeRun();
        $friend = User::factory()->create(['name' => 'Priya']);
        $run->learner->follow($friend);
        $friend->acceptFollowRequest($run->learner);

        Livewire::test('missions.steps.listening', ['run' => $run])
            ->assertSee('Discuss this with a friend')
            ->assertSee('Priya');
    }

    public function test_the_custom_audio_player_renders_with_skip_and_seek_controls(): void
    {
        $run = $this->makeRun();

        $html = Livewire::test('missions.steps.listening', ['run' => $run])->html();

        $this->assertStringContainsString('http://localhost/storage/missions/m01/mornings.mp3', $html);
        $this->assertStringContainsString('skip(-10)', $html);
        $this->assertStringContainsString('skip(10)', $html);
        $this->assertStringContainsString('togglePlay()', $html);
        $this->assertStringContainsString('type="range"', $html);
        $this->assertStringContainsString('download', $html);
    }

    public function test_the_word_bank_lists_every_target_phrase(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.listening', ['run' => $run])
            ->assertSee('sleep in')
            ->assertSee('morning person');
    }

    public function test_gap_fill_gives_feedback_and_only_a_correct_selection_counts(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.listening', ['run' => $run])
            ->set('gapFillSelections.0', 'sleep in')
            ->call('checkGapFill', 0)
            ->assertSet('gapFillFeedback.0.severity', 'none');

        Livewire::test('missions.steps.listening', ['run' => $run])
            ->set('gapFillSelections.0', 'morning person') // wrong gap
            ->call('checkGapFill', 0)
            ->assertSet('gapFillFeedback.0.severity', 'minor');
    }

    public function test_gap_fill_must_be_all_correct_before_saving(): void
    {
        $run = $this->makeRun();
        $this->mockLenientShadowCheck();

        $component = Livewire::test('missions.steps.listening', ['run' => $run]);
        $this->shadowEveryLine($component);

        // Only gap 0 answered, and incorrectly.
        $component->set('gapFillSelections.0', 'morning person')->call('checkGapFill', 0);

        $component->call('save')->assertHasErrors(['gapFill']);
        $this->assertDatabaseCount('evidences', 0);
    }

    public function test_saving_requires_the_required_number_of_shadowed_lines(): void
    {
        $run = $this->makeRun();

        $component = Livewire::test('missions.steps.listening', ['run' => $run]);
        $this->fillGapsCorrectly($component);

        // No shadowing attempted at all.
        $component->call('save')->assertHasErrors(['shadowRecordings']);
        $this->assertDatabaseCount('evidences', 0);
    }

    public function test_saving_records_evidence_and_advances_the_run(): void
    {
        $run = $this->makeRun();
        $this->mockLenientShadowCheck();

        $component = Livewire::test('missions.steps.listening', ['run' => $run]);
        $this->fillGapsCorrectly($component);
        $this->shadowEveryLine($component);

        $component
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('completed', true) // shows the language recap first
            ->assertOk()
            ->call('proceed')
            ->assertRedirect(route('missions.show', $run->mission));

        $textEvidence = Evidence::where('phase', 'listening')->where('type', Evidence::TYPE_TEXT)->first();
        $this->assertNotNull($textEvidence);
        $this->assertSame(2, Evidence::where('phase', 'listening')->where('type', Evidence::TYPE_AUDIO)->count());
        $this->assertSame('grammar_in_context', $run->fresh()->currentStepKey());
    }

    public function test_a_major_shadow_verdict_does_not_count_toward_the_requirement(): void
    {
        $run = $this->makeRun();
        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->once()->andReturn('completely unrelated'));
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn(json_encode(['severity' => 'major', 'hint' => 'Try that line again.'])));

        $component = Livewire::test('missions.steps.listening', ['run' => $run])
            ->set('shadowRecordings.0', UploadedFile::fake()->create('shadow-0.webm', 200, 'audio/webm'))
            ->call('checkShadowLine', 0);

        $this->assertSame(0, $component->instance()->shadowedCount());
        $component->assertSee('Try that line again.');
    }

    public function test_adding_to_the_notebook_enrolls_every_target_phrase(): void
    {
        $run = $this->makeRun();
        $this->mockLenientShadowCheck();

        $component = Livewire::test('missions.steps.listening', ['run' => $run]);
        $this->fillGapsCorrectly($component);
        $this->shadowEveryLine($component);

        $component->call('save')->call('addWordsToNotebook')->assertSet('trackedWords', true);

        $this->assertSame(2, VocabularyWord::where('learner_id', $run->learner_id)->count());

        $sleepIn = VocabularyWord::where('learner_id', $run->learner_id)->where('word', 'sleep in')->firstOrFail();
        $this->assertSame($run->id, $sleepIn->source_mission_run_id);
        $this->assertSame('to stay in bed and sleep later than usual', $sleepIn->meaning);
        $this->assertTrue($sleepIn->isDue());
    }

    public function test_words_are_not_enrolled_until_add_to_notebook_is_pressed(): void
    {
        $run = $this->makeRun();
        $this->mockLenientShadowCheck();

        $component = Livewire::test('missions.steps.listening', ['run' => $run]);
        $this->fillGapsCorrectly($component);
        $this->shadowEveryLine($component);
        $component->call('save');

        $this->assertSame(0, VocabularyWord::where('learner_id', $run->learner_id)->count());
    }

    public function test_unchecking_a_target_phrase_leaves_it_out_of_the_notebook(): void
    {
        $run = $this->makeRun();
        $this->mockLenientShadowCheck();

        $component = Livewire::test('missions.steps.listening', ['run' => $run]);
        $this->fillGapsCorrectly($component);
        $this->shadowEveryLine($component);

        $component
            ->call('save')
            ->set('wordsToTrack.0', false) // "sleep in"
            ->call('addWordsToNotebook');

        $this->assertSame(1, VocabularyWord::where('learner_id', $run->learner_id)->count());
        $this->assertDatabaseMissing('vocabulary_words', ['learner_id' => $run->learner_id, 'word' => 'sleep in']);
        $this->assertDatabaseHas('vocabulary_words', ['learner_id' => $run->learner_id, 'word' => 'morning person']);
    }

    public function test_completing_the_step_shows_a_language_recap_before_proceeding(): void
    {
        $run = $this->makeRun();
        $this->mockLenientShadowCheck();

        $component = Livewire::test('missions.steps.listening', ['run' => $run]);
        $this->fillGapsCorrectly($component);
        $this->shadowEveryLine($component);

        $component
            ->call('save')
            ->assertSee('sleep in')
            ->assertSee('to stay in bed and sleep later than usual')
            ->assertSee('morning person');

        // Nothing has navigated away yet — evidence is saved, but the
        // learner is still looking at the recap until they click through.
        $this->assertDatabaseCount('evidences', 3); // 1 text + 2 audio
    }

    public function test_read_only_mode_maps_saved_gap_fill_answers_back(): void
    {
        $run = $this->makeRun();

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'listening',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode([
                'gap_fill_selections' => ['sleep in', 'morning person'],
                'detail_correct' => null,
            ]),
        ]);

        Livewire::test('missions.steps.listening', ['run' => $run, 'readOnly' => true])
            ->assertSet('gapFillSelections.0', 'sleep in')
            ->assertSet('gapFillSelections.1', 'morning person');
    }

    public function test_a_connection_style_failure_from_shadowing_shows_a_clean_error(): void
    {
        $run = $this->makeRun();

        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->once()->andThrow(new \RuntimeException('service unavailable')));

        Livewire::test('missions.steps.listening', ['run' => $run])
            ->set('shadowRecordings.0', UploadedFile::fake()->create('shadow-0.webm', 200, 'audio/webm'))
            ->call('checkShadowLine', 0)
            ->assertSet('checkErrors.shadow_0', fn ($error) => str_contains($error, 'service unavailable'));
    }

    public function test_a_successful_save_dispatches_a_clear_draft_event(): void
    {
        $run = $this->makeRun();
        $this->mockLenientShadowCheck();

        $component = Livewire::test('missions.steps.listening', ['run' => $run]);
        $this->fillGapsCorrectly($component);
        $this->shadowEveryLine($component);

        $component->call('save')->assertDispatched('clear-draft', prefix: "eos-draft:{$run->id}:listening:");
    }

    private function makeRunWithTranscript(): MissionRun
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [
                [
                    'phase' => 'foundation',
                    'steps' => [
                        [
                            'key' => 'listening',
                            'audio_url' => 'http://localhost/storage/missions/m01/mornings.mp3',
                            'transcript' => [
                                ['speaker' => 'Neil', 'text' => 'Hello and welcome.'],
                                ['speaker' => 'Georgie', 'text' => "And I'm Georgie."],
                            ],
                        ],
                        ['key' => 'grammar_in_context'],
                    ],
                ],
            ],
        ]);

        $this->actingAs($learner);

        return MissionRun::findOrStart($learner, $mission);
    }

    public function test_the_transcript_is_gated_behind_two_listens_by_default(): void
    {
        $run = $this->makeRunWithTranscript();

        $html = Livewire::test('missions.steps.listening', ['run' => $run])->html();

        $this->assertStringContainsString('listenCount', $html);
        $this->assertStringContainsString('Math.min(listenCount, 2)}/2 times', $html);
        $this->assertStringContainsString('dispatch(&#039;audio-ended&#039;)', $html);
        $this->assertStringContainsString('Hello and welcome.', $html);
    }

    public function test_the_transcript_is_never_gated_when_reviewing_a_completed_step(): void
    {
        $run = $this->makeRunWithTranscript();

        $html = Livewire::test('missions.steps.listening', ['run' => $run, 'readOnly' => true])->html();

        $this->assertStringNotContainsString('times to unlock the transcript', $html);
        $this->assertStringContainsString('Show transcript', $html);
    }

    private function makeRunWithComprehensionCheck(): MissionRun
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [
                [
                    'phase' => 'foundation',
                    'steps' => [
                        [
                            'key' => 'listening',
                            'audio_url' => 'http://localhost/storage/missions/m01/mornings.mp3',
                            'comprehension_check' => [
                                ['statement' => 'They are talking about morning routines.', 'correct' => true],
                                ['statement' => 'Neil often skips breakfast.', 'correct' => false],
                            ],
                        ],
                        ['key' => 'grammar_in_context'],
                    ],
                ],
            ],
        ]);

        $this->actingAs($learner);

        return MissionRun::findOrStart($learner, $mission);
    }

    public function test_the_comprehension_check_renders_as_the_first_substep_when_authored(): void
    {
        $run = $this->makeRunWithComprehensionCheck();

        $html = Livewire::test('missions.steps.listening', ['run' => $run])->html();

        $this->assertStringContainsString('activeSubstep === 0', $html);
        $this->assertStringContainsString('They are talking about morning routines.', $html);
        $this->assertStringContainsString('Neil often skips breakfast.', $html);
    }

    public function test_a_seeded_difficulty_tag_is_threaded_through_to_the_quick_round_cards(): void
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [[
                'phase' => 'foundation',
                'steps' => [[
                    'key' => 'listening',
                    'audio_url' => 'http://localhost/storage/missions/m01/mornings.mp3',
                    'comprehension_check' => [
                        ['statement' => 'They are talking about morning routines.', 'correct' => true, 'difficulty' => 'easy'],
                        ['statement' => 'Neil often skips breakfast.', 'correct' => false, 'difficulty' => 'hard'],
                    ],
                ]],
            ]],
        ]);
        $this->actingAs($learner);
        $run = MissionRun::findOrStart($learner, $mission);

        $html = Livewire::test('missions.steps.listening', ['run' => $run])->html();

        $this->assertMatchesRegularExpression('/difficulty.*?u0022:.*?u0022easy/', $html);
        $this->assertMatchesRegularExpression('/difficulty.*?u0022:.*?u0022hard/', $html);
    }

    public function test_the_comprehension_check_does_not_render_in_read_only_mode(): void
    {
        $run = $this->makeRunWithComprehensionCheck();

        $html = Livewire::test('missions.steps.listening', ['run' => $run, 'readOnly' => true])->html();

        $this->assertStringNotContainsString('They are talking about morning routines.', $html);
    }

    private function makeRunWithDetailQuestion(): MissionRun
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [
                [
                    'phase' => 'foundation',
                    'steps' => [
                        [
                            'key' => 'listening',
                            'audio_url' => 'http://localhost/storage/missions/m01/mornings.mp3',
                            'shadow_lines' => ['Do you **like** to **get up** early?', 'Are you a **morning person**?'],
                            'detail_question' => [
                                'question' => 'What time did Neil need to get up to catch his flight?',
                                'options' => ['3am', '7am', '9am'],
                                'correct' => 0,
                            ],
                        ],
                        ['key' => 'grammar_in_context'],
                    ],
                ],
            ],
        ]);

        $this->actingAs($learner);

        return MissionRun::findOrStart($learner, $mission);
    }

    public function test_the_detail_bonus_is_optional_and_never_blocks_continue(): void
    {
        $run = $this->makeRunWithDetailQuestion();
        $this->mockLenientShadowCheck();

        $component = Livewire::test('missions.steps.listening', ['run' => $run]);
        $this->shadowEveryLine($component);

        // detailCorrect left untouched — the learner skipped the bonus round.
        $component->call('save')->assertHasNoErrors()->assertSet('completed', true);
    }

    public function test_a_completed_detail_bonus_is_recorded_in_evidence(): void
    {
        $run = $this->makeRunWithDetailQuestion();
        $this->mockLenientShadowCheck();

        $component = Livewire::test('missions.steps.listening', ['run' => $run])->set('detailCorrect', true);
        $this->shadowEveryLine($component);

        $component->call('save')->assertHasNoErrors();

        $evidence = Evidence::where('phase', 'listening')->where('type', Evidence::TYPE_TEXT)->first();
        $content = json_decode($evidence->content_ref, true);
        $this->assertTrue($content['detail_correct']);
    }

    public function test_the_detail_bonus_is_rendered_as_a_quick_round(): void
    {
        $run = $this->makeRunWithDetailQuestion();

        $html = Livewire::test('missions.steps.listening', ['run' => $run])->html();

        $this->assertStringContainsString('What time did Neil need to get up to catch his flight?', $html);
        $this->assertStringContainsString('quick-round-completed', $html);
    }

    public function test_a_shadow_lines_stressed_words_render_bolded(): void
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [[
                'phase' => 'foundation',
                'steps' => [[
                    'key' => 'listening',
                    'audio_url' => 'http://localhost/storage/missions/m01/mornings.mp3',
                    'shadow_lines' => ['Do you **like** to **get up** early?'],
                ]],
            ]],
        ]);
        $run = MissionRun::findOrStart($learner, $mission);

        $html = Livewire::test('missions.steps.listening', ['run' => $run])->html();

        $this->assertStringContainsString('<strong', $html);
        $this->assertStringContainsString('>like</strong>', $html);
        $this->assertStringContainsString('>get up</strong>', $html);
        $this->assertStringNotContainsString('**', $html);
    }

    public function test_shadowing_fewer_than_required_lines_blocks_continue(): void
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [[
                'phase' => 'foundation',
                'steps' => [[
                    'key' => 'listening',
                    'audio_url' => 'http://localhost/storage/missions/m01/mornings.mp3',
                    // A pool of 4, only 2 required — shadowing just 1 must still block.
                    'shadow_lines' => ['Line one.', 'Line two.', 'Line three.', 'Line four.'],
                ]],
            ]],
        ]);
        $run = MissionRun::findOrStart($learner, $mission);
        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->once()->andReturn('Line one.'));
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn(json_encode(['severity' => 'none', 'hint' => ''])));

        Livewire::test('missions.steps.listening', ['run' => $run])
            ->set('shadowRecordings.0', UploadedFile::fake()->create('shadow-0.webm', 200, 'audio/webm'))
            ->call('checkShadowLine', 0)
            ->call('save')
            ->assertHasErrors(['shadowRecordings']);

        $this->assertDatabaseCount('evidences', 0);
    }

    public function test_a_cover_image_shows_when_the_episode_has_an_image_query(): void
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [[
                'phase' => 'foundation',
                'steps' => [['key' => 'listening', 'image_query' => 'morning coffee']],
            ]],
        ]);
        $run = MissionRun::findOrStart($learner, $mission);

        $this->mock(PexelsClient::class, function ($mock) {
            $mock->shouldReceive('imageUrlFor')
                ->with('M01-listening', 'morning coffee')
                ->once()
                ->andReturn('http://localhost/storage/vocabulary-images/m01-listening.jpg');
        });

        Livewire::test('missions.steps.listening', ['run' => $run])
            ->assertSeeHtml('http://localhost/storage/vocabulary-images/m01-listening.jpg');
    }

    public function test_no_cover_image_without_an_image_query(): void
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [['phase' => 'foundation', 'steps' => [['key' => 'listening']]]],
        ]);
        $run = MissionRun::findOrStart($learner, $mission);

        $this->mock(PexelsClient::class, fn ($mock) => $mock->shouldNotReceive('imageUrlFor'));

        Livewire::test('missions.steps.listening', ['run' => $run])->assertDontSeeHtml('<img');
    }
}
