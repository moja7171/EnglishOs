<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use Database\Seeders\MissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Steps (and whole days) the learner hasn't reached yet are viewable, but
 * only as a read-only preview: they see the real screen, can't do it, and
 * can't skip ahead. The lock that matters is server-side (PreviewsStep +
 * the call hook in AppServiceProvider), not the disabled buttons.
 */
class MissionStepPreviewTest extends TestCase
{
    use RefreshDatabase;

    private function freshM01Run(?User $learner = null): MissionRun
    {
        $this->seed(MissionSeeder::class);

        $learner ??= User::factory()->create();
        $this->actingAs($learner);

        return MissionRun::findOrStart($learner, Mission::where('code', 'M01')->firstOrFail());
    }

    public function test_a_step_ahead_of_the_learner_opens_as_a_preview_without_moving_progress(): void
    {
        $run = $this->freshM01Run();

        Livewire::test('missions.runner', ['mission' => $run->mission, 'step' => 'grammar_in_context'])
            ->assertSet('activeStepKey', 'grammar_in_context')
            ->assertSet('isPreviewing', true)
            ->assertSet('isReviewing', false)
            ->assertSee('Preview only')
            ->assertSee('Go to where I am');

        $this->assertSame('mission_brief', $run->fresh()->currentStepKey());
        $this->assertSame(0, Evidence::count());
    }

    public function test_the_current_and_completed_steps_are_not_previews(): void
    {
        $run = $this->freshM01Run();

        Evidence::create(['mission_run_id' => $run->id, 'phase' => 'mission_brief', 'type' => Evidence::TYPE_SCORE, 'content_ref' => '3']);

        Livewire::test('missions.runner', ['mission' => $run->mission, 'step' => 'mission_brief'])
            ->assertSet('isPreviewing', false)
            ->assertSet('isReviewing', true);

        Livewire::test('missions.runner', ['mission' => $run->mission, 'step' => 'vocabulary_builder_1'])
            ->assertSet('isPreviewing', false)
            ->assertDontSee('Preview only');
    }

    public function test_the_banner_points_back_to_the_step_the_learner_is_actually_on(): void
    {
        $run = $this->freshM01Run();

        Livewire::test('missions.runner', ['mission' => $run->mission, 'step' => 'grammar_in_context'])
            ->assertSet('currentPosition.dayNumber', 1)
            ->assertSet('currentPosition.stepKey', 'mission_brief')
            ->assertSeeHtml('href="'.route('missions.show', [$run->mission, 'mission_brief']).'"');
    }

    public function test_every_step_of_a_mission_renders_as_a_preview_for_a_brand_new_learner(): void
    {
        Http::fake();
        $run = $this->freshM01Run();

        foreach ($run->mission->stepKeys() as $key) {
            if ($key === 'mission_brief') {
                continue; // the live current step, not a preview
            }

            Livewire::test('missions.runner', ['mission' => $run->mission, 'step' => $key])
                ->assertSet('isPreviewing', true)
                ->assertSee('Preview only');
        }

        $this->assertSame(0, Evidence::count());
        Http::assertNothingSent();
    }

    public function test_a_previewed_step_never_claims_to_be_completed_or_offers_its_action(): void
    {
        $run = $this->freshM01Run();
        $mission = $run->mission;

        Livewire::test('missions.runner', ['mission' => $mission, 'step' => 'ai_conversation_1'])
            ->assertDontSee('Talk It Out complete');

        Livewire::test('missions.runner', ['mission' => $mission, 'step' => 'error_log'])
            ->assertDontSee('No recurring mistakes found')
            ->assertDontSee('Review my mistakes')
            ->assertSee('It fills in once you reach this step');

        Livewire::test('missions.runner', ['mission' => $mission, 'step' => 'mission_result'])
            ->assertDontSee('Get My Result');
    }

