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

        return MissionRun::findOrStart($learner, $mission);
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

        $this->assertStringContainsString('Finish the first listening above to unlock this.', $html);
        $this->assertStringContainsString("gistDone ? '' : 'pointer-events-none opacity-40'", $html);
    }

    public function test_clicking_a_target_phrase_chip_is_wired_to_fill_the_first_empty_expression_input(): void
    {
        $run = $this->makeRun();

        $html = Livewire::test('missions.steps.listening', ['run' => $run])->html();

        $this->assertStringContainsString("\$wire.expressionsHeard.findIndex", $html);
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
                new \Illuminate\Http\Client\ConnectionException('cURL error 7: Failed to connect() to host')
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

    public function test_three_failed_gist_checks_offer_to_reveal_the_correction(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(3)->andReturn(json_encode(['severity' => 'major', 'hint' => 'Try again.']));
        });

        $component = Livewire::test('missions.steps.listening', ['run' => $run])
            ->set('gistPoints.0', 'attempt one');

        $component->call('checkGist', 0);
        $component->call('checkGist', 0);
        $component->call('checkGist', 0)->assertSet('offerReveal.gist_0', true);
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
}
