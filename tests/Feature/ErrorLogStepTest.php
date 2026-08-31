<?php

namespace Tests\Feature;

use App\Models\ErrorLogItem;
use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use App\Services\GeminiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ErrorLogStepTest extends TestCase
{
    use RefreshDatabase;

    private function makeRunWithEvidence(): MissionRun
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [
                ['phase' => 'mission', 'steps' => [['key' => 'error_log'], ['key' => 'mission_result']]],
            ],
        ]);
        $run = MissionRun::findOrStart($learner, $mission);

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'ai_conversation_1',
            'type' => Evidence::TYPE_TRANSCRIPT,
            'content_ref' => json_encode([['question' => 'Q', 'answer' => 'She go to work.', 'followup' => 'F']]),
        ]);
        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'writing',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => 'I usually goes to work by bus.',
        ]);

        return $run;
    }

    public function test_mount_generates_errors_from_prior_evidence(): void
    {
        $run = $this->makeRunWithEvidence();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode([
                ['error' => 'She go to work.', 'correction' => 'She goes to work.'],
            ]));
        });

        Livewire::test('missions.steps.error-log', ['run' => $run])
            ->assertSet('mistakes', [['error' => 'She go to work.', 'correction' => 'She goes to work.']]);
    }

    public function test_new_example_required_for_every_error_before_saving(): void
    {
        $run = $this->makeRunWithEvidence();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode([
                ['error' => 'She go to work.', 'correction' => 'She goes to work.'],
                ['error' => 'I usually goes.', 'correction' => 'I usually go.'],
            ]));
        });

        Livewire::test('missions.steps.error-log', ['run' => $run])
            ->set('newExamples.0', 'She goes to work every day.')
            ->call('save')
            ->assertHasErrors(['newExamples']);

        $this->assertDatabaseCount('error_log_items', 0);
    }

    public function test_saving_persists_error_log_items_and_advances_the_run(): void
    {
        $run = $this->makeRunWithEvidence();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode([
                ['error' => 'She go to work.', 'correction' => 'She goes to work.'],
            ]));
        });

        Livewire::test('missions.steps.error-log', ['run' => $run])
            ->set('newExamples.0', 'She goes to work every day by bus.')
            ->call('save')
            ->assertRedirect(route('missions.show', $run->mission));

        $item = ErrorLogItem::first();
        $this->assertSame('She go to work.', $item->error);
        $this->assertSame('She goes to work every day by bus.', $item->new_example);

        $this->assertSame('mission_result', $run->fresh()->currentStepKey());
    }

    public function test_no_mistakes_found_lets_the_learner_continue_immediately(): void
    {
        $run = $this->makeRunWithEvidence();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('[]');
        });

        Livewire::test('missions.steps.error-log', ['run' => $run])
            ->assertSet('mistakes', [])
            ->call('save')
            ->assertRedirect(route('missions.show', $run->mission));

        $this->assertDatabaseCount('error_log_items', 0);
        $this->assertSame('mission_result', $run->fresh()->currentStepKey());
    }

    public function test_read_only_mode_loads_from_error_log_items_without_calling_gemini(): void
    {
        $run = $this->makeRunWithEvidence();

        ErrorLogItem::create([
            'mission_run_id' => $run->id,
            'error' => 'She go to work.',
            'correction' => 'She goes to work.',
            'new_example' => 'She goes to work every day.',
        ]);
        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'error_log',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode([['error' => 'She go to work.', 'correction' => 'She goes to work.']]),
        ]);

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldNotReceive('chat');
        });

        Livewire::test('missions.steps.error-log', ['run' => $run, 'readOnly' => true])
            ->assertSet('mistakes', [['error' => 'She go to work.', 'correction' => 'She goes to work.']])
            ->assertSet('newExamples', ['She goes to work every day.'])
            ->assertDontSee('Continue');
    }
}
