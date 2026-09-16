<?php

namespace Tests\Feature;

use App\Livewire\Concerns\TracksCheckAttempts;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S2 of the "growth without discouragement" epic. The acceptance
 * criterion is one-directional: struggling may only ever make the app
 * easier. These tests exist as much to pin down what must NOT change as
 * what does.
 */
class AdaptiveReliefWhenStrugglingTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(): MissionRun
    {
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'Test Mission',
            'module' => 'Me',
            'outcome' => 'Outcome.',
            'phases' => [],
        ]);

        return MissionRun::findOrStart(User::factory()->create(), $mission);
    }

    /** A bare host for the trait, so this tests the trait itself rather than one step. */
    private function componentFor(MissionRun $run): object
    {
        return new class($run)
        {
            use TracksCheckAttempts {
                trackCheckAttempt as public;
            }

            public function __construct(public MissionRun $run) {}
        };
    }

    public function test_a_run_is_not_struggling_until_enough_major_mistakes(): void
    {
        $run = $this->makeRun();

        for ($i = 0; $i < MissionRun::STRUGGLE_SIGNAL_THRESHOLD - 1; $i++) {
            $run->recordStruggleSignal();
        }

        $this->assertFalse($run->fresh()->isStruggling());

        $run->recordStruggleSignal();
        $this->assertTrue($run->fresh()->isStruggling());
    }

    public function test_the_reveal_is_offered_after_three_tries_normally(): void
    {
        $component = $this->componentFor($this->makeRun());

        $component->trackCheckAttempt('field', 'minor');
        $component->trackCheckAttempt('field', 'minor');
        $this->assertArrayNotHasKey('field', $component->offerReveal);

        $component->trackCheckAttempt('field', 'minor');
        $this->assertTrue($component->offerReveal['field']);
    }

    public function test_the_reveal_is_offered_a_try_sooner_once_the_learner_is_struggling(): void
    {
        $run = $this->makeRun();
        $run->forceFill(['struggle_signal_count' => MissionRun::STRUGGLE_SIGNAL_THRESHOLD])->save();

        $component = $this->componentFor($run);

        $component->trackCheckAttempt('field', 'minor');
        $this->assertArrayNotHasKey('field', $component->offerReveal);

        $component->trackCheckAttempt('field', 'minor');
        $this->assertTrue($component->offerReveal['field'], 'a struggling learner should be offered help on attempt 2');
    }

    public function test_the_heads_up_notice_moves_with_the_threshold(): void
    {
        $run = $this->makeRun();
        $component = $this->componentFor($run);

        $component->trackCheckAttempt('field', 'minor');
        $this->assertFalse($component->isAlmostRevealing('field'));
        $component->trackCheckAttempt('field', 'minor');
        $this->assertTrue($component->isAlmostRevealing('field'));

        $run->forceFill(['struggle_signal_count' => MissionRun::STRUGGLE_SIGNAL_THRESHOLD])->save();
        $struggling = $this->componentFor($run->fresh());
        $struggling->trackCheckAttempt('field', 'minor');
        $this->assertTrue($struggling->isAlmostRevealing('field'), 'the warning should arrive on attempt 1 when the offer comes on 2');
    }

    public function test_struggling_only_ever_lowers_the_number_of_tries_required(): void
    {
        $run = $this->makeRun();
        $easy = $this->componentFor($run)->revealThreshold();

        $run->forceFill(['struggle_signal_count' => 99])->save();
        $hard = $this->componentFor($run->fresh())->revealThreshold();

        $this->assertLessThan($easy, $hard);
    }

    public function test_a_clean_answer_clears_the_attempt_count_however_much_they_struggled(): void
    {
        $run = $this->makeRun();
        $run->forceFill(['struggle_signal_count' => MissionRun::STRUGGLE_SIGNAL_THRESHOLD])->save();
        $component = $this->componentFor($run);

        $component->trackCheckAttempt('field', 'major');
        $component->trackCheckAttempt('field', 'major');
        $this->assertTrue($component->offerReveal['field']);

        $component->trackCheckAttempt('field', 'none');
        $this->assertArrayNotHasKey('field', $component->offerReveal);
        $this->assertArrayNotHasKey('field', $component->checkAttempts);
    }

    public function test_the_ai_tone_softens_without_ever_naming_the_struggle(): void
    {
        $run = $this->makeRun();
        $run->forceFill(['struggle_signal_count' => MissionRun::STRUGGLE_SIGNAL_THRESHOLD])->save();

        $guidance = $run->fresh()->aiToneGuidance();

        $this->assertStringContainsString('ONE short', $guidance);
        $this->assertStringContainsString('simpler words', $guidance);
        // The AI must not turn the relief into a remark about the learner.
        $this->assertStringContainsString('Do not mention that they are struggling', $guidance);
    }

    public function test_struggling_does_not_change_what_the_mission_requires(): void
    {
        $mission = Mission::create([
            'code' => 'M02',
            'title' => 'Test Mission',
            'module' => 'Me',
            'outcome' => 'Outcome.',
            'phases' => [[
                'title' => 'Day 1',
                'steps' => [
                    ['key' => 'vocabulary_builder', 'title' => 'Vocab', 'content' => []],
                    ['key' => 'writing', 'title' => 'Writing', 'content' => []],
                ],
            ]],
        ]);
        $run = MissionRun::findOrStart(User::factory()->create(), $mission);

        $before = [$run->currentStepKey(), $run->progressPercent()];

        $run->forceFill(['struggle_signal_count' => 99])->save();

        // Evidence Before Progress (EOS-003 §7) still decides everything:
        // the same steps are still required, in the same order.
        $this->assertSame($before, [$run->fresh()->currentStepKey(), $run->fresh()->progressPercent()]);
    }
}
