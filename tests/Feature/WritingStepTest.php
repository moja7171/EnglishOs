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

    public function test_reaching_the_minimum_saves_evidence_and_shows_the_recap(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode([
                'strength' => 'Clear, simple sentences.',
                'expression' => 'have breakfast',
                'correction' => 'Try "wake up early" instead of "wake early".',
            ]));
        });

        Livewire::test('missions.steps.writing', ['run' => $run])
            ->set('text', 'I usually wake up early and then have breakfast.')
            ->call('save')
            ->assertSet('completed', true)
            ->assertSee('Clear, simple sentences.')
            ->call('proceed')
            ->assertRedirect(route('missions.show', $run->mission));

        $this->assertDatabaseHas('evidences', [
            'mission_run_id' => $run->id,
            'phase' => 'writing',
            'content_ref' => 'I usually wake up early and then have breakfast.',
        ]);
        $this->assertDatabaseHas('evidences', [
            'mission_run_id' => $run->id,
            'phase' => 'writing_feedback',
        ]);

        // The 'writing' step key is satisfied by the essay's own Evidence —
        // the feedback is stored under a different phase and must never
        // become a second required step to advance past.
        $this->assertSame('ai_conversation_2', $run->fresh()->currentStepKey());
    }

    public function test_a_failed_feedback_call_never_blocks_saving_the_essay(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andThrow(new \RuntimeException('AI unavailable'));
        });

        Livewire::test('missions.steps.writing', ['run' => $run])
            ->set('text', 'I usually wake up early and then have breakfast.')
            ->call('save')
            ->assertSet('completed', true)
            ->assertSet('feedback', null);

        $this->assertDatabaseHas('evidences', ['mission_run_id' => $run->id, 'phase' => 'writing']);
        $this->assertDatabaseMissing('evidences', ['mission_run_id' => $run->id, 'phase' => 'writing_feedback']);
    }

    public function test_reviewing_a_completed_step_reloads_the_saved_feedback(): void
    {
        $run = $this->makeRun();

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'writing',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => 'I usually wake up early and then have breakfast.',
        ]);
        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'writing_feedback',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode([
                'strength' => 'Clear, simple sentences.',
                'expression' => 'have breakfast',
                'correction' => 'Try "wake up early" instead of "wake early".',
            ]),
        ]);

        Livewire::test('missions.steps.writing', ['run' => $run, 'readOnly' => true])
            ->assertSee('Clear, simple sentences.')
            ->assertDontSee('Continue');
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

    public function test_the_text_field_carries_a_draft_key_scoped_to_the_run(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.writing', ['run' => $run])
            ->assertSeeHtml("eos-draft:{$run->id}:writing:text");
    }

    public function test_a_successful_save_dispatches_a_clear_draft_event(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.writing', ['run' => $run])
            ->set('text', 'I usually wake up early and then have breakfast.')
            ->call('save')
            ->assertDispatched('clear-draft', prefix: "eos-draft:{$run->id}:writing:");
    }
}
