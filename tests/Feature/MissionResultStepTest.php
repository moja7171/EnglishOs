<?php

namespace Tests\Feature;

use App\Models\ErrorLogItem;
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

    public function test_the_result_shows_which_target_vocabulary_words_were_actually_used(): void
    {
        $run = $this->makeRun();

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'vocabulary_builder',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode(['selected_words' => ['wake up', 'commute', 'have a shower']]),
        ]);
        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'writing',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => 'Every morning I wake up early and commute to work by bus.',
        ]);

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn(json_encode([
            'status' => 'complete',
            'reason' => 'Nice work.',
        ])));

        Livewire::test('missions.steps.mission-result', ['run' => $run])
            ->set('scores.Speaking.before', 2)->set('scores.Speaking.after', 4)
            ->set('scores.Writing.before', 3)->set('scores.Writing.after', 4)
            ->set('reflection.became_easier', 'x')
            ->set('reflection.still_difficult', 'y')
            ->call('getResult')
            ->assertSee('2 of 3 words used')
            ->assertSee('wake up')
            ->assertSee('commute')
            ->assertSee('have a shower');
    }

    public function test_no_vocabulary_section_renders_without_a_selection(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn(json_encode([
            'status' => 'complete',
            'reason' => 'Nice work.',
        ])));

        Livewire::test('missions.steps.mission-result', ['run' => $run])
            ->set('scores.Speaking.before', 2)->set('scores.Speaking.after', 4)
            ->set('scores.Writing.before', 3)->set('scores.Writing.after', 4)
            ->set('reflection.became_easier', 'x')
            ->set('reflection.still_difficult', 'y')
            ->call('getResult')
            ->assertDontSee('words used');
    }

    public function test_the_result_surfaces_a_recurring_error_pattern(): void
    {
        $run = $this->makeRun();

        // Recurring means "shown up across 2+ mission runs" — seed one
        // earlier, separate mission for the same learner.
        $earlierMission = Mission::create([
            'code' => 'M-EARLIER',
            'title' => 'Earlier Mission',
            'module' => 'Me',
            'outcome' => 'Outcome.',
            'phases' => [],
        ]);
        $earlierRun = MissionRun::findOrStart($run->learner, $earlierMission);
        ErrorLogItem::create([
            'mission_run_id' => $earlierRun->id,
            'error' => 'He walk fast.',
            'correction' => 'He walks fast.',
            'category' => 'third-person-s',
        ]);
        ErrorLogItem::create([
            'mission_run_id' => $run->id,
            'error' => 'She go home.',
            'correction' => 'She goes home.',
            'category' => 'third-person-s',
        ]);

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn(json_encode([
            'status' => 'complete',
            'reason' => 'Nice work.',
        ])));

        Livewire::test('missions.steps.mission-result', ['run' => $run])
            ->set('scores.Speaking.before', 2)->set('scores.Speaking.after', 4)
            ->set('scores.Writing.before', 3)->set('scores.Writing.after', 4)
            ->set('reflection.became_easier', 'x')
            ->set('reflection.still_difficult', 'y')
            ->call('getResult')
            ->assertSee('A pattern to keep an eye on')
            ->assertSee('She go home.')
            ->assertSee('She goes home.');
    }

    public function test_no_recurring_error_section_without_a_repeated_pattern(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn(json_encode([
            'status' => 'complete',
            'reason' => 'Nice work.',
        ])));

        Livewire::test('missions.steps.mission-result', ['run' => $run])
            ->set('scores.Speaking.before', 2)->set('scores.Speaking.after', 4)
            ->set('scores.Writing.before', 3)->set('scores.Writing.after', 4)
            ->set('reflection.became_easier', 'x')
            ->set('reflection.still_difficult', 'y')
            ->call('getResult')
            ->assertDontSee('A pattern to keep an eye on');
    }

    public function test_the_result_shows_the_learners_current_streak(): void
    {
        $run = $this->makeRun();

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'mission_brief',
            'type' => Evidence::TYPE_SCORE,
            'content_ref' => '3',
        ]);

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn(json_encode([
            'status' => 'complete',
            'reason' => 'Nice work.',
        ])));

        Livewire::test('missions.steps.mission-result', ['run' => $run])
            ->set('scores.Speaking.before', 2)->set('scores.Speaking.after', 4)
            ->set('scores.Writing.before', 3)->set('scores.Writing.after', 4)
            ->set('reflection.became_easier', 'x')
            ->set('reflection.still_difficult', 'y')
            ->call('getResult')
            ->assertSee("You're on a 1-day streak — nice start!");
    }

    public function test_no_streak_banner_without_any_active_days(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn(json_encode([
            'status' => 'complete',
            'reason' => 'Nice work.',
        ])));

        Livewire::test('missions.steps.mission-result', ['run' => $run])
            ->set('scores.Speaking.before', 2)->set('scores.Speaking.after', 4)
            ->set('scores.Writing.before', 3)->set('scores.Writing.after', 4)
            ->set('reflection.became_easier', 'x')
            ->set('reflection.still_difficult', 'y')
            ->call('getResult')
            ->assertDontSee('day streak');
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
