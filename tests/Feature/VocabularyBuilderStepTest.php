<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use App\Models\VocabularyWord;
use App\Services\GeminiClient;
use App\Services\PexelsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Mission structure redesign, Epic B: Vocabulary Builder is no longer a
 * "pick at least 8 of 29" free-for-all — each day (vocabulary_builder_1/2/3)
 * shows a small, fixed, curated word list (4 here, for a manageable
 * fixture; 5-6 in real mission content) with no selection step at all.
 */
class VocabularyBuilderStepTest extends TestCase
{
    use RefreshDatabase;

    /** The 4 fixed words for the day-1 fixture, in story order. */
    private const DAY1_WORDS = ['wake up', 'routine', 'get up', 'commute'];

    private function day1Step(): array
    {
        return [
            'key' => 'vocabulary_builder_1',
            'story' => 'I usually **wake up** early and follow my **routine**. I **get up**, then **commute** to work.',
            'words' => [
                ['phrase' => 'wake up', 'meaning' => 'to stop sleeping'],
                ['phrase' => 'routine', 'meaning' => 'the usual things you do'],
                ['phrase' => 'get up', 'meaning' => 'to get out of bed'],
                ['phrase' => 'commute', 'meaning' => 'to travel to work'],
            ],
        ];
    }

    private function makeMissionAndRun(): array
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
                        ['key' => 'mission_brief'],
                        $this->day1Step(),
                        ['key' => 'listening'],
                    ],
                ],
            ],
        ]);

        // mission_brief already has Evidence, so the run starts on vocabulary_builder_1.
        Evidence::create([
            'mission_run_id' => MissionRun::findOrStart($learner, $mission)->id,
            'phase' => 'mission_brief',
            'type' => Evidence::TYPE_SCORE,
            'content_ref' => '2',
        ]);

        return [$learner, $mission, MissionRun::findOrStart($learner, $mission)];
    }

    private function testComponent($run, bool $readOnly = false)
    {
        return Livewire::test('missions.steps.vocabulary-builder', [
            'run' => $run,
            'readOnly' => $readOnly,
            'stepKey' => 'vocabulary_builder_1',
        ]);
    }

    public function test_the_story_shows_every_word_highlighted_with_its_meaning_inline(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $html = $this->testComponent($run)->html();

        foreach (self::DAY1_WORDS as $word) {
            $this->assertStringContainsString($word, $html);
        }
        $this->assertStringContainsString('to stop sleeping', $html);
        $this->assertStringContainsString('to travel to work', $html);
        // No selection mechanic left at all.
        $this->assertStringNotContainsString('toggleWord', $html);
    }

    public function test_all_of_todays_words_are_the_practice_set_with_no_selection_step(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $component = $this->testComponent($run);

        // words() is derived entirely from seeded content, not a Livewire
        // property a learner can change — there is no "selectedWords" any
        // more, so words() is the only source of truth.
        $this->assertSame(self::DAY1_WORDS, collect($component->instance()->words())->pluck('phrase')->all());
    }

    public function test_day_one_has_no_spiral_review_since_there_is_no_previous_day(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->assertSame([], $this->testComponent($run)->instance()->spiralReviewCards());
    }

    public function test_day_two_offers_a_spiral_review_of_day_ones_words(): void
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [
                ['phase' => 'foundation', 'steps' => [$this->day1Step()]],
                ['phase' => 'build', 'steps' => [[
                    'key' => 'vocabulary_builder_2',
                    'story' => 'In the evening I like to **relax**.',
                    'words' => [['phrase' => 'relax', 'meaning' => 'to rest']],
                ]]],
            ],
        ]);
        $this->actingAs($learner);
        $run = MissionRun::findOrStart($learner, $mission);

        $component = Livewire::test('missions.steps.vocabulary-builder', [
            'run' => $run,
            'stepKey' => 'vocabulary_builder_2',
        ]);

        $cards = $component->instance()->spiralReviewCards();

        $this->assertCount(4, $cards);
        $this->assertSame(self::DAY1_WORDS, collect($cards)->pluck('prompt')->all());
    }

    public function test_a_meaning_check_quick_round_covers_every_word(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $component = $this->testComponent($run);
        $cards = $component->instance()->meaningCheckCards();
        $meanings = collect($this->day1Step()['words'])->pluck('meaning', 'phrase');

        $this->assertCount(4, $cards);

        foreach ($cards as $index => $card) {
            $this->assertSame(self::DAY1_WORDS[$index], $card['prompt']);
            $this->assertCount(3, $card['options']);
            $this->assertSame($meanings[$card['prompt']], $card['options'][$card['correct']]);
        }

        $component->assertSee('Quick check before you write');
    }

    public function test_a_seeded_difficulty_tag_is_threaded_through_to_the_meaning_check_cards(): void
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
                    'key' => 'vocabulary_builder_1',
                    'story' => '',
                    'words' => [
                        ['phrase' => 'wake up', 'meaning' => 'to stop sleeping', 'difficulty' => 'easy'],
                        ['phrase' => 'oversleep', 'meaning' => 'to sleep too long by accident', 'difficulty' => 'hard'],
                        ['phrase' => 'routine', 'meaning' => 'the usual things you do'],
                    ],
                ]],
            ]],
        ]);
        $this->actingAs($learner);
        $run = MissionRun::findOrStart($learner, $mission);

        $component = Livewire::test('missions.steps.vocabulary-builder', ['run' => $run, 'stepKey' => 'vocabulary_builder_1']);
        $cards = collect($component->instance()->meaningCheckCards())->keyBy('prompt');

        $this->assertSame('easy', $cards['wake up']['difficulty']);
        $this->assertSame('hard', $cards['oversleep']['difficulty']);
        $this->assertArrayNotHasKey('difficulty', $cards['routine']);
    }

    public function test_an_image_match_round_is_offered_for_words_with_an_image_query(): void
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
                    'key' => 'vocabulary_builder_1',
                    'story' => 'A short story.',
                    'words' => [
                        ['phrase' => 'cereal', 'meaning' => 'a breakfast food', 'image_query' => 'bowl of cereal'],
                        ['phrase' => 'shower', 'meaning' => 'to wash', 'image_query' => 'shower bathroom'],
                        ['phrase' => 'ironing', 'meaning' => 'smoothing clothes', 'image_query' => 'ironing clothes'],
                        ['phrase' => 'routine', 'meaning' => 'the usual things you do'], // no image_query — abstract
                    ],
                ]],
            ]],
        ]);
        $run = MissionRun::findOrStart($learner, $mission);

        $this->mock(PexelsClient::class, function ($mock) {
            $mock->shouldReceive('imageUrlFor')
                ->andReturnUsing(fn (string $word, string $query) => "http://localhost/storage/{$word}.jpg");
        });

        $cards = Livewire::test('missions.steps.vocabulary-builder', ['run' => $run, 'stepKey' => 'vocabulary_builder_1'])
            ->instance()->imageMatchCards();

        // 'routine' has no image_query, so only the 3 image-bearing words get a card.
        $this->assertCount(3, $cards);

        foreach ($cards as $card) {
            $this->assertSame('image', $card['optionType']);
            $this->assertCount(3, $card['options']);
            $this->assertSame("http://localhost/storage/{$card['prompt']}.jpg", $card['options'][$card['correct']]);
        }
    }

    public function test_practice_cards_never_show_an_image_even_when_the_word_has_one(): void
    {
        // Epic B decision: dual-coding images were removed from the
        // personal-example practice cards specifically — only the
        // image-match quiz round (tested above) still uses them.
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [[
                'phase' => 'foundation',
                'steps' => [[
                    'key' => 'vocabulary_builder_1',
                    'story' => 'I always eat **cereal**.',
                    'words' => [['phrase' => 'cereal', 'meaning' => 'a breakfast food', 'image_query' => 'bowl of cereal breakfast']],
                ]],
            ]],
        ]);
        $this->actingAs($learner);
        $run = MissionRun::findOrStart($learner, $mission);

        $this->mock(PexelsClient::class, fn ($mock) => $mock->shouldNotReceive('imageUrlFor'));

        Livewire::test('missions.steps.vocabulary-builder', ['run' => $run, 'stepKey' => 'vocabulary_builder_1'])
            ->call('startPractice')
            ->assertDontSeeHtml('<img');
    }

    public function test_read_only_mode_shows_the_story_as_reference_without_click(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'vocabulary_builder_1',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode([
                'selected_words' => self::DAY1_WORDS,
                'examples' => [
                    ['word' => 'wake up', 'example' => 'I usually wake up around 7.'],
                    ['word' => 'routine', 'example' => 'I have a morning routine.'],
                    ['word' => 'get up', 'example' => 'I get up straight away.'],
                    ['word' => 'commute', 'example' => 'I commute by bus.'],
                ],
            ]),
        ]);

        $html = $this->testComponent($run, readOnly: true)->html();

        $this->assertStringContainsString('data-initial-phase="practice"', $html);
        $this->assertStringContainsString('follow my', $html); // story text present as reference
        $this->assertStringNotContainsString('wire:click="toggleWord', $html);
    }

    public function test_every_word_needs_an_example_before_continuing(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->testComponent($run)
            ->set('examples.0', 'I usually wake up around 7.')
            ->set('examples.1', 'I have a morning routine.')
            ->call('save')
            ->assertHasErrors(['examples']);

        $this->assertDatabaseCount('evidences', 1); // only the mission_brief one from setup
    }

    public function test_all_examples_save_evidence_and_advance_the_run(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(4)->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        $this->testComponent($run)
            ->set('examples.0', 'I usually wake up around 7.')
            ->set('examples.1', 'I have a morning routine.')
            ->set('examples.2', 'I get up straight away.')
            ->set('examples.3', 'I commute by bus.')
            ->call('save')
            ->assertSet('completed', true)
            ->call('proceed')
            ->assertRedirect(route('missions.show', $run->mission));

        $evidence = Evidence::where('phase', 'vocabulary_builder_1')->first();
        $this->assertNotNull($evidence);

        $content = json_decode($evidence->content_ref, true);
        $this->assertSame(self::DAY1_WORDS, $content['selected_words']);
        $this->assertCount(4, $content['examples']);

        $this->assertSame('listening', $run->fresh()->currentStepKey());
    }

    public function test_saving_enrolls_every_word_into_the_vocabulary_notebook(): void
    {
        [$learner, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(4)->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        $this->testComponent($run)
            ->set('examples.0', 'I usually wake up around 7.')
            ->set('examples.1', 'I have a morning routine.')
            ->set('examples.2', 'I get up straight away.')
            ->set('examples.3', 'I commute by bus.')
            ->call('save')
            ->assertSet('completed', true)
            ->call('addWordsToNotebook')
            ->assertSet('trackedWords', true);

        $this->assertSame(4, VocabularyWord::where('learner_id', $learner->id)->count());

        $wakeUp = VocabularyWord::where('learner_id', $learner->id)->where('word', 'wake up')->firstOrFail();
        $this->assertSame($run->id, $wakeUp->source_mission_run_id);
        $this->assertSame('to stop sleeping', $wakeUp->meaning);
        $this->assertTrue($wakeUp->isDue());
    }

    public function test_words_are_only_enrolled_once_add_to_notebook_is_pressed(): void
    {
        [$learner, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(4)->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        $this->testComponent($run)
            ->set('examples.0', 'I usually wake up around 7.')
            ->set('examples.1', 'I have a morning routine.')
            ->set('examples.2', 'I get up straight away.')
            ->set('examples.3', 'I commute by bus.')
            ->call('save');

        $this->assertSame(0, VocabularyWord::where('learner_id', $learner->id)->count());
    }

    public function test_unchecking_a_word_before_adding_leaves_it_out_of_the_notebook(): void
    {
        [$learner, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(4)->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        $this->testComponent($run)
            ->set('examples.0', 'I usually wake up around 7.')
            ->set('examples.1', 'I have a morning routine.')
            ->set('examples.2', 'I get up straight away.')
            ->set('examples.3', 'I commute by bus.')
            ->call('save')
            ->set('wordsToTrack.0', false) // "wake up" is index 0
            ->call('addWordsToNotebook');

        $this->assertSame(3, VocabularyWord::where('learner_id', $learner->id)->count());
        $this->assertDatabaseMissing('vocabulary_words', ['learner_id' => $learner->id, 'word' => 'wake up']);
    }

    public function test_continue_checks_every_unchecked_filled_sentence_and_blocks_on_a_major_issue(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->times(3)
                ->andReturn(json_encode(['severity' => 'none', 'hint' => '']))
                ->ordered();
            $mock->shouldReceive('chat')
                ->once()
                ->andReturn(json_encode(['severity' => 'major', 'hint' => 'Describe your own actual commute.']))
                ->ordered();
        });

        $this->testComponent($run)
            ->set('examples.0', 'I have a morning routine.')
            ->set('examples.1', 'travel to work') // routine's own example is copied-definition-like; commute below is major
            ->set('examples.2', 'I get up straight away.')
            ->set('examples.3', 'a personal sentence')
            ->call('save')
            ->assertHasErrors(['examples'])
            ->assertSee('Describe your own actual commute.');

        $this->assertDatabaseCount('evidences', 1);
    }

    public function test_checking_one_input_does_not_touch_the_others_and_nothing_is_saved(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        $this->testComponent($run)
            ->set('examples.0', 'I usually wake up around 7.')
            ->call('checkOne', 0)
            ->assertSet('feedback.wake up.severity', 'none')
            ->assertSet('feedback.routine', null);

        $this->assertDatabaseCount('evidences', 1);
    }

    public function test_a_failed_check_shows_an_error_for_just_that_input(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andThrow(new \RuntimeException('service unavailable'));
        });

        $this->testComponent($run)
            ->set('examples.0', 'I usually wake up around 7.')
            ->call('checkOne', 0)
            ->assertSet('checkErrors.wake up', fn ($error) => str_contains($error, 'service unavailable'))
            ->assertSet('examples.0', 'I usually wake up around 7.');
    }

    public function test_a_connection_failure_shows_a_friendly_retry_message_not_a_raw_error(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andThrow(
                new ConnectionException('cURL error 7: Failed to connect() to host')
            );
        });

        $this->testComponent($run)
            ->set('examples.0', 'I usually wake up around 7.')
            ->call('checkOne', 0)
            ->assertSet('checkErrors.wake up', "Couldn't reach the AI service — please try again.")
            ->assertDontSee('cURL error');
    }

    public function test_read_only_mode_maps_saved_examples_back_to_the_right_word(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'vocabulary_builder_1',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode([
                'selected_words' => self::DAY1_WORDS,
                'examples' => [
                    ['word' => 'commute', 'example' => 'I commute by bus.'],
                ],
            ]),
        ]);

        $this->testComponent($run, readOnly: true)
            ->assertSet('examples.0', '') // wake up — not filled
            ->assertSet('examples.3', 'I commute by bus.'); // commute is index 3
    }

    public function test_example_inputs_carry_a_draft_key_scoped_to_the_run_and_day(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->testComponent($run)
            ->call('startPractice')
            ->assertSeeHtml("eos-draft:{$run->id}:vocabulary_builder_1:examples.0");
    }

    public function test_a_successful_save_dispatches_a_clear_draft_event_scoped_to_the_day(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(4)->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        $this->testComponent($run)
            ->set('examples.0', 'I usually wake up around 7.')
            ->set('examples.1', 'I have a morning routine.')
            ->set('examples.2', 'I get up straight away.')
            ->set('examples.3', 'I commute by bus.')
            ->call('save')
            ->assertDispatched('clear-draft', prefix: "eos-draft:{$run->id}:vocabulary_builder_1:");
    }

    public function test_three_failed_checks_on_a_word_offer_to_reveal_the_correction(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(3)->andReturn(json_encode(['severity' => 'major', 'hint' => 'Try again.']));
        });

        $component = $this->testComponent($run);
        $component->set('examples.0', 'attempt one');
        $component->call('checkOne', 0);
        $component->call('checkOne', 0);
        $component->call('checkOne', 0)
            ->assertSet('offerReveal.wake up', true);
    }

    public function test_accepting_the_reveal_writes_the_ai_correction_into_the_example(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(3)->andReturn(json_encode(['severity' => 'major', 'hint' => 'Try again.']));
            $mock->shouldReceive('chat')->once()->andReturn('I usually wake up early.');
        });

        $component = $this->testComponent($run);
        $component->set('examples.0', 'wake up bad sentence');
        $component->call('checkOne', 0);
        $component->call('checkOne', 0);
        $component->call('checkOne', 0)->assertSet('offerReveal.wake up', true);

        $component->call('revealCorrection', 0)
            ->assertSet('examples.0', 'I usually wake up early.')
            ->assertSet('feedback.wake up.severity', 'none')
            ->assertSet('offerReveal.wake up', null);
    }

    public function test_a_major_verdict_records_a_struggle_signal_on_the_run(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode([
                'severity' => 'major',
                'hint' => 'This just repeats the definition.',
            ]));
        });

        $this->testComponent($run)->set('examples.3', 'to travel to work')->call('checkOne', 3);

        $this->assertSame(1, $run->fresh()->struggle_signal_count);
    }
}
