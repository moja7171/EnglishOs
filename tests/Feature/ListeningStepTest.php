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
                        ],
                        ['key' => 'grammar_in_context'],
                    ],
                ],
            ],
        ]);

        return MissionRun::findOrStart($learner, $mission);
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
            ->assertRedirect(route('missions.show', $run->mission));

        $evidence = Evidence::where('phase', 'listening')->first();
        $this->assertNotNull($evidence);

        $content = json_decode($evidence->content_ref, true);
        $this->assertCount(3, $content['gist_points']);
        $this->assertArrayNotHasKey('expression_missed', $content);
        $this->assertArrayNotHasKey('expression_to_use', $content);

        $this->assertSame('grammar_in_context', $run->fresh()->currentStepKey());
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
}
