<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
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
}
