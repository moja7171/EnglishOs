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
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
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

    private function stepComponent($run, bool $readOnly = false)
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

        $html = $this->stepComponent($run)->html();

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

        $component = $this->stepComponent($run);

        // words() is derived entirely from seeded content, not a Livewire
        // property a learner can change — there is no "selectedWords" any
        // more, so words() is the only source of truth.
        $this->assertSame(self::DAY1_WORDS, collect($component->instance()->words())->pluck('phrase')->all());
    }

    public function test_day_two_goes_straight_to_todays_words_with_no_recap_of_day_ones(): void
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

        // Yesterday's words already live in My Words and Daily Review, so
        // the step opens on today's words only.
        $component->assertDontSee('Quick reminder')
            ->assertSee('relax')
            ->assertDontSee('commute');
    }

    public function test_a_meaning_check_quick_round_covers_every_word(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $component = $this->stepComponent($run);
        $cards = $component->instance()->meaningCheckCards();
        $meanings = collect($this->day1Step()['words'])->pluck('meaning', 'phrase');

        $this->assertCount(4, $cards);

        foreach ($cards as $index => $card) {
            $this->assertSame(self::DAY1_WORDS[$index], $card['prompt']);
            $this->assertCount(3, $card['options']);
            $this->assertSame($meanings[$card['prompt']], $card['options'][$card['correct']]);
        }

    }

    public function test_the_quick_check_is_one_card_per_word_in_persian_when_every_word_has_it(): void
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [['phase' => 'foundation', 'steps' => [[
                'key' => 'vocabulary_builder_1',
                'story' => '',
                'words' => [
                    ['phrase' => 'wake up', 'meaning' => 'to stop sleeping', 'meaning_fa' => 'بیدار شدن'],
                    ['phrase' => 'get up', 'meaning' => 'to get out of bed', 'meaning_fa' => 'بلند شدن'],
                    ['phrase' => 'commute', 'meaning' => 'to travel to work', 'meaning_fa' => 'رفت‌وآمد'],
                ],
            ]]]],
        ]);
        $run = MissionRun::findOrStart($learner, $mission);

        $cards = Livewire::test('missions.steps.vocabulary-builder', ['run' => $run, 'stepKey' => 'vocabulary_builder_1'])
            ->instance()->quizCards();

        $this->assertCount(3, $cards);
        $this->assertSame('بیدار شدن', $cards[0]['options'][$cards[0]['correct']]);
        $this->assertSame('Pick the meaning', $cards[0]['label']);
    }

    public function test_meaning_check_wrong_answers_come_from_other_days_so_they_cannot_be_ruled_out_by_elimination(): void
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
                    'words' => [
                        ['phrase' => 'cereal', 'meaning' => 'a breakfast food made from grain'],
                        ['phrase' => 'snack', 'meaning' => 'a small amount of food between meals'],
                    ],
                ]]],
            ],
        ]);
        $this->actingAs($learner);

        $cards = Livewire::test('missions.steps.vocabulary-builder', ['run' => MissionRun::findOrStart($learner, $mission), 'stepKey' => 'vocabulary_builder_1'])
            ->instance()->meaningCheckCards();
        $todaysMeanings = collect($this->day1Step()['words'])->pluck('meaning');

        foreach ($cards as $card) {
            $wrongOptions = collect($card['options'])->reject(fn ($option, $index) => $index === $card['correct']);

            $this->assertCount(2, $wrongOptions);
            $this->assertEmpty($wrongOptions->intersect($todaysMeanings)->all());
        }
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

    public function test_a_word_card_shows_the_words_picture(): void
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
                    'story' => 'I always eat **cereal**.',
                    'words' => [['phrase' => 'cereal', 'meaning' => 'a breakfast food', 'image_query' => 'bowl of cereal breakfast']],
                ]],
            ]],
        ]);
        $this->actingAs($learner);
        $run = MissionRun::findOrStart($learner, $mission);

        $this->mock(PexelsClient::class, fn ($mock) => $mock->shouldReceive('imageUrlFor')->andReturn('http://localhost/storage/cereal.jpg'));

        Livewire::test('missions.steps.vocabulary-builder', ['run' => $run, 'stepKey' => 'vocabulary_builder_1'])
            ->assertSeeHtml('http://localhost/storage/cereal.jpg');
    }

    public function test_read_only_mode_lists_each_word_with_the_learners_sentence(): void
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

        $this->stepComponent($run, readOnly: true)
            ->assertSee('I commute by bus.')
            ->assertDontSeeHtml('checkOne');
    }

    public function test_every_word_needs_an_example_before_continuing(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->stepComponent($run)
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

        $this->stepComponent($run)
            ->set('examples.0', 'I usually wake up around 7.')
            ->set('examples.1', 'I have a morning routine.')
            ->set('examples.2', 'I get up straight away.')
            ->set('examples.3', 'I commute by bus.')
            ->call('save')
            ->assertRedirect(route('missions.show', $run->mission));

        $evidence = Evidence::where('phase', 'vocabulary_builder_1')->first();
        $this->assertNotNull($evidence);

        $content = json_decode($evidence->content_ref, true);
        $this->assertSame(self::DAY1_WORDS, $content['selected_words']);
        $this->assertCount(4, $content['examples']);

        $this->assertSame('listening', $run->fresh()->currentStepKey());
    }

    public function test_a_words_persian_meaning_shows_on_its_card_and_is_kept_in_my_words(): void
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [['phase' => 'foundation', 'steps' => [[
                'key' => 'vocabulary_builder_1',
                'story' => 'I **wake up** early.',
                'words' => [['phrase' => 'wake up', 'meaning' => 'to stop sleeping', 'meaning_fa' => 'بیدار شدن']],
            ]]]],
        ]);
        $this->actingAs($learner);
        $run = MissionRun::findOrStart($learner, $mission);

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn(json_encode(['severity' => 'none', 'hint' => ''])));

        $this->stepComponent($run)
            ->assertSee('بیدار شدن')
            ->set('examples.0', 'I usually wake up around 7.')
            ->call('save');

        $this->assertDatabaseHas('vocabulary_words', ['learner_id' => $learner->id, 'word' => 'wake up', 'meaning_fa' => 'بیدار شدن']);
    }

    public function test_saving_adds_every_word_to_my_words_by_itself(): void
    {
        [$learner, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(4)->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        $this->stepComponent($run)
            ->set('examples.0', 'I usually wake up around 7.')
            ->set('examples.1', 'I have a morning routine.')
            ->set('examples.2', 'I get up straight away.')
            ->set('examples.3', 'I commute by bus.')
            ->call('save');

        $this->assertSame(4, VocabularyWord::where('learner_id', $learner->id)->count());

        $wakeUp = VocabularyWord::where('learner_id', $learner->id)->where('word', 'wake up')->firstOrFail();
        $this->assertSame($run->id, $wakeUp->source_mission_run_id);
        $this->assertSame('to stop sleeping', $wakeUp->meaning);
        // The learner's own checked sentence travels with the word to the review card.
        $this->assertSame('I usually wake up around 7.', $wakeUp->user_sentence);
        // Just practiced it here, so the first review is tomorrow.
        $this->assertFalse($wakeUp->isDue());
        $this->assertEqualsWithDelta(now()->addDay()->timestamp, $wakeUp->next_review_at->timestamp, 5);
    }

    public function test_the_authored_part_of_speech_and_example_are_saved_with_the_word(): void
    {
        [$learner, $mission, $run] = $this->makeMissionAndRun();

        $steps = $mission->phases;
        $steps[0]['steps'][1]['words'][0] += ['pos' => 'phrasal verb', 'example' => 'I wake up at six.'];
        $mission->update(['phases' => $steps]);

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(4)->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        $this->stepComponent($run->fresh())
            ->set('examples.0', 'I usually wake up around 7.')
            ->set('examples.1', 'I have a morning routine.')
            ->set('examples.2', 'I get up straight away.')
            ->set('examples.3', 'I commute by bus.')
            ->call('save');

        $wakeUp = VocabularyWord::where('learner_id', $learner->id)->where('word', 'wake up')->firstOrFail();
        $this->assertSame('phrasal verb', $wakeUp->pos);
        $this->assertSame('I wake up at six.', $wakeUp->example);

        $routine = VocabularyWord::where('learner_id', $learner->id)->where('word', 'routine')->firstOrFail();
        $this->assertNull($routine->pos);
        $this->assertNull($routine->example);
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

        $this->stepComponent($run)
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

        $this->stepComponent($run)
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

        $this->stepComponent($run)
            ->set('examples.0', 'I usually wake up around 7.')
            ->call('checkOne', 0)
            ->assertSet('checkErrors.wake up', "Couldn't check this one — please try again in a moment.")
            ->assertDontSee('service unavailable')
            ->assertSet('examples.0', 'I usually wake up around 7.');
    }

    public function test_a_connection_failure_is_tried_once_more_then_says_it_is_a_connection_problem(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->twice()->andThrow(
                new ConnectionException('cURL error 7: Failed to connect() to host')
            );
        });

        $this->stepComponent($run)
            ->set('examples.0', 'I usually wake up around 7.')
            ->call('checkOne', 0)
            ->assertSet('checkErrors.wake up', 'Connection problem — try again in a moment. (connection)')
            ->assertSee('Try again')
            ->assertSee('Continue without check')
            ->assertDontSee('cURL error');
    }

    public function test_a_connection_blip_that_answers_on_the_second_try_never_shows_an_error(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andThrow(new ConnectionException('cURL error 28: timed out'));
            $mock->shouldReceive('chat')->once()->andReturn(json_encode(['severity' => 'none', 'hint' => 'Clear.']));
        });

        $this->stepComponent($run)
            ->set('examples.0', 'I usually wake up around 7.')
            ->call('checkOne', 0)
            ->assertReturned(true)
            ->assertSet('feedback.wake up.severity', 'none')
            ->assertSet('checkErrors', []);
    }

    public function test_a_rate_limit_says_busy_and_is_not_retried_at_once(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andThrow(new RequestException(
                new Response(new Psr7Response(429, [], json_encode(['error' => ['message' => 'Resource exhausted']])))
            ));
        });

        $this->stepComponent($run)
            ->set('examples.0', 'I usually wake up around 7.')
            ->call('checkOne', 0)
            ->assertSet('checkErrors.wake up', 'Too many checks at once — wait a minute, then try again. (busy)');
    }

    public function test_a_used_up_daily_quota_says_so_and_lets_the_learner_continue(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andThrow(new RequestException(
                new Response(new Psr7Response(429, [], json_encode(['error' => ['details' => [[
                    'violations' => [['quotaId' => 'GenerateRequestsPerDayPerProjectPerModel-FreeTier']],
                ]]]])))
            ));
        });

        $this->stepComponent($run)
            ->set('examples.0', 'I usually wake up around 7.')
            ->call('checkOne', 0)
            ->assertSet('checkErrors.wake up', 'Checking is used up for now — try again later, or continue. (finished)')
            ->assertSee('Continue without check');
    }

    public function test_the_page_keeps_its_screen_when_the_server_re_renders_after_a_check(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        // The opening screen in x-data follows the first empty box, so it
        // changes after every first check; Alpine must not re-read it, or
        // the page jumps to the next word before the verdict is seen.
        $this->stepComponent($run)->call('startPractice')->assertSeeHtml('wire:ignore.self');
    }

    public function test_a_failed_check_replaces_the_old_verdict_instead_of_showing_it_beside_the_error(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode(['severity' => 'minor', 'hint' => "Add 'an' before 'old'."]));
            $mock->shouldReceive('chat')->once()->andThrow(new RequestException(
                new Response(new Psr7Response(429, [], json_encode(['error' => ['message' => 'Resource exhausted']])))
            ));
        });

        $this->stepComponent($run)
            ->set('examples.0', 'He is a old friend.')
            ->call('checkOne', 0)
            ->assertSee("Add 'an' before 'old'.")
            ->set('examples.0', 'He is an old friend.')
            ->call('checkOne', 0)
            ->assertSet('feedback.wake up', null)
            ->assertDontSee("Add 'an' before 'old'.")
            ->assertDontSee('Almost!')
            ->assertSee('(busy)');
    }

    public function test_continuing_does_not_ask_the_ai_again_about_a_sentence_it_could_not_check(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andThrow(new RequestException(
                new Response(new Psr7Response(403, [], 'blocked'))
            ));
            $mock->shouldReceive('chat')->times(3)->andReturn(json_encode(['severity' => 'none', 'hint' => 'Clear.']));
        });

        $sentences = ['I usually wake up around 7.', 'I have a morning routine.', 'I get up straight away.', 'I commute by bus.'];
        $component = $this->stepComponent($run);

        foreach ($sentences as $index => $sentence) {
            $component->set("examples.$index", $sentence)->call('checkOne', $index);
        }

        $component->assertSet('checkErrors.wake up', "Checking isn't working right now — you can continue. (setup)")
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('evidences', ['phase' => 'vocabulary_builder_1']);
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

        $this->stepComponent($run, readOnly: true)
            ->assertSet('examples.0', '') // wake up — not filled
            ->assertSet('examples.3', 'I commute by bus.'); // commute is index 3
    }

    public function test_example_inputs_carry_a_draft_key_scoped_to_the_run_and_day(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->stepComponent($run)
            ->call('startPractice')
            ->assertSeeHtml("eos-draft:{$run->id}:vocabulary_builder_1:examples.0");
    }

    public function test_a_successful_save_dispatches_a_clear_draft_event_scoped_to_the_day(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(4)->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        $this->stepComponent($run)
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

        $component = $this->stepComponent($run);
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

        $component = $this->stepComponent($run);
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

        $this->stepComponent($run)->set('examples.3', 'to travel to work')->call('checkOne', 3);

        $this->assertSame(1, $run->fresh()->struggle_signal_count);
    }

    public function test_a_learner_who_leaves_after_starting_practice_comes_back_to_practice(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->stepComponent($run)->call('startPractice');

        $this->stepComponent($run)->assertSet('practiceStarted', true);
    }

    public function test_a_said_sentence_becomes_the_boxs_text_and_is_checked(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->once()->andReturn('I wake up at seven.'));
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn(json_encode(['severity' => 'none', 'hint' => ''])));

        $this->stepComponent($run)
            ->set('dictation', UploadedFile::fake()->create('answer.webm', 10, 'audio/webm'))
            ->call('dictateExample', 0)
            ->assertSet('examples.0', 'I wake up at seven.')
            ->assertSet('feedback.wake up.severity', 'none')
            ->assertSee('Great!');
    }

    public function test_a_good_sentence_still_shows_the_ais_short_comment(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn(json_encode(['severity' => 'none', 'hint' => 'Clear and natural.'])));

        $this->stepComponent($run)
            ->set('examples.0', 'I wake up at seven.')
            ->call('checkOne', 0)
            ->assertSee('Great!')
            ->assertSee('Clear and natural.');
    }

    public function test_a_major_issue_offers_only_check_again_not_continue(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn(json_encode(['severity' => 'major', 'hint' => 'Use the word with its real meaning.'])));

        $this->stepComponent($run)
            ->set('examples.0', 'Wake up is a word.')
            ->call('checkOne', 0)
            ->assertSee('Not quite')
            ->assertSee('Use the word with its real meaning.')
            ->assertSee('Check again')
            ->assertDontSee('Try again')
            ->assertDontSee('Continue anyway');
    }

    public function test_a_nearly_right_sentence_offers_check_again_and_a_quiet_continue_anyway(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn(json_encode(['severity' => 'minor', 'hint' => "'day' should be plural."])));

        $this->stepComponent($run)
            ->set('examples.0', 'I wake up at seven every day.')
            ->call('checkOne', 0)
            ->assertSee('Almost!')
            ->assertSee('Check again')
            ->assertSee('Continue anyway');
    }

    public function test_check_reports_whether_the_ai_answered_so_the_page_can_keep_the_change_flag(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andThrow(new \RuntimeException('boom')));

        $this->stepComponent($run)
            ->set('examples.0', 'I wake up at seven.')
            ->call('checkOne', 0)
            ->assertReturned(false)
            ->assertSee("Couldn't check this one");
    }

    public function test_verdicts_survive_leaving_so_the_last_continue_does_not_check_everything_again(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->times(4)->andReturn(json_encode(['severity' => 'none', 'hint' => 'Clear.'])));

        $sentences = ['I usually wake up around 7.', 'I have a morning routine.', 'I get up straight away.', 'I commute by bus.'];
        $first = $this->stepComponent($run);

        foreach ($sentences as $index => $sentence) {
            $first->set("examples.$index", $sentence)->call('checkOne', $index);
        }

        // A new visit: a fresh component whose boxes get their text back
        // from the browser's draft. The four verdicts must still be there.
        $second = $this->stepComponent($run);

        foreach ($sentences as $index => $sentence) {
            $second->set("examples.$index", $sentence);
        }

        $second->call('save')->assertHasNoErrors();
    }

    public function test_a_sentence_edited_after_its_check_is_still_checked_again_on_save(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->times(5)->andReturn(json_encode(['severity' => 'none', 'hint' => 'Clear.'])));

        $sentences = ['I usually wake up around 7.', 'I have a morning routine.', 'I get up straight away.', 'I commute by bus.'];
        $first = $this->stepComponent($run);

        foreach ($sentences as $index => $sentence) {
            $first->set("examples.$index", $sentence)->call('checkOne', $index);
        }

        $second = $this->stepComponent($run);

        foreach ($sentences as $index => $sentence) {
            $second->set("examples.$index", $index === 2 ? 'I get up at six.' : $sentence);
        }

        $second->call('save')->assertHasNoErrors();
    }
}
