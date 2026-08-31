<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use App\Services\GeminiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class VocabularyBuilderStepTest extends TestCase
{
    use RefreshDatabase;

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
                            'vocabulary' => [
                                ['word' => 'routine', 'meaning' => 'the usual things you do'],
                                ['word' => 'commute', 'meaning' => 'travel to work'],
                                ['word' => 'day off', 'meaning' => 'a day when you don\'t work'],
                                ['word' => 'wind down', 'meaning' => 'to relax before sleep'],
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

    public function test_at_least_three_examples_are_required(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        Livewire::test('missions.steps.vocabulary-builder', ['run' => $run])
            ->set('examples.0', 'I have a morning routine.')
            ->set('examples.1', 'I commute by bus.')
            ->call('save')
            ->assertHasErrors(['examples']);

        $this->assertDatabaseCount('evidences', 1); // only the mission_brief one from setup
    }

    public function test_three_examples_save_evidence_and_advance_the_run(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        Livewire::test('missions.steps.vocabulary-builder', ['run' => $run])
            ->set('examples.0', 'I have a morning routine.')
            ->set('examples.1', 'I commute by bus.')
            ->set('examples.2', 'Sunday is my day off.')
            ->call('save')
            ->assertRedirect(route('missions.show', $run->mission));

        $evidence = Evidence::where('phase', 'vocabulary_builder')->first();
        $this->assertNotNull($evidence);
        $this->assertCount(3, json_decode($evidence->content_ref, true));

        $this->assertSame('listening', $run->fresh()->currentStepKey());
    }

    public function test_continue_never_calls_gemini_and_ignores_any_unchecked_input(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        // Even a copied definition sails through Continue — it was never checked.
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldNotReceive('chat'));

        Livewire::test('missions.steps.vocabulary-builder', ['run' => $run])
            ->set('examples.0', 'I have a morning routine.')
            ->set('examples.1', 'travel to work')
            ->set('examples.2', 'Sunday is my day off.')
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

        Livewire::test('missions.steps.vocabulary-builder', ['run' => $run])
            ->set('examples.0', 'I have a morning routine.')
            ->call('checkOne', 0)
            ->assertSet('feedback.routine.severity', 'none')
            ->assertSet('feedback.commute', null);

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

        Livewire::test('missions.steps.vocabulary-builder', ['run' => $run])
            ->set('examples.1', 'to travel to work or school regularly')
            ->call('checkOne', 1)
            ->assertSee('can you describe your own actual commute?');
    }

    public function test_checking_an_empty_input_does_nothing(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldNotReceive('chat'));

        Livewire::test('missions.steps.vocabulary-builder', ['run' => $run])
            ->call('checkOne', 0)
            ->assertSet('feedback', []);
    }

    public function test_a_failed_check_shows_an_error_for_just_that_input(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andThrow(new \RuntimeException('service unavailable'));
        });

        Livewire::test('missions.steps.vocabulary-builder', ['run' => $run])
            ->set('examples.0', 'I have a morning routine.')
            ->call('checkOne', 0)
            ->assertSet('checkErrors.routine', fn ($error) => str_contains($error, 'service unavailable'))
            ->assertSet('examples.0', 'I have a morning routine.'); // input preserved
    }

    public function test_a_connection_failure_shows_a_friendly_retry_message_not_a_raw_error(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andThrow(
                new \Illuminate\Http\Client\ConnectionException('cURL error 7: Failed to connect() to host')
            );
        });

        Livewire::test('missions.steps.vocabulary-builder', ['run' => $run])
            ->set('examples.0', 'I have a morning routine.')
            ->call('checkOne', 0)
            ->assertSet('checkErrors.routine', "Couldn't reach the AI service — please try again.")
            ->assertDontSee('cURL error');
    }

    public function test_read_only_mode_maps_saved_examples_back_to_the_right_word(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'vocabulary_builder',
            'type' => Evidence::TYPE_TEXT,
            // Only 2 of the 4 words were filled — mirrors the real "3+ filled" save format.
            'content_ref' => json_encode([
                ['word' => 'commute', 'example' => 'I commute by bus.'],
                ['word' => 'day off', 'example' => 'Sunday is my day off.'],
            ]),
        ]);

        Livewire::test('missions.steps.vocabulary-builder', ['run' => $run, 'readOnly' => true])
            ->assertSet('examples.0', '') // routine — not filled
            ->assertSet('examples.1', 'I commute by bus.')
            ->assertSet('examples.2', 'Sunday is my day off.')
            ->assertSet('examples.3', '') // wind down — not filled
            ->assertDontSee('Continue');
    }

    public function test_checking_a_word_blocks_every_other_input_and_shows_a_checking_indicator(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $html = Livewire::test('missions.steps.vocabulary-builder', ['run' => $run])->html();

        // Every input, every Check button, the results wrapper, and Continue
        // all share the same unparameterised wire:target so ANY in-flight
        // checkOne call blocks clicks on all of them at once — 2 per word
        // (input + button) plus the results wrapper and Continue. The "AI is
        // thinking" indicator itself is scoped per-word (checkOne(0),
        // checkOne(1)…) so it appears only on the card actually being checked.
        $expected = 2 * count($run->mission->stepContent('vocabulary_builder')['vocabulary']) + 2;
        $this->assertSame($expected, substr_count($html, 'wire:target="checkOne"'));
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

        Livewire::test('missions.steps.vocabulary-builder', ['run' => $run])
            ->set('examples.1', 'to travel to work or school regularly')
            ->call('checkOne', 1)
            ->assertSeeHtml('bg-red-50')
            ->assertSee('Can you describe your own actual commute?');
    }

    public function test_editing_a_checked_input_is_wired_to_dismiss_its_old_feedback(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $html = Livewire::test('missions.steps.vocabulary-builder', ['run' => $run])->html();

        // A stale verdict must fade the instant the learner edits that word's
        // input again — wired client-side (Alpine), so this only checks the
        // markup is present; the actual show/hide can't be exercised here.
        $this->assertStringContainsString("dismissed[0] = true", $html);
        $this->assertStringContainsString("dismissed[0] = false", $html);
        $this->assertStringContainsString('x-show="!dismissed[0]"', $html);
    }

    public function test_unfilled_words_are_wired_as_bonus_practice_once_the_minimum_is_met(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $html = Livewire::test('missions.steps.vocabulary-builder', ['run' => $run])->html();

        // Client-side (Alpine) — every word card carries a bonus-practice hint
        // that only shows once filledCount >= 3 AND that specific word is
        // still empty; presence in markup is all that's checkable here.
        $this->assertSame(4, substr_count($html, 'optional — bonus practice'));
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
                ['word' => 'routine', 'example' => 'I have a morning routine.'],
                ['word' => 'commute', 'example' => 'I commute by bus.'],
                ['word' => 'day off', 'example' => 'Sunday is my day off.'],
            ]),
        ]);

        Livewire::test('missions.steps.vocabulary-builder', ['run' => $run, 'readOnly' => true])
            ->assertDontSeeHtml('h-1.5 w-full overflow-hidden rounded-full')
            ->assertDontSeeHtml('filledCount >= 3');
    }
}