    public function test_listening_to_a_previewed_recording_does_not_count_as_a_listen(): void
    {
        $run = $this->freshM01Run();
        $mission = $run->mission;

        Livewire::test('missions.runner', ['mission' => $mission, 'step' => 'listening'])
            ->assertDontSeeLivewire('listen-counter');

        // Control: the same player does count once the learner is really there.
        foreach (['mission_brief', 'vocabulary_builder_1'] as $key) {
            Evidence::create(['mission_run_id' => $run->id, 'phase' => $key, 'type' => Evidence::TYPE_TEXT, 'content_ref' => 'done']);
        }

        Livewire::test('missions.runner', ['mission' => $mission, 'step' => 'listening'])
            ->assertSeeLivewire('listen-counter');

        Livewire::test('missions.runner', ['mission' => $mission, 'step' => 'daily_listen_2'])
            ->assertDontSeeLivewire('listen-counter');

        Livewire::test('missions.runner', ['mission' => $mission, 'step' => 'video_shadowing'])
            ->assertDontSeeLivewire('listen-counter');
    }

    public function test_previewing_walks_forward_through_the_day_and_back_to_the_overview(): void
    {
        $run = $this->freshM01Run();
        $daySteps = $run->dayProgress()[1]['stepKeys'];

        Livewire::test('missions.runner', ['mission' => $run->mission, 'step' => $daySteps[0]])
            ->assertSet('nextStepKey', $daySteps[1])
            ->assertSet('previousStepKey', null);

        Livewire::test('missions.runner', ['mission' => $run->mission, 'step' => end($daySteps)])
            ->assertSet('nextStepKey', null)
            ->assertSee('Back to all days');
    }

    public function test_the_overview_offers_every_later_day_as_a_preview_and_marks_where_you_are(): void
    {
        $run = $this->freshM01Run();

        $component = Livewire::test('missions.runner', ['mission' => $run->mission, 'step' => 'overview'])
            ->assertSee('You are here')
            ->assertSee('Preview');

        foreach ($run->dayProgress() as $day) {
            $component->assertSeeHtml('href="'.route('missions.show', [$run->mission, $day['stepKeys'][0]]).'"');
        }
    }

    public function test_ask_instructor_is_not_offered_while_previewing(): void
    {
        $run = $this->freshM01Run();

        Livewire::test('missions.runner', ['mission' => $run->mission, 'step' => 'grammar_in_context'])
            ->assertDontSeeLivewire('missions.ask-instructor');

        Livewire::test('missions.runner', ['mission' => $run->mission, 'step' => 'mission_brief'])
            ->assertSeeLivewire('missions.ask-instructor');
    }

    public function test_an_admin_still_does_every_step_live(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $run = $this->freshM01Run($admin);

        Livewire::test('missions.runner', ['mission' => $run->mission, 'step' => 'grammar_in_context'])
            ->assertSet('isPreviewing', false)
            ->assertDontSee('Preview only');
    }

    public function test_a_finished_mission_has_nothing_left_to_preview(): void
    {
        $run = $this->freshM01Run();

        foreach ($run->mission->stepKeys() as $key) {
            Evidence::create(['mission_run_id' => $run->id, 'phase' => $key, 'type' => Evidence::TYPE_TEXT, 'content_ref' => 'done']);
        }

        Livewire::test('missions.runner', ['mission' => $run->mission, 'step' => 'listening'])
            ->assertSet('isPreviewing', false)
            ->assertSet('isReviewing', true);
    }

    public function test_a_previewed_step_refuses_every_action_and_records_nothing(): void
    {
        $run = $this->freshM01Run();

        foreach (['save', 'proceed', 'finish', 'generate'] as $action) {
            Livewire::test('missions.steps.mission-brief', ['run' => $run, 'readOnly' => true, 'preview' => true])
                ->call($action)
                ->assertForbidden();
        }

        $this->assertSame(0, Evidence::count());
    }

    public function test_a_learner_cannot_switch_preview_off_from_the_browser(): void
    {
        $run = $this->freshM01Run();

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test('missions.steps.mission-brief', ['run' => $run, 'readOnly' => true, 'preview' => true])
            ->set('preview', false);
    }

    public function test_a_step_that_is_not_previewed_still_runs_its_actions(): void
    {
        $run = $this->freshM01Run();

        Livewire::test('missions.steps.mission-brief', ['run' => $run])
            ->set('score', 3)
            ->call('save');

        $this->assertSame(1, Evidence::count());
    }
}
