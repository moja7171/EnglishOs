<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\Reflection;
use App\Models\SelfAssessment;
use App\Models\User;
use App\Services\GeminiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MissionResultStepTest extends TestCase
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
                    'phase' => 'mission',
                    'steps' => [
                        [
                            'key' => 'mission_result',
                            'skills' => ['Speaking', 'Writing'],
                            'reflection_questions' => [
                                'became_easier' => 'What became easier?',
                                'still_difficult' => 'What is still difficult?',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        return MissionRun::findOrStart($learner, $mission);
    }

    public function test_all_skills_must_be_rated_before_and_after(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.mission-result', ['run' => $run])
            ->set('scores.Speaking.before', 2)
            ->set('scores.Speaking.after', 4)
            ->call('getResult')
            ->assertHasErrors(['scores']);

        $this->assertDatabaseCount('self_assessments', 0);
    }

    public function test_getting_a_result_then_finishing_persists_everything_and_completes_the_run(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode([
                'status' => 'complete',
                'reason' => 'You clearly improved and met the mission outcome.',
            ]));
        });

        $component = Livewire::test('missions.steps.mission-result', ['run' => $run])
            ->set('scores.Speaking.before', 2)->set('scores.Speaking.after', 4)
            ->set('scores.Writing.before', 3)->set('scores.Writing.after', 4)
            ->set('reflection.became_easier', 'Talking about my routine.')
            ->set('reflection.still_difficult', 'Past tense.')
            ->call('getResult');

        $component->assertSet('status', 'complete');

        $component->call('finish');

        $this->assertDatabaseCount('self_assessments', 2);
        $this->assertDatabaseHas('self_assessments', ['skill' => 'Speaking', 'before' => 2, 'after' => 4]);

        $reflection = Reflection::first();
        $this->assertSame('Talking about my routine.', $reflection->answers['became_easier']);

        $this->assertDatabaseHas('evidences', ['mission_run_id' => $run->id, 'phase' => 'mission_result']);

        $run->refresh();
        $this->assertSame('complete', $run->status);
        $this->assertNotNull($run->completed_at);
        $this->assertNull($run->currentStepKey());
    }

    public function test_the_result_shows_a_before_after_comparison_per_skill(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn(json_encode([
            'status' => 'complete',
            'reason' => 'Nice work.',
        ])));

        Livewire::test('missions.steps.mission-result', ['run' => $run])
            ->set('scores.Speaking.before', 2)->set('scores.Speaking.after', 4)
            ->set('scores.Writing.before', 3)->set('scores.Writing.after', 3)
            ->set('reflection.became_easier', 'Talking about my routine.')
            ->set('reflection.still_difficult', 'Past tense.')
            ->call('getResult')
            ->assertSee('2 → 4 (+2)')
            ->assertSee('3 → 3 (0)');
    }

    public function test_read_only_mode_loads_the_saved_decision_without_calling_gemini(): void
    {
        $run = $this->makeRun();

        SelfAssessment::create(['mission_run_id' => $run->id, 'skill' => 'Speaking', 'before' => 2, 'after' => 4]);
        Reflection::create(['mission_run_id' => $run->id, 'answers' => ['became_easier' => 'Talking about my routine.']]);
        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'mission_result',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode(['status' => 'complete', 'reason' => 'Saved reason.']),
        ]);

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldNotReceive('chat'));

        Livewire::test('missions.steps.mission-result', ['run' => $run, 'readOnly' => true])
            ->assertSet('status', 'complete')
            ->assertSet('reason', 'Saved reason.')
            ->assertDontSee('Finish Mission');
    }

    public function test_reflection_inputs_carry_a_draft_key_scoped_to_the_run(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.mission-result', ['run' => $run])
            ->assertSeeHtml("eos-draft:{$run->id}:mission_result:reflection.became_easier");
    }

    public function test_finishing_the_mission_dispatches_a_clear_draft_event(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode([
                'status' => 'complete',
                'reason' => 'You clearly improved and met the mission outcome.',
            ]));
        });

        $component = Livewire::test('missions.steps.mission-result', ['run' => $run])
            ->set('scores.Speaking.before', 2)->set('scores.Speaking.after', 4)
            ->set('scores.Writing.before', 3)->set('scores.Writing.after', 4)
            ->set('reflection.became_easier', 'Talking about my routine.')
            ->set('reflection.still_difficult', 'Past tense.')
            ->call('getResult');

        $component->call('finish')
            ->assertDispatched('clear-draft', prefix: "eos-draft:{$run->id}:mission_result:");
    }
}
