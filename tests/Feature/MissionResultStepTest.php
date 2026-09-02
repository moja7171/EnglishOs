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
                            'label' => 'Mission Result',
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

        $this->actingAs($learner);

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

    public function test_reaching_a_7_day_streak_shows_the_milestone_celebration(): void
    {
        $run = $this->makeRun();

        for ($i = 1; $i < 7; $i++) {
            $evidence = Evidence::create([
                'mission_run_id' => $run->id,
                'phase' => 'mission_brief',
                'type' => Evidence::TYPE_SCORE,
                'content_ref' => '3',
            ]);
            $evidence->forceFill(['created_at' => now()->subDays($i)])->saveQuietly();
        }
        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'mission_brief',
            'type' => Evidence::TYPE_SCORE,
            'content_ref' => '3',
        ]); // today, completes the 7-day streak

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
            ->assertSee('7-day streak!')
            ->assertSee('A full week of practice');

        $this->assertSame(7, $run->learner->fresh()->celebrated_streak_milestone);
    }

    public function test_the_milestone_celebration_is_never_shown_in_read_only_mode(): void
    {
        $run = $this->makeRun();

        for ($i = 0; $i < 7; $i++) {
            $evidence = Evidence::create([
                'mission_run_id' => $run->id,
                'phase' => 'mission_brief',
                'type' => Evidence::TYPE_SCORE,
                'content_ref' => '3',
            ]);
            $evidence->forceFill(['created_at' => now()->subDays($i)])->saveQuietly();
        }

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'mission_result',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode(['status' => 'complete', 'reason' => 'Nice work.']),
        ]);

        Livewire::test('missions.steps.mission-result', ['run' => $run, 'readOnly' => true])
            ->assertDontSee('day streak!');

        $this->assertSame(0, $run->learner->fresh()->celebrated_streak_milestone);
    }

    public function test_a_non_complete_status_offers_to_review_the_flagged_step(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn(json_encode([
            'status' => 'needs_review',
            'reason' => 'Your grammar needs a bit more work.',
            'weak_step' => 'mission_result',
        ])));

        Livewire::test('missions.steps.mission-result', ['run' => $run])
            ->set('scores.Speaking.before', 2)->set('scores.Speaking.after', 4)
            ->set('scores.Writing.before', 3)->set('scores.Writing.after', 4)
            ->set('reflection.became_easier', 'x')
            ->set('reflection.still_difficult', 'y')
            ->call('getResult')
            ->assertSet('weakStep', 'mission_result')
            ->assertSeeHtml(route('missions.show', [$run->mission, 'mission_result']))
            ->assertSee('Review Mission Result');
    }

    public function test_a_hallucinated_step_key_is_never_trusted(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn(json_encode([
            'status' => 'needs_review',
            'reason' => 'Needs work.',
            'weak_step' => 'not_a_real_step',
        ])));

        Livewire::test('missions.steps.mission-result', ['run' => $run])
            ->set('scores.Speaking.before', 2)->set('scores.Speaking.after', 4)
            ->set('scores.Writing.before', 3)->set('scores.Writing.after', 4)
            ->set('reflection.became_easier', 'x')
            ->set('reflection.still_difficult', 'y')
            ->call('getResult')
            ->assertSet('weakStep', null)
            ->assertDontSee('Review Mission Result');
    }

    public function test_no_review_link_when_status_is_complete_even_with_a_weak_step(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn(json_encode([
            'status' => 'complete',
            'reason' => 'Nice work.',
            'weak_step' => 'mission_result',
        ])));

        Livewire::test('missions.steps.mission-result', ['run' => $run])
            ->set('scores.Speaking.before', 2)->set('scores.Speaking.after', 4)
            ->set('scores.Writing.before', 3)->set('scores.Writing.after', 4)
            ->set('reflection.became_easier', 'x')
            ->set('reflection.still_difficult', 'y')
            ->call('getResult')
            ->assertDontSee('Review Mission Result');
    }

    public function test_a_complete_status_offers_to_share_with_a_mutual_friend(): void
    {
        $run = $this->makeRun();
        $friend = User::factory()->create(['name' => 'Priya']);
        $run->learner->follow($friend);
        $friend->follow($run->learner);

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
            ->assertSee('Share this with a friend')
            ->assertSee('Priya');
    }

    public function test_no_share_prompt_when_status_is_not_complete(): void
    {
        $run = $this->makeRun();
        $friend = User::factory()->create();
        $run->learner->follow($friend);
        $friend->follow($run->learner);

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn(json_encode([
            'status' => 'needs_review',
            'reason' => 'Needs a bit more.',
        ])));

        Livewire::test('missions.steps.mission-result', ['run' => $run])
            ->set('scores.Speaking.before', 2)->set('scores.Speaking.after', 4)
            ->set('scores.Writing.before', 3)->set('scores.Writing.after', 4)
            ->set('reflection.became_easier', 'x')
            ->set('reflection.still_difficult', 'y')
            ->call('getResult')
            ->assertDontSee('Share this with a friend');
    }

    /**
     * Mirrors the real seeded shape (see MissionSeeder) — each reflection
     * question authored as {label, type}, not a plain string — used only
     * by the tests below that exercise the actual pick-from-options UI.
     */
    private function makeRunWithSelectableReflection(): MissionRun
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
                            'label' => 'Mission Result',
                            'skills' => ['Speaking', 'Writing'],
                            'reflection_questions' => [
                                'became_easier' => ['label' => 'What became easier?', 'type' => 'skills'],
                                'expression_to_keep' => ['label' => 'One expression I want to keep using', 'type' => 'vocabulary'],
                                'grammar_to_review' => ['label' => 'One grammar point I need to review', 'type' => 'errors'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->actingAs($learner);

        return MissionRun::findOrStart($learner, $mission);
    }

    public function test_a_skills_type_reflection_question_offers_the_skill_chips(): void
    {
        $run = $this->makeRunWithSelectableReflection();

        Livewire::test('missions.steps.mission-result', ['run' => $run])
            ->assertSee('What became easier?')
            ->assertSee('Speaking')
            ->assertSee('Writing');
    }

    public function test_selecting_a_chip_sets_the_reflection_answer(): void
    {
        $run = $this->makeRunWithSelectableReflection();

        Livewire::test('missions.steps.mission-result', ['run' => $run])
            ->call('selectReflectionOption', 'became_easier', 'Speaking')
            ->assertSet('reflection.became_easier', 'Speaking');
    }

    public function test_a_vocabulary_type_reflection_offers_the_learners_own_words(): void
    {
        $run = $this->makeRunWithSelectableReflection();

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'vocabulary_builder',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode(['selected_words' => ['wake up', 'commute']]),
        ]);

        Livewire::test('missions.steps.mission-result', ['run' => $run])
            ->assertSee('One expression I want to keep using')
            ->assertSee('wake up')
            ->assertSee('commute');
    }

    public function test_an_errors_type_reflection_offers_this_runs_corrections(): void
    {
        $run = $this->makeRunWithSelectableReflection();

        ErrorLogItem::create([
            'mission_run_id' => $run->id,
            'error' => 'She go home.',
            'correction' => 'She goes home.',
        ]);

        Livewire::test('missions.steps.mission-result', ['run' => $run])
            ->assertSee('One grammar point I need to review')
            ->assertSee('She goes home.');
    }

    public function test_a_reflection_question_with_no_available_options_is_skipped(): void
    {
        $run = $this->makeRunWithSelectableReflection(); // no vocabulary_builder Evidence, no ErrorLogItem

        Livewire::test('missions.steps.mission-result', ['run' => $run])
            ->assertSee('What became easier?') // skills always available
            ->assertDontSee('One expression I want to keep using')
            ->assertDontSee('One grammar point I need to review');
    }

    public function test_a_selected_reflection_option_persists_through_finish(): void
    {
        $run = $this->makeRunWithSelectableReflection();

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn(json_encode([
            'status' => 'complete',
            'reason' => 'Nice work.',
        ])));

        $component = Livewire::test('missions.steps.mission-result', ['run' => $run])
            ->set('scores.Speaking.before', 2)->set('scores.Speaking.after', 4)
            ->set('scores.Writing.before', 3)->set('scores.Writing.after', 4)
            ->call('selectReflectionOption', 'became_easier', 'Speaking')
            ->call('getResult');

        $component->call('finish');

        $reflection = Reflection::first();
        $this->assertSame('Speaking', $reflection->answers['became_easier']);
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

    public function test_read_only_mode_reloads_the_saved_weak_step(): void
    {
        $run = $this->makeRun();

        SelfAssessment::create(['mission_run_id' => $run->id, 'skill' => 'Speaking', 'before' => 2, 'after' => 4]);
        Reflection::create(['mission_run_id' => $run->id, 'answers' => ['became_easier' => 'x']]);
        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'mission_result',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode(['status' => 'needs_review', 'reason' => 'Saved reason.', 'weak_step' => 'mission_result']),
        ]);

        Livewire::test('missions.steps.mission-result', ['run' => $run, 'readOnly' => true])
            ->assertSet('weakStep', 'mission_result')
            ->assertSee('Review Mission Result');
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
