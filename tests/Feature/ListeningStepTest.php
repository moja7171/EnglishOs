<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
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

        Livewire::test('missions.steps.listening', ['run' => $run])
            ->set('gistPoints.0', 'Morning routines.')
            ->set('gistPoints.1', 'Getting up early or late.')
            ->set('gistPoints.2', 'Breakfast habits.')
            ->set('expressionsHeard.0', 'sleep in')
            ->set('expressionMissed', 'oversleep')
            ->set('expressionToUse', 'morning person')
            ->call('save')
            ->assertRedirect(route('missions.show', $run->mission));

        $evidence = Evidence::where('phase', 'listening')->first();
        $this->assertNotNull($evidence);

        $content = json_decode($evidence->content_ref, true);
        $this->assertCount(3, $content['gist_points']);
        $this->assertSame('morning person', $content['expression_to_use']);

        $this->assertSame('grammar_in_context', $run->fresh()->currentStepKey());
    }
}
