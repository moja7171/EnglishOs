<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MissionsOverviewTest extends TestCase
{
    use RefreshDatabase;

    private function makeMission(): Mission
    {
        return Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [
                ['phase' => 'foundation', 'label' => 'Day 1', 'steps' => [['key' => 'mission_brief']]],
            ],
        ]);
    }

    private function recordEvidenceOn(MissionRun $run, string $date): void
    {
        $evidence = Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'mission_brief',
            'type' => Evidence::TYPE_SCORE,
            'content_ref' => '3',
        ]);

        $evidence->forceFill(['created_at' => now()->parse($date)->setTime(12, 0)])->saveQuietly();
    }

    public function test_the_grace_banner_shows_right_after_a_forgiven_gap(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission());

        $this->recordEvidenceOn($run, now()->toDateString());
        $this->recordEvidenceOn($run, now()->subDays(2)->toDateString());

        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertSee('You missed a day, but your streak is safe!');
    }

    public function test_no_grace_banner_with_a_clean_streak(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission());

        $this->recordEvidenceOn($run, now()->toDateString());
        $this->recordEvidenceOn($run, now()->subDay()->toDateString());

        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertDontSee('You missed a day, but your streak is safe!');
    }

    public function test_the_comeback_banner_shows_after_a_streak_actually_breaks(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission());

        $this->recordEvidenceOn($run, now()->subDays(10)->toDateString());
        $this->recordEvidenceOn($run, now()->subDays(9)->toDateString());
        $this->recordEvidenceOn($run, now()->subDays(8)->toDateString());

        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertSee('Fresh start')
            ->assertSee('3 days');
    }

    public function test_no_comeback_banner_for_a_learner_with_no_history(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertDontSee('Fresh start');
    }

    public function test_no_comeback_banner_while_the_streak_is_still_alive(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission());

        $this->recordEvidenceOn($run, now()->toDateString());

        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertDontSee('Fresh start');
    }

    public function test_the_today_reminder_shows_with_an_active_streak_not_yet_logged_today(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission());
        $this->recordEvidenceOn($run, now()->subDay()->toDateString());

        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertSee('Practice today to keep your');
    }

    public function test_no_today_reminder_once_todays_activity_is_already_logged(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission());
        $this->recordEvidenceOn($run, now()->subDay()->toDateString());
        $this->recordEvidenceOn($run, now()->toDateString());

        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertDontSee('Practice today to keep your');
    }

    public function test_no_today_reminder_with_no_streak_to_protect(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertDontSee('Practice today to keep your');
    }

    public function test_the_course_progress_bar_shows_mission_1_of_24_for_a_new_learner(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertSee('Mission 1 of 24');
    }

    public function test_the_course_progress_bar_reflects_the_furthest_mission_reached(): void
    {
        $learner = User::factory()->create();
        MissionRun::findOrStart($learner, $this->makeMission());
        MissionRun::findOrStart($learner, $this->makeSecondMission());
        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertSee('Mission 2 of 24');
    }

    private function makeSecondMission(): Mission
    {
        return Mission::create([
            'code' => 'M02',
            'title' => 'My Neighborhood',
            'module' => 'Me',
            'outcome' => 'I can describe where I live.',
            'phases' => [
                ['phase' => 'foundation', 'label' => 'Day 1', 'steps' => [['key' => 'mission_brief']]],
            ],
        ]);
    }

    /**
     * These 4 gating tests only exercise real behavior once
     * MissionRun::TESTING_UNLOCK_ALL_STEPS is false — see
     * project_testing_unlock_all_steps memory. Expected to fail alongside
     * the other 4 known TESTING_UNLOCK_ALL_STEPS-caused failures until
     * that flag is reverted.
     */
    public function test_a_mission_is_gated_until_its_predecessor_is_cleared(): void
    {
        $learner = User::factory()->create();
        $this->makeMission();
        $this->makeSecondMission();
        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertSee('Finish M01 first to unlock this one.');
    }

    public function test_a_mission_unlocks_once_its_predecessor_is_complete(): void
    {
        $learner = User::factory()->create();
        $first = $this->makeMission();
        $second = $this->makeSecondMission();

        $run = MissionRun::findOrStart($learner, $first);
        $run->update(['status' => MissionRun::STATUS_COMPLETE, 'completed_at' => now()]);

        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertDontSee('Finish M01 first to unlock this one.')
            ->assertSeeHtml(route('missions.show', $second));
    }

    public function test_a_mission_unlocks_when_its_predecessor_is_needs_review_not_just_complete(): void
    {
        $learner = User::factory()->create();
        $first = $this->makeMission();
        $this->makeSecondMission();

        $run = MissionRun::findOrStart($learner, $first);
        $run->update(['status' => MissionRun::STATUS_NEEDS_REVIEW, 'completed_at' => now()]);

        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertDontSee('Finish M01 first to unlock this one.');
    }

    public function test_a_mission_stays_gated_when_its_predecessor_needs_retry_evidence(): void
    {
        $learner = User::factory()->create();
        $first = $this->makeMission();
        $this->makeSecondMission();

        $run = MissionRun::findOrStart($learner, $first);
        $run->update(['status' => MissionRun::STATUS_RETRY_EVIDENCE, 'completed_at' => now()]);

        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertSee('Finish M01 first to unlock this one.');
    }

    public function test_progress_already_made_on_a_gated_mission_is_never_locked_out(): void
    {
        $learner = User::factory()->create();
        $this->makeMission();
        $second = $this->makeSecondMission();

        // The learner already has a run of their own for M02 — from
        // before this gate existed, or made while TESTING_UNLOCK_ALL_STEPS
        // bypassed it — and must never be retroactively locked out of it.
        MissionRun::findOrStart($learner, $second);

        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertDontSee('Finish M01 first to unlock this one.');
    }
}
