<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WritingStepTest extends TestCase
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
                            'key' => 'writing',
                            'title' => 'A typical day in my life',
                            'min_words' => 5,
                            'max_words' => 10,
                        ],
                        ['key' => 'ai_conversation_2'],
                    ],
                ],
            ],
        ]);

        return MissionRun::findOrStart($learner, $mission);
    }

    public function test_word_count_below_minimum_is_rejected(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.writing', ['run' => $run])
            ->set('text', 'Too short.')
            ->call('save')
            ->assertHasErrors(['text']);

        $this->assertDatabaseCount('evidences', 0);
    }

    public function test_reaching_the_minimum_saves_evidence_and_advances_the_run(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.writing', ['run' => $run])
            ->set('text', 'I usually wake up early and then have breakfast.')
            ->call('save')
            ->assertRedirect(route('missions.show', $run->mission));

        $this->assertDatabaseHas('evidences', [
            'mission_run_id' => $run->id,
            'phase' => 'writing',
            'content_ref' => 'I usually wake up early and then have breakfast.',
        ]);

        $this->assertSame('ai_conversation_2', $run->fresh()->currentStepKey());
    }

    public function test_word_count_property_ignores_extra_whitespace(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.writing', ['run' => $run])
            ->set('text', "  one   two\nthree  ")
            ->assertSet('wordCount', 3);
    }

    public function test_the_learners_own_selected_vocabulary_shows_as_suggestions(): void
    {
        $run = $this->makeRun();

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'vocabulary_builder',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode([
                'selected_words' => ['wake up', 'have a shower', 'do the housework'],
                'examples' => [],
            ]),
        ]);

        Livewire::test('missions.steps.writing', ['run' => $run])
            ->assertSee('wake up')
            ->assertSee('have a shower')
            ->assertSee('do the housework')
            ->assertSee('Words you picked');
    }
}
