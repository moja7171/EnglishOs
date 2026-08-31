<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ActiveRecallStepTest extends TestCase
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
                            'key' => 'active_recall',
                            'instruction' => 'Without looking at the previous pages.',
                            'sections' => [
                                ['key' => 'expressions', 'label' => '5 expressions I learned', 'count' => 5],
                                ['key' => 'listening_facts', 'label' => '3 things I learned from the listening', 'count' => 3],
                            ],
                        ],
                        ['key' => 'error_log'],
                    ],
                ],
            ],
        ]);

        return MissionRun::findOrStart($learner, $mission);
    }

    public function test_an_empty_section_is_rejected(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.active-recall', ['run' => $run])
            ->set('answers.expressions.0', 'get up')
            ->call('save')
            ->assertHasErrors(['answers']);

        $this->assertDatabaseCount('evidences', 0);
    }

    public function test_at_least_one_answer_per_section_saves_evidence_and_advances_the_run(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.active-recall', ['run' => $run])
            ->set('answers.expressions.0', 'get up')
            ->set('answers.expressions.1', 'sleep in')
            ->set('answers.listening_facts.0', 'They talked about morning routines.')
            ->call('save')
            ->assertRedirect(route('missions.show', $run->mission));

        $evidence = Evidence::where('phase', 'active_recall')->first();
        $content = json_decode($evidence->content_ref, true);

        $this->assertCount(2, $content['expressions']);
        $this->assertCount(1, $content['listening_facts']);

        $this->assertSame('error_log', $run->fresh()->currentStepKey());
    }

    public function test_the_expressions_section_is_sized_to_what_the_learner_actually_picked(): void
    {
        $run = $this->makeRun();

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'vocabulary_builder',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode([
                'selected_words' => ['wake up', 'get up', 'have a shower'], // 3, not the seeded 5
                'examples' => [],
            ]),
        ]);

        Livewire::test('missions.steps.active-recall', ['run' => $run])
            ->assertSee('you picked 3')
            ->assertSet('answers.expressions', ['', '', '']);
    }

    public function test_the_expressions_section_falls_back_to_the_seeded_default_without_a_selection(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.active-recall', ['run' => $run])
            ->assertSee('5 expressions I learned')
            ->assertSet('answers.expressions', ['', '', '', '', '']);
    }
}
