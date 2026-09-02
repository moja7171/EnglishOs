<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use App\Services\GeminiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Livewire\Livewire;
use Tests\TestCase;

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
                            'topic_summary' => 'Neil and Georgie talk about their morning routines: get up '
                                .'early or sleep in, breakfast habits, oversleep, exercise, checking the weather.',
                            'target_phrases' => [
                                ['phrase' => 'sleep in', 'meaning' => 'to stay in bed and sleep later than usual'],
                                ['phrase' => 'morning person', 'meaning' => 'someone with lots of energy in the morning'],
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

    public function test_the_wrap_up_offers_discussing_the_topic_with_a_mutual_friend(): void
    {
        $run = $this->makeRun();
        $friend = User::factory()->create(['name' => 'Priya']);
        $run->learner->follow($friend);
        $friend->follow($run->learner);

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
        // A native range input, not a custom click-math div — gives real
        // keyboard (arrow key) and click/drag seeking for free.
        $this->assertStringContainsString('type="range"', $html);
        $this->assertStringContainsString('download', $html);
    }

    public function test_all_three_gist_points_are_required(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.listening', ['run' => $run])
            ->set('gistPoints.0', 'It is about morning routines.')
            ->call('save')
            ->assertHasErrors(['gistPoints']);

        $this->assertDatabaseCount('evidences', 0);
    }

    public function test_saving_records_evidence_and_advances_the_run(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(4)->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        Livewire::test('missions.steps.listening', ['run' => $run])
            ->set('gistPoints.0', 'They talk about morning routines.')
            ->set('gistPoints.1', 'Some people get up early or late.')
            ->set('gistPoints.2', 'They mention breakfast habits.')
            ->set('expressionsHeard.0', 'I like to sleep in on weekends.')
            ->call('save')
            ->assertSet('completed', true) // shows the language recap first
            ->assertOk()
            ->call('proceed')
            ->assertRedirect(route('missions.show', $run->mission));

        $evidence = Evidence::where('phase', 'listening')->first();
        $this->assertNotNull($evidence);

        $content = json_decode($evidence->content_ref, true);
        $this->assertCount(3, $content['gist_points']);
        $this->assertArrayNotHasKey('expression_missed', $content);
        $this->assertArrayNotHasKey('expression_to_use', $content);

        $this->assertSame('grammar_in_context', $run->fresh()->currentStepKey());
    }

    public function test_completing_the_step_shows_a_language_recap_before_proceeding(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(3)->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        Livewire::test('missions.steps.listening', ['run' => $run])
            ->set('gistPoints.0', 'They talk about morning routines.')
            ->set('gistPoints.1', 'Some people get up early or late.')
            ->set('gistPoints.2', 'They mention breakfast habits.')
            ->call('save')
            ->assertSee('sleep in')
            ->assertSee('to stay in bed and sleep later than usual')
            ->assertSee('morning person')
            // The edit form (with its own "Continue" wording) is replaced by
            // the recap, not shown alongside it.
            ->assertDontSee('Checking your sentences');

        // Nothing has navigated away yet — evidence is saved, but the
        // learner is still looking at the recap until they click through.
        $this->assertDatabaseCount('evidences', 1);
    }

    public function test_the_second_listening_section_is_gated_behind_the_three_gist_points(): void
    {
        $run = $this->makeRun();

        $html = Livewire::test('missions.steps.listening', ['run' => $run])->html();

        // Sub-step wizard: Next is disabled until gistDone, with a hint
        // explaining why, instead of the old stacked/opacity-locked section.
        // "&&" renders HTML-entity-escaped inside the attribute (harmless —
        // browsers decode it before Alpine ever sees it).
        $this->assertStringContainsString('Write all 3 to move on.', $html);
        $this->assertStringContainsString('activeSubstep === 0 &amp;&amp; !gistDone', $html);
    }

    public function test_clicking_a_target_phrase_chip_is_wired_to_fill_the_first_empty_expression_input(): void
    {
        $run = $this->makeRun();

        $html = Livewire::test('missions.steps.listening', ['run' => $run])->html();

        $this->assertStringContainsString('$wire.expressionsHeard.findIndex', $html);
        $this->assertStringContainsString("\$wire.set('expressionsHeard.' + idx, 'Sleep in')", $html);
    }

    public function test_continue_checks_every_unchecked_filled_sentence_and_blocks_on_a_major_issue(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->twice()
                ->andReturn(json_encode(['severity' => 'none', 'hint' => '']))
                ->ordered();
            // The off-topic gist point is the 3rd (and last) check.
            $mock->shouldReceive('chat')
                ->once()
                ->andReturn(json_encode(['severity' => 'major', 'hint' => 'Does this relate to what they said about mornings?']))
                ->ordered();
        });

        Livewire::test('missions.steps.listening', ['run' => $run])
            ->set('gistPoints.0', 'They talk about morning routines.')
            ->set('gistPoints.1', 'Some people get up early or late.')
            ->set('gistPoints.2', 'I really enjoy playing video games at night.') // off-topic
            ->call('save')
            ->assertHasErrors(['sentences'])
            ->assertSee('Does this relate to what they said about mornings?');

        $this->assertDatabaseCount('evidences', 0);
    }

    public function test_checking_one_field_does_not_touch_the_others_and_nothing_is_saved(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        Livewire::test('missions.steps.listening', ['run' => $run])
            ->set('gistPoints.0', 'They talk about morning routines.')
            ->call('checkGist', 0)
            ->assertSet('feedback.gist_0.severity', 'none')
            ->assertSet('feedback.expr_0', null);

        $this->assertDatabaseCount('evidences', 0);
    }

    public function test_checking_an_empty_field_does_nothing(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldNotReceive('chat'));

        Livewire::test('missions.steps.listening', ['run' => $run])
            ->call('checkExpression', 0)
            ->assertSet('feedback', []);
    }

    public function test_a_connection_failure_shows_a_friendly_retry_message_not_a_raw_error(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andThrow(
                new ConnectionException('cURL error 7: Failed to connect() to host')
            );
        });

        Livewire::test('missions.steps.listening', ['run' => $run])
            ->set('expressionsHeard.0', 'I like to sleep in on weekends.')
            ->call('checkExpression', 0)
            ->assertSet('checkErrors.expr_0', "Couldn't reach the AI service — please try again.")
            ->assertDontSee('cURL error');
    }

    public function test_read_only_mode_maps_saved_answers_back(): void
    {
        $run = $this->makeRun();

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'listening',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode([
                'gist_points' => ['A.', 'B.', 'C.'],
                'expressions_heard' => ['I like to sleep in.'],
            ]),
        ]);

        Livewire::test('missions.steps.listening', ['run' => $run, 'readOnly' => true])
            ->assertSet('gistPoints.0', 'A.')
            ->assertSet('expressionsHeard.0', 'I like to sleep in.')
            ->assertDontSee('Continue');
    }

    public function test_clicking_check_on_an_empty_gist_point_shows_an_error(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldNotReceive('chat');
        });

        Livewire::test('missions.steps.listening', ['run' => $run])
            ->call('checkGist', 0)
            ->assertSet('checkErrors.gist_0', 'Write something first.')
            ->assertSee('Write something first.');
    }

    public function test_clicking_check_on_an_empty_expression_shows_an_error(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldNotReceive('chat');
        });

        Livewire::test('missions.steps.listening', ['run' => $run])
            ->call('checkExpression', 0)
            ->assertSet('checkErrors.expr_0', 'Write something first.')
            ->assertSee('Write something first.');
    }

    public function test_three_failed_gist_checks_offer_to_reveal_the_correction(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(3)->andReturn(json_encode(['severity' => 'major', 'hint' => 'Try again.']));
        });

        $component = Livewire::test('missions.steps.listening', ['run' => $run])
            ->set('gistPoints.0', 'attempt one');

        $component->call('checkGist', 0);
        $component->call('checkGist', 0)
            ->assertSee('One more try — after that I can write the correct one for you');
        $component->call('checkGist', 0)
            ->assertSet('offerReveal.gist_0', true)
            ->assertDontSee('One more try — after that I can write the correct one for you');
    }

    public function test_accepting_the_gist_reveal_writes_the_ai_correction(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(3)->andReturn(json_encode(['severity' => 'major', 'hint' => 'Try again.']));
            $mock->shouldReceive('chat')->once()->andReturn('They talk about their morning routines.');
        });

        $component = Livewire::test('missions.steps.listening', ['run' => $run])
            ->set('gistPoints.0', 'bad fragment');

        $component->call('checkGist', 0);
        $component->call('checkGist', 0);
        $component->call('checkGist', 0)->assertSet('offerReveal.gist_0', true);

        $component->call('revealGist', 0)
            ->assertSet('gistPoints.0', 'They talk about their morning routines.')
            ->assertSet('feedback.gist_0.severity', 'none')
            ->assertSet('offerReveal.gist_0', null);
    }

    public function test_declining_the_expression_reveal_resets_the_attempt_count(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(3)->andReturn(json_encode(['severity' => 'major', 'hint' => 'Try again.']));
        });

        $component = Livewire::test('missions.steps.listening', ['run' => $run])
            ->set('expressionsHeard.0', 'attempt one');

        $component->call('checkExpression', 0);
        $component->call('checkExpression', 0);
        $component->call('checkExpression', 0)->assertSet('offerReveal.expr_0', true);

        $component->call('declineExpression', 0)
            ->assertSet('offerReveal.expr_0', null)
            ->assertSet('checkAttempts.expr_0', 0);
    }

    public function test_gist_and_expression_inputs_carry_a_draft_key_scoped_to_the_run(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.listening', ['run' => $run])
            ->assertSeeHtml("eos-draft:{$run->id}:listening:gistPoints.0")
            ->assertSeeHtml("eos-draft:{$run->id}:listening:expressionsHeard.0");
    }

    public function test_a_successful_save_dispatches_a_clear_draft_event(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(4)->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        Livewire::test('missions.steps.listening', ['run' => $run])
            ->set('gistPoints.0', 'They talk about morning routines.')
            ->set('gistPoints.1', 'Some people get up early or late.')
            ->set('gistPoints.2', 'They mention breakfast habits.')
            ->set('expressionsHeard.0', 'I like to sleep in on weekends.')
            ->call('save')
            ->assertDispatched('clear-draft', prefix: "eos-draft:{$run->id}:listening:");
    }

    public function test_the_gist_section_shows_a_fill_progress_bar(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.listening', ['run' => $run])
            ->assertSeeHtml('h-1.5 w-full overflow-hidden rounded-full')
            ->assertSeeHtml('of 3 written');
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

        // Locked message + the real Alpine wiring that counts real completed
        // plays (the "ended" event), not just clicks — must be present.
        $this->assertStringContainsString('listenCount', $html);
        $this->assertStringContainsString('Math.min(listenCount, 2)}/2 times', $html);
        $this->assertStringContainsString('dispatch(&#039;audio-ended&#039;)', $html);
        // The transcript text is server-rendered either way (client-side
        // x-show hides it) so it can appear instantly once unlocked.
        $this->assertStringContainsString('Hello and welcome.', $html);
    }

    public function test_the_transcript_is_never_gated_when_reviewing_a_completed_step(): void
    {
        $run = $this->makeRunWithTranscript();

        $html = Livewire::test('missions.steps.listening', ['run' => $run, 'readOnly' => true])->html();

        $this->assertStringNotContainsString('times to unlock the transcript', $html);
        $this->assertStringContainsString('Show transcript', $html);
    }

    public function test_no_transcript_section_renders_when_the_mission_has_no_transcript_authored(): void
    {
        $run = $this->makeRun();

        $html = Livewire::test('missions.steps.listening', ['run' => $run])->html();

        $this->assertStringNotContainsString('unlock the transcript', $html);
        $this->assertStringNotContainsString('Show transcript', $html);
    }

    private function makeRunWithDetailAndGapFill(): MissionRun
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
                            'target_phrases' => [
                                ['phrase' => 'sleep in', 'meaning' => 'to stay in bed and sleep later than usual', 'gap_before' => 'I like to ', 'gap_after' => ' at weekends.'],
                            ],
                            'detail_question' => [
                                'question' => 'What time did Neil need to get up to catch his flight?',
                                'accepted' => ['3am', '3 am', 'three am'],
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

    public function test_the_detail_question_is_required_and_blocks_continue_when_empty(): void
    {
        $run = $this->makeRunWithDetailAndGapFill();

        Livewire::test('missions.steps.listening', ['run' => $run])
            ->set('gistPoints.0', 'They talk about morning routines.')
            ->set('gistPoints.1', 'Some people get up early or late.')
            ->set('gistPoints.2', 'They mention breakfast habits.')
            ->set('expressionsHeard.0', 'I like to sleep in on weekends.')
            ->call('save')
            ->assertHasErrors(['detailAnswer']);

        $this->assertDatabaseCount('evidences', 0);
    }

    public function test_a_wrong_detail_answer_blocks_continue(): void
    {
        $run = $this->makeRunWithDetailAndGapFill();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(4)->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        Livewire::test('missions.steps.listening', ['run' => $run])
            ->set('gistPoints.0', 'They talk about morning routines.')
            ->set('gistPoints.1', 'Some people get up early or late.')
            ->set('gistPoints.2', 'They mention breakfast habits.')
            ->set('expressionsHeard.0', 'I like to sleep in on weekends.')
            ->set('detailAnswer', 'Nine in the morning')
            ->call('save')
            ->assertHasErrors(['sentences']);

        $this->assertDatabaseCount('evidences', 0);
    }

    public function test_a_correct_detail_answer_saves_and_advances(): void
    {
        $run = $this->makeRunWithDetailAndGapFill();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(4)->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        Livewire::test('missions.steps.listening', ['run' => $run])
            ->set('gistPoints.0', 'They talk about morning routines.')
            ->set('gistPoints.1', 'Some people get up early or late.')
            ->set('gistPoints.2', 'They mention breakfast habits.')
            ->set('expressionsHeard.0', 'I like to sleep in on weekends.')
            ->set('detailAnswer', 'He needed to get up at 3am.')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('completed', true);

        $evidence = Evidence::where('phase', 'listening')->first();
        $content = json_decode($evidence->content_ref, true);
        $this->assertSame('He needed to get up at 3am.', $content['detail_answer']);
    }

    public function test_gap_fill_is_optional_and_never_blocks_continue(): void
    {
        $run = $this->makeRunWithDetailAndGapFill();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(4)->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        Livewire::test('missions.steps.listening', ['run' => $run])
            ->set('gistPoints.0', 'They talk about morning routines.')
            ->set('gistPoints.1', 'Some people get up early or late.')
            ->set('gistPoints.2', 'They mention breakfast habits.')
            ->set('expressionsHeard.0', 'I like to sleep in on weekends.')
            ->set('detailAnswer', '3am')
            // gapFillAnswers left entirely empty on purpose.
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('completed', true);
    }

    public function test_gap_fill_check_gives_non_blocking_feedback(): void
    {
        $run = $this->makeRunWithDetailAndGapFill();

        Livewire::test('missions.steps.listening', ['run' => $run])
            ->set('gapFillAnswers.0', 'sleep in')
            ->call('checkGapFill', 0)
            ->assertSet('gapFillFeedback.0.severity', 'none');

        Livewire::test('missions.steps.listening', ['run' => $run])
            ->set('gapFillAnswers.0', 'wake up late')
            ->call('checkGapFill', 0)
            ->assertSet('gapFillFeedback.0.severity', 'minor');
    }

    public function test_selecting_a_shadow_line_clears_any_previous_recording(): void
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
                    'shadow_lines' => ['Do you like to get up early or sleep in?', 'Are you a morning person?'],
                ]],
            ]],
        ]);
        $run = MissionRun::findOrStart($learner, $mission);

        Livewire::test('missions.steps.listening', ['run' => $run])
            ->assertSee('Line 1')
            ->assertSee('Line 2')
            ->call('selectShadowLine', 1)
            ->assertSet('activeShadowLine', 1)
            ->assertSet('shadowRecording', null)
            ->assertSee('Are you a morning person?');
    }
}
