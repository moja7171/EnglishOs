<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use App\Models\VocabularyWord;
use App\Services\GeminiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Livewire\Livewire;
use Tests\TestCase;

class VocabularyBuilderStepTest extends TestCase
{
    use RefreshDatabase;

    /** The 10 candidate phrases marked in the fixture story, in story order. */
    private const STORY_WORDS = [
        'wake up', 'routine', 'get up', 'commute', 'relax',
        'cook dinner', 'go to bed', 'day off', 'sleep in', 'wind down',
    ];

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
                        [
                            'key' => 'vocabulary_builder',
                            'story' => [
                                [
                                    'heading' => 'Morning',
                                    'text' => 'I usually **wake up** early and follow my **routine**. I '
                                        .'**get up**, then **commute** to work. In the evening I like to '
                                        .'**relax**.',
                                ],
                                [
                                    'heading' => 'Evening',
                                    'text' => 'Sometimes I **cook dinner**, and later I **go to bed**. On '
                                        .'my **day off** I **sleep in** and just **wind down**.',
                                ],
                            ],
                            'story_words' => [
                                ['phrase' => 'wake up', 'meaning' => 'to stop sleeping'],
                                ['phrase' => 'routine', 'meaning' => 'the usual things you do'],
                                ['phrase' => 'get up', 'meaning' => 'to get out of bed'],
                                ['phrase' => 'commute', 'meaning' => 'to travel to work'],
                                ['phrase' => 'relax', 'meaning' => 'to rest'],
                                ['phrase' => 'cook dinner', 'meaning' => 'to prepare the evening meal'],
                                ['phrase' => 'go to bed', 'meaning' => 'to get into bed to sleep'],
                                ['phrase' => 'day off', 'meaning' => 'a day when you don\'t work'],
                                ['phrase' => 'sleep in', 'meaning' => 'to sleep later than usual'],
                                ['phrase' => 'wind down', 'meaning' => 'to relax before sleep'],
                            ],
                        ],
                        ['key' => 'listening'],
                    ],
                ],
            ],
        ]);

        // mission_brief already has Evidence, so the run starts on vocabulary_builder.
        Evidence::create([
            'mission_run_id' => MissionRun::findOrStart($learner, $mission)->id,
            'phase' => 'mission_brief',
            'type' => Evidence::TYPE_SCORE,
            'content_ref' => '2',
        ]);

        return [$learner, $mission, MissionRun::findOrStart($learner, $mission)];
    }

    /** The first 8 of the 10 story words — leaves 2 spare to test the "extra word" behaviour. */
    private function firstEight(): array
    {
        return array_slice(self::STORY_WORDS, 0, 8);
    }

    private function selectEight($component)
    {
        foreach ($this->firstEight() as $word) {
            $component->call('toggleWord', $word);
        }

        return $component;
    }

    public function test_the_story_shows_with_selectable_words_and_a_live_counter(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $html = Livewire::test('missions.steps.vocabulary-builder', ['run' => $run])->html();

        $this->assertStringContainsString('0 of 8 selected', $html);
        foreach (self::STORY_WORDS as $word) {
            $this->assertStringContainsString("toggleWord('{$word}')", $html);
        }
    }

    public function test_selecting_8_words_reveals_the_continue_button(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $component = Livewire::test('missions.steps.vocabulary-builder', ['run' => $run]);
        $this->selectEight($component);

        $component
            ->assertSet('selectedWords', $this->firstEight())
            ->assertSee('Continue with these 8 words');
    }

    public function test_selecting_a_9th_word_adds_it_there_is_no_upper_limit(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $component = Livewire::test('missions.steps.vocabulary-builder', ['run' => $run]);
        $this->selectEight($component);

        $component
            ->call('toggleWord', 'sleep in') // the 9th word — not part of the first 8
            ->assertSet('selectedWords', [...$this->firstEight(), 'sleep in'])
            ->assertSee('Continue with these 9 words');
    }

    public function test_deselecting_a_word_frees_up_a_slot_for_another(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $component = Livewire::test('missions.steps.vocabulary-builder', ['run' => $run]);
        $this->selectEight($component);

        $component
            ->call('toggleWord', 'wake up') // deselect the first
            ->assertSet('selectedWords', array_slice(self::STORY_WORDS, 1, 7))
            ->call('toggleWord', 'sleep in') // pick the spare 9th word instead
            ->assertSet('selectedWords', [...array_slice(self::STORY_WORDS, 1, 7), 'sleep in']);
    }

    public function test_deselecting_is_disabled_once_practice_has_started(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $component = Livewire::test('missions.steps.vocabulary-builder', ['run' => $run]);
        $this->selectEight($component);

        $component
            ->call('startPractice')
            ->call('toggleWord', 'wake up') // attempt to deselect — must be a no-op now
            ->assertSet('selectedWords', $this->firstEight());
    }

    public function test_new_words_can_still_be_added_after_practice_has_started(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $component = Livewire::test('missions.steps.vocabulary-builder', ['run' => $run]);
        $this->selectEight($component);

        $component
            ->call('startPractice')
            ->call('toggleWord', 'sleep in') // adding is still allowed — only removal is locked
            ->assertSet('selectedWords', [...$this->firstEight(), 'sleep in']);
    }

    public function test_read_only_mode_skips_selection_but_shows_the_story_as_reference(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'vocabulary_builder',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode([
                'selected_words' => $this->firstEight(),
                'examples' => [
                    ['word' => 'commute', 'example' => 'I commute by bus.'],
                    ['word' => 'day off', 'example' => 'Sunday is my day off.'],
                ],
            ]),
        ]);

        $html = Livewire::test('missions.steps.vocabulary-builder', ['run' => $run, 'readOnly' => true])->html();

        $this->assertStringContainsString("phase: 'practice'", $html);
        $this->assertStringContainsString('follow my', $html); // story text present as reference
        $this->assertStringNotContainsString('Continue with these 8 words', $html);
        // Words render as plain highlighted text in review — clicking must
        // never be able to change what was actually submitted.
        $this->assertStringNotContainsString('wire:click="toggleWord', $html);
    }

    public function test_at_least_three_examples_are_required(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $component = Livewire::test('missions.steps.vocabulary-builder', ['run' => $run]);
        $this->selectEight($component);

        $component
            ->set('examples.0', 'I have a morning routine.')
            ->set('examples.1', 'I commute by bus.')
            ->call('save')
            ->assertHasErrors(['examples']);

        $this->assertDatabaseCount('evidences', 1); // only the mission_brief one from setup
    }

    public function test_three_examples_save_evidence_and_advance_the_run(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(3)->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        $component = Livewire::test('missions.steps.vocabulary-builder', ['run' => $run]);
        $this->selectEight($component);

        $component
            ->set('examples.0', 'I have a morning routine.')
            ->set('examples.1', 'I commute by bus.')
            ->set('examples.2', 'Sunday is my day off.')
            ->call('save')
            ->assertRedirect(route('missions.show', $run->mission));

        $evidence = Evidence::where('phase', 'vocabulary_builder')->first();
        $this->assertNotNull($evidence);

        $content = json_decode($evidence->content_ref, true);
        $this->assertSame($this->firstEight(), $content['selected_words']);
        $this->assertCount(3, $content['examples']);

        $this->assertSame('listening', $run->fresh()->currentStepKey());
    }

    public function test_saving_enrolls_every_selected_word_into_the_vocabulary_notebook(): void
    {
        [$learner, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(3)->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        $component = Livewire::test('missions.steps.vocabulary-builder', ['run' => $run]);
        $this->selectEight($component);

        $component
            ->set('examples.0', 'I have a morning routine.')
            ->set('examples.1', 'I commute by bus.')
            ->set('examples.2', 'Sunday is my day off.')
            ->call('save');

        $this->assertSame(8, VocabularyWord::where('learner_id', $learner->id)->count());

        $wakeUp = VocabularyWord::where('learner_id', $learner->id)->where('word', 'wake up')->firstOrFail();
        $this->assertSame($run->id, $wakeUp->source_mission_run_id);
        $this->assertSame('to stop sleeping', $wakeUp->meaning);
        $this->assertSame(0, $wakeUp->repetitions);
        $this->assertTrue($wakeUp->isDue());
    }

    public function test_picking_an_already_tracked_word_again_never_resets_its_review_progress(): void
    {
        [$learner, , $run] = $this->makeMissionAndRun();

        $existing = VocabularyWord::create([
            'learner_id' => $learner->id,
            'word' => 'wake up',
            'meaning' => 'stale meaning',
            'repetitions' => 4,
            'interval_days' => 30,
            'ease_factor' => 2.8,
            'next_review_at' => now()->addDays(30),
        ]);

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(3)->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        $component = Livewire::test('missions.steps.vocabulary-builder', ['run' => $run]);
        $this->selectEight($component);

        $component
            ->set('examples.0', 'I have a morning routine.')
            ->set('examples.1', 'I commute by bus.')
            ->set('examples.2', 'Sunday is my day off.')
            ->call('save');

        $this->assertSame(4, $existing->fresh()->repetitions);
        $this->assertSame('stale meaning', $existing->fresh()->meaning);
        $this->assertSame(8, VocabularyWord::where('learner_id', $learner->id)->count());
    }

    public function test_continue_checks_every_unchecked_filled_sentence_and_blocks_on_a_major_issue(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        // A copied definition sailed through Continue in the old flow — now
        // Continue checks it itself and must block on the "major" verdict.
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->twice()
                ->andReturn(json_encode(['severity' => 'none', 'hint' => '']))
                ->ordered();
            $mock->shouldReceive('chat')
                ->once()
                ->andReturn(json_encode(['severity' => 'major', 'hint' => 'Describe your own actual commute.']))
                ->ordered();
        });

        $component = Livewire::test('missions.steps.vocabulary-builder', ['run' => $run]);
        $this->selectEight($component);

        $component
            ->set('examples.0', 'I have a morning routine.')
            ->set('examples.1', 'travel to work') // examples.1 corresponds to "routine"; examples.3 to "commute"
            ->set('examples.3', 'a personal sentence')
            ->call('save')
            ->assertHasErrors(['examples'])
            ->assertSee('Describe your own actual commute.');

        $this->assertDatabaseCount('evidences', 1); // only the mission_brief one from setup — blocked before save
    }

    public function test_continue_proceeds_when_every_filled_sentence_is_minor_or_none(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->once()
                ->andReturn(json_encode(['severity' => 'minor', 'hint' => 'Try "I commute to work."']));
            $mock->shouldReceive('chat')
                ->twice()
                ->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        $component = Livewire::test('missions.steps.vocabulary-builder', ['run' => $run]);
        $this->selectEight($component);

        $component
            ->set('examples.0', 'I have a morning routine.')
            ->set('examples.1', 'I have my own routine.')
            ->set('examples.2', 'I get up early.')
            ->call('save')
            ->assertRedirect(route('missions.show', $run->mission));
    }

    public function test_continue_does_not_recheck_a_sentence_already_checked_against_the_same_text(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, function ($mock) {
            // Exactly 3 calls total: the manual checkOne(0), plus one each
            // for the two still-unchecked words — never a 4th for word 0.
            $mock->shouldReceive('chat')->times(3)->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        $component = Livewire::test('missions.steps.vocabulary-builder', ['run' => $run]);
        $this->selectEight($component);

        $component
            ->set('examples.0', 'I usually wake up around 7.')
            ->call('checkOne', 0)
            ->set('examples.1', 'I have a morning routine.')
            ->set('examples.2', 'I get up straight away.')
            ->call('save')
            ->assertRedirect(route('missions.show', $run->mission));
    }

    public function test_continue_rechecks_a_sentence_edited_since_its_last_check(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->once()
                ->andReturn(json_encode(['severity' => 'major', 'hint' => 'Write your own sentence.']))
                ->ordered();
            // The word-0 recheck (text changed since the manual check) plus
            // the other two still-unchecked words.
            $mock->shouldReceive('chat')
                ->times(3)
                ->andReturn(json_encode(['severity' => 'none', 'hint' => '']))
                ->ordered();
        });

        $component = Livewire::test('missions.steps.vocabulary-builder', ['run' => $run]);
        $this->selectEight($component);

        $component
            ->set('examples.0', 'to stop sleeping')
            ->call('checkOne', 0)
            ->set('examples.0', 'I usually wake up around 7.') // edited after the check
            ->set('examples.1', 'I have a morning routine.')
            ->set('examples.2', 'I get up straight away.')
            ->call('save')
            ->assertRedirect(route('missions.show', $run->mission));
    }

    public function test_checking_one_input_does_not_touch_the_others_and_nothing_is_saved(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->once()
                ->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        $component = Livewire::test('missions.steps.vocabulary-builder', ['run' => $run]);
        $this->selectEight($component);

        $component
            ->set('examples.0', 'I usually wake up around 7.')
            ->call('checkOne', 0)
            ->assertSet('feedback.wake up.severity', 'none')
            ->assertSet('feedback.routine', null);

        $this->assertDatabaseCount('evidences', 1); // checking never saves anything
    }

    public function test_checking_a_copied_definition_shows_a_guiding_hint_not_the_answer(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode([
                'severity' => 'major',
                'hint' => 'This just repeats the definition — can you describe your own actual commute?',
            ]));
        });

        $component = Livewire::test('missions.steps.vocabulary-builder', ['run' => $run]);
        $this->selectEight($component);

        $component
            ->set('examples.3', 'to travel to work') // index 3 = "commute"
            ->call('checkOne', 3)
            ->assertSee('can you describe your own actual commute?');
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

        $component = Livewire::test('missions.steps.vocabulary-builder', ['run' => $run]);
        $this->selectEight($component);

        $component->set('examples.3', 'to travel to work')->call('checkOne', 3);

        $this->assertSame(1, $run->fresh()->struggle_signal_count);
    }

    public function test_checking_an_empty_input_does_nothing(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldNotReceive('chat'));

        $component = Livewire::test('missions.steps.vocabulary-builder', ['run' => $run]);
        $this->selectEight($component);

        $component->call('checkOne', 0)->assertSet('feedback', []);
    }

    public function test_a_failed_check_shows_an_error_for_just_that_input(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andThrow(new \RuntimeException('service unavailable'));
        });

        $component = Livewire::test('missions.steps.vocabulary-builder', ['run' => $run]);
        $this->selectEight($component);

        $component
            ->set('examples.0', 'I usually wake up around 7.')
            ->call('checkOne', 0)
            ->assertSet('checkErrors.wake up', fn ($error) => str_contains($error, 'service unavailable'))
            ->assertSet('examples.0', 'I usually wake up around 7.'); // input preserved
    }

    public function test_a_connection_failure_shows_a_friendly_retry_message_not_a_raw_error(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andThrow(
                new ConnectionException('cURL error 7: Failed to connect() to host')
            );
        });

        $component = Livewire::test('missions.steps.vocabulary-builder', ['run' => $run]);
        $this->selectEight($component);

        $component
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
            'phase' => 'vocabulary_builder',
            'type' => Evidence::TYPE_TEXT,
            // Only 2 of the 8 selected words were filled — mirrors the real "3+ filled" save format.
            'content_ref' => json_encode([
                'selected_words' => $this->firstEight(),
                'examples' => [
                    ['word' => 'commute', 'example' => 'I commute by bus.'],
                    ['word' => 'day off', 'example' => 'Sunday is my day off.'],
                ],
            ]),
        ]);

        Livewire::test('missions.steps.vocabulary-builder', ['run' => $run, 'readOnly' => true])
            ->assertSet('examples.0', '') // wake up — not filled
            ->assertSet('examples.3', 'I commute by bus.') // commute is index 3
            ->assertSet('examples.7', 'Sunday is my day off.') // day off is index 7
            ->assertDontSee('Continue with these 8 words');
    }

    public function test_checking_a_word_blocks_every_other_input_and_shows_a_checking_indicator(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $component = Livewire::test('missions.steps.vocabulary-builder', ['run' => $run]);
        $this->selectEight($component);
        $html = $component->html();

        // Every input, every Check button, the results wrapper, and Continue
        // all share the same wire:target (checkOne, revealCorrection,
        // declineReveal, or save) so ANY in-flight checkOne call — or
        // Continue's own bulk check — blocks clicks on all of them at once:
        // 2 per word (input + button) plus the results wrapper and Continue.
        // The "AI is thinking" indicator itself is scoped per-word
        // (checkOne(0), checkOne(1)…) so it appears only on the card
        // actually being checked.
        $expected = 2 * count($this->firstEight()) + 2;
        $this->assertSame($expected, substr_count($html, 'wire:target="checkOne,revealCorrection,declineReveal,save"'));
        $this->assertStringContainsString('AI is thinking', $html);
    }

    public function test_feedback_renders_inside_a_severity_coloured_box(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode([
                'severity' => 'major',
                'hint' => 'Can you describe your own actual commute?',
            ]));
        });

        $component = Livewire::test('missions.steps.vocabulary-builder', ['run' => $run]);
        $this->selectEight($component);

        $component
            ->set('examples.3', 'to travel to work')
            ->call('checkOne', 3)
            ->assertSeeHtml('bg-red-50')
            ->assertSee('Can you describe your own actual commute?');
    }

    public function test_editing_a_checked_input_is_wired_to_dismiss_its_old_feedback(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $component = Livewire::test('missions.steps.vocabulary-builder', ['run' => $run]);
        $this->selectEight($component);
        $html = $component->html();

        // A stale verdict must fade the instant the learner edits that word's
        // input again — wired client-side (Alpine), so this only checks the
        // markup is present; the actual show/hide can't be exercised here.
        $this->assertStringContainsString('dismissed[0] = true', $html);
        $this->assertStringContainsString('x-show="!dismissed[0]"', $html);
    }

    public function test_clicking_check_hides_the_stale_result_until_the_fresh_one_lands(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $component = Livewire::test('missions.steps.vocabulary-builder', ['run' => $run]);
        $this->selectEight($component);
        $html = $component->html();

        // Regression guard: dismissing must happen on click (so a previous
        // word's verdict disappears immediately), and un-dismissing only
        // inside the $wire.checkOne(...).then() callback — i.e. after the
        // fresh result has actually landed — never eagerly on click, which
        // would flash the OLD stale verdict back up during the request.
        $this->assertStringContainsString(
            "dismissed['0'] = true; \$wire.checkOne(0).then(() => { dismissed['0'] = false })",
            $html
        );
        // Driven entirely through $wire from Alpine now — a separate
        // wire:click="checkOne(0)" would double-fire the request.
        $this->assertStringNotContainsString('wire:click="checkOne(0)"', $html);
    }

    public function test_unfilled_words_are_wired_as_bonus_practice_once_the_minimum_is_met(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $component = Livewire::test('missions.steps.vocabulary-builder', ['run' => $run]);
        $this->selectEight($component);
        $html = $component->html();

        // Client-side (Alpine) — every word card carries a bonus-practice hint
        // that only shows once filledCount >= 3 AND that specific word is
        // still empty; presence in markup is all that's checkable here.
        $this->assertSame(8, substr_count($html, 'optional — bonus practice'));
        $this->assertStringContainsString('filledCount >= 3 && !filled[0]', $html);
    }

    public function test_the_progress_bar_and_message_show_in_edit_mode_but_not_in_review(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        // The progress message text itself is client-rendered (x-text), so it
        // always exists inside the component's inline x-data JS regardless of
        // $readOnly — not a meaningful signal here. The bar/message wrapper's
        // markup, scoped inside @unless($readOnly), is what actually differs.
        Livewire::test('missions.steps.vocabulary-builder', ['run' => $run])
            ->assertSeeHtml('h-1.5 w-full overflow-hidden rounded-full')
            ->assertSeeHtml('filledCount >= 3');

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'vocabulary_builder',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode([
                'selected_words' => $this->firstEight(),
                'examples' => [
                    ['word' => 'wake up', 'example' => 'I usually wake up around 7.'],
                    ['word' => 'routine', 'example' => 'I have a morning routine.'],
                    ['word' => 'commute', 'example' => 'I commute by bus.'],
                ],
            ]),
        ]);

        Livewire::test('missions.steps.vocabulary-builder', ['run' => $run, 'readOnly' => true])
            ->assertDontSeeHtml('h-1.5 w-full overflow-hidden rounded-full')
            ->assertDontSeeHtml('filledCount >= 3');
    }

    public function test_clicking_check_on_an_empty_example_shows_an_error(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldNotReceive('chat');
        });

        $component = Livewire::test('missions.steps.vocabulary-builder', ['run' => $run]);
        $this->selectEight($component);

        $component->call('checkOne', 0)
            ->assertSet('checkErrors.wake up', 'Write something first.')
            ->assertSee('Write something first.');
    }

    public function test_three_failed_checks_on_a_word_offer_to_reveal_the_correction(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(3)->andReturn(json_encode(['severity' => 'major', 'hint' => 'Try again.']));
        });

        $component = Livewire::test('missions.steps.vocabulary-builder', ['run' => $run]);
        $this->selectEight($component);

        $component->set('examples.0', 'attempt one');
        $component->call('checkOne', 0);
        $component->call('checkOne', 0)
            ->assertSee('One more try — after that I can write the correct one for you');
        $component->call('checkOne', 0)
            ->assertSet('offerReveal.wake up', true)
            ->assertDontSee('One more try — after that I can write the correct one for you');
    }

    public function test_accepting_the_reveal_writes_the_ai_correction_into_the_example(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(3)->andReturn(json_encode(['severity' => 'major', 'hint' => 'Try again.']));
            $mock->shouldReceive('chat')->once()->andReturn('I usually wake up early.');
        });

        $component = Livewire::test('missions.steps.vocabulary-builder', ['run' => $run]);
        $this->selectEight($component);

        $component->set('examples.0', 'wake up bad sentence');
        $component->call('checkOne', 0);
        $component->call('checkOne', 0);
        $component->call('checkOne', 0)->assertSet('offerReveal.wake up', true);

        $component->call('revealCorrection', 0)
            ->assertSet('examples.0', 'I usually wake up early.')
            ->assertSet('feedback.wake up.severity', 'none')
            ->assertSet('offerReveal.wake up', null);
    }

    public function test_declining_the_reveal_resets_the_attempt_count(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(3)->andReturn(json_encode(['severity' => 'major', 'hint' => 'Try again.']));
        });

        $component = Livewire::test('missions.steps.vocabulary-builder', ['run' => $run]);
        $this->selectEight($component);

        $component->set('examples.0', 'attempt one');
        $component->call('checkOne', 0);
        $component->call('checkOne', 0);
        $component->call('checkOne', 0)->assertSet('offerReveal.wake up', true);

        $component->call('declineReveal', 0)
            ->assertSet('offerReveal.wake up', null)
            ->assertSet('checkAttempts.wake up', 0);
    }

    public function test_example_inputs_carry_a_draft_key_scoped_to_the_run(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $component = Livewire::test('missions.steps.vocabulary-builder', ['run' => $run]);
        $this->selectEight($component);

        $component->assertSeeHtml("eos-draft:{$run->id}:vocabulary_builder:examples.0");
    }

    public function test_a_successful_save_dispatches_a_clear_draft_event(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(3)->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        $component = Livewire::test('missions.steps.vocabulary-builder', ['run' => $run]);
        $this->selectEight($component);

        $component
            ->set('examples.0', 'I have a morning routine.')
            ->set('examples.1', 'I commute by bus.')
            ->set('examples.2', 'Sunday is my day off.')
            ->call('save')
            ->assertDispatched('clear-draft', prefix: "eos-draft:{$run->id}:vocabulary_builder:");
    }
}
