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

    public function test_three_good_examples_pass_the_ai_check_and_advance_the_run(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode([
                ['word' => 'routine', 'severity' => 'none', 'hint' => ''],
                ['word' => 'commute', 'severity' => 'none', 'hint' => ''],
                ['word' => 'day off', 'severity' => 'none', 'hint' => ''],
            ]));
        });

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

    public function test_a_minor_issue_shows_a_hint_but_still_advances_the_run(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode([
                ['word' => 'routine', 'severity' => 'minor', 'hint' => 'Try adding "my" before routine.'],
                ['word' => 'commute', 'severity' => 'none', 'hint' => ''],
                ['word' => 'day off', 'severity' => 'none', 'hint' => ''],
            ]));
        });

        Livewire::test('missions.steps.vocabulary-builder', ['run' => $run])
            ->set('examples.0', 'I have morning routine.')
            ->set('examples.1', 'I commute by bus.')
            ->set('examples.2', 'Sunday is my day off.')
            ->call('save')
            ->assertRedirect(route('missions.show', $run->mission));

        $this->assertDatabaseHas('evidences', ['mission_run_id' => $run->id, 'phase' => 'vocabulary_builder']);
    }

    public function test_a_major_issue_blocks_saving_and_shows_a_guiding_hint_not_the_answer(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode([
                ['word' => 'routine', 'severity' => 'none', 'hint' => ''],
                [
                    'word' => 'commute',
                    'severity' => 'major',
                    'hint' => 'This just repeats the definition — can you describe your own actual commute?',
                ],
                ['word' => 'day off', 'severity' => 'none', 'hint' => ''],
            ]));
        });

        $component = Livewire::test('missions.steps.vocabulary-builder', ['run' => $run])
            ->set('examples.0', 'I have a morning routine.')
            ->set('examples.1', 'to travel to work or school regularly')
            ->set('examples.2', 'Sunday is my day off.')
            ->call('save');

        $component->assertSee('can you describe your own actual commute?');

        $this->assertDatabaseCount('evidences', 1); // only mission_brief from setup — nothing saved
        $this->assertSame('vocabulary_builder', $run->fresh()->currentStepKey());
    }

    public function test_a_failed_ai_check_shows_an_error_without_losing_the_learners_input(): void
    {
        [, , $run] = $this->makeMissionAndRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andThrow(new \RuntimeException('service unavailable'));
        });

        Livewire::test('missions.steps.vocabulary-builder', ['run' => $run])
            ->set('examples.0', 'I have a morning routine.')
            ->set('examples.1', 'I commute by bus.')
            ->set('examples.2', 'Sunday is my day off.')
            ->call('save')
            ->assertSet('error', fn ($error) => str_contains($error, 'service unavailable'))
            ->assertSet('examples.0', 'I have a morning routine.'); // input preserved

        $this->assertDatabaseCount('evidences', 1);
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
}
