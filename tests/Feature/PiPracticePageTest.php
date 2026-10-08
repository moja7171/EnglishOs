<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\ListeningLog;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\PiPracticeLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The daily voice practice with Pi, outside the app (/pi) — the sibling of
 * the /listening page, see App\Services\PiPractice.
 */
class PiPracticePageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A mission with 4 days of one step each (the real ones have 4 phases).
     */
    private function makeMission(string $code = 'M01'): Mission
    {
        return Mission::create([
            'code' => $code,
            'title' => 'Mission '.$code,
            'module' => 'Me',
            'outcome' => 'An outcome.',
            'phases' => collect(['foundation', 'build', 'practice', 'challenge'])
                ->map(fn (string $phase) => [
                    'phase' => $phase,
                    'label' => ucfirst($phase),
                    'steps' => [['key' => "{$phase}_step", 'label' => ucfirst($phase).' step', 'duration_minutes' => 5]],
                ])
                ->all(),
        ]);
    }

    /**
     * @param  list<string>  $phases  the days to finish, in order
     */
    private function finishDays(MissionRun $run, array $phases, bool $yesterday = false): void
    {
        foreach ($phases as $phase) {
            $evidence = Evidence::create(['mission_run_id' => $run->id, 'phase' => "{$phase}_step", 'type' => Evidence::TYPE_TEXT, 'content_ref' => 'x']);

            if ($yesterday) {
                $evidence->forceFill(['created_at' => now()->subDay()])->save();
            }
        }
    }

    /**
     * A learner whose first day of M01 was finished today (the planner keeps
     * Today on that day until tomorrow) — or yesterday, so today is Day 2.
     */
    private function learnerWhoFinishedDay1(bool $yesterday = false): User
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission());
        $this->finishDays($run, ['foundation'], $yesterday);

        return $learner;
    }

    private function finishedLearner(): User
    {
        $learner = User::factory()->create();

        foreach (range(1, Mission::TOTAL_ROADMAP_MISSIONS) as $number) {
            $run = MissionRun::findOrStart($learner, $this->makeMission(sprintf('M%02d', $number)));
            $run->update(['status' => MissionRun::STATUS_COMPLETE, 'completed_at' => now()]);
        }

        return $learner;
    }

    public function test_visitors_who_are_not_logged_in_are_sent_to_login(): void
    {
        $this->get(route('pi.practice'))->assertRedirect(route('login'));
    }

    public function test_a_new_learner_sees_the_pronunciation_practice_for_day_1(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('pi.practice'))
            ->assertSee('Voice practice today')
            ->assertSee('M01 · My Daily Life · Day 1 of 4')
            ->assertSee('Pronunciation Coach')
            ->assertSee('Like always, let');
    }

    public function test_the_page_links_to_both_voice_apps_in_a_new_tab(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('pi.practice'))
            ->assertSeeHtml('href="https://pi.ai"')
            ->assertSeeHtml('href="https://gemini.google.com/app"')
            ->assertSeeHtml('target="_blank"')
            ->assertSeeHtml('rel="noopener noreferrer"');
    }

    public function test_each_day_of_the_mission_has_its_own_practice(): void
    {
        $this->actingAs($this->finishedLearner());

        $this->get(route('pi.practice', ['M01', 2]))->assertSee('Teacher chat');
        $this->get(route('pi.practice', ['M01', 3]))->assertSee('Language Partner chat');
        $this->get(route('pi.practice', ['M01', 4]))
            ->assertSee('Retell &amp; Fix', false)
            ->assertSee('retell game');
    }

    public function test_a_day_finished_earlier_today_stays_the_target_until_the_learner_practices(): void
    {
        $this->actingAs($this->learnerWhoFinishedDay1());

        $this->get(route('pi.practice'))
            ->assertSee('M01 · My Daily Life · Day 1 of 4')
            ->assertDontSee('Back to today');
    }

    public function test_once_the_learner_has_practiced_the_target_stays_on_the_day_todays_box_shows(): void
    {
        $learner = $this->learnerWhoFinishedDay1();
        PiPracticeLog::factory()->for($learner, 'learner')->create(['practiced_on' => now()->toDateString()]);
        $this->actingAs($learner);

        $this->get(route('pi.practice'))->assertSee('M01 · My Daily Life · Day 1 of 4');
    }

    public function test_a_day_finished_before_today_does_not_carry_over(): void
    {
        $this->actingAs($this->learnerWhoFinishedDay1(yesterday: true));

        $this->get(route('pi.practice'))->assertSee('M01 · My Daily Life · Day 2 of 4');
    }

    public function test_a_mission_finished_today_keeps_its_retell_day_as_the_target(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission());
        $this->finishDays($run, ['foundation', 'build', 'practice', 'challenge']);
        $run->update(['status' => MissionRun::STATUS_COMPLETE, 'completed_at' => now()]);
        $this->actingAs($learner);

        $this->get(route('pi.practice'))
            ->assertSee('M01 · My Daily Life · Day 4 of 4')
            ->assertSee('Retell &amp; Fix', false);
    }

    public function test_a_learner_can_reopen_an_earlier_day_and_is_offered_the_way_back_to_today(): void
    {
        $learner = $this->learnerWhoFinishedDay1(yesterday: true);
        $this->actingAs($learner);

        $this->get(route('pi.practice', ['M01', 1]))
            ->assertSee('Voice practice')
            ->assertSee('Day 1 of 4')
            ->assertSee('Back to today (M01 · Day 2)');
    }

    public function test_a_day_ahead_of_the_learner_is_sent_back_to_today(): void
    {
        $this->actingAs($this->learnerWhoFinishedDay1());

        $this->get(route('pi.practice', ['M01', 3]))->assertRedirect(route('pi.practice'));
    }

    public function test_a_brand_new_learner_cannot_jump_to_a_later_mission(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('pi.practice', ['M02', 1]))->assertRedirect(route('pi.practice'));
    }

    public function test_unknown_missions_and_days_are_not_found(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/pi/M25/1')->assertNotFound();
        $this->get('/pi/M01/5')->assertNotFound();
    }

    public function test_the_day_switcher_marks_reached_and_not_yet_reached_days(): void
    {
        $this->actingAs($this->learnerWhoFinishedDay1(yesterday: true));

        $this->get(route('pi.practice'))
            ->assertSeeHtml('aria-label="Days of M01"')
            ->assertSeeHtml('href="'.route('pi.practice', ['M01', 1]).'"')
            ->assertSee('Day 2 · Grammar · today')
            ->assertSeeHtml('aria-label="Day 3, not reached yet"');
    }

    public function test_ticking_i_practiced_files_the_tick_under_the_day_on_screen(): void
    {
        $learner = $this->learnerWhoFinishedDay1();
        $this->actingAs($learner);

        Livewire::test('pi-practice.index', ['requestedMission' => 'M01', 'requestedDay' => 1])
            ->call('markPracticed')
            ->assertSee('Practiced today')
            ->assertDontSee('Counts toward your streak');

        $this->assertDatabaseHas('pi_practice_logs', [
            'learner_id' => $learner->id,
            'practiced_on' => now()->toDateString(),
            'mission_code' => 'M01',
            'day_number' => 1,
        ]);
    }

    public function test_ticking_i_practiced_twice_on_the_same_day_keeps_a_single_row(): void
    {
        $learner = $this->learnerWhoFinishedDay1();
        $this->actingAs($learner);

        Livewire::test('pi-practice.index')
            ->call('markPracticed')
            ->call('markPracticed');

        $this->assertSame(1, $learner->piPracticeLogs()->count());
    }

    public function test_the_practice_tick_and_the_listening_tick_are_independent(): void
    {
        $learner = User::factory()->create();
        ListeningLog::factory()->for($learner, 'learner')->create(['listened_on' => now()->toDateString()]);
        $this->actingAs($learner);

        $this->get(route('pi.practice'))->assertSee('I practiced');

        Livewire::test('pi-practice.index')->call('markPracticed');

        $this->assertTrue($learner->hasListenedToday());
        $this->assertTrue($learner->hasPracticedWithPiToday());
        $this->assertSame(1, $learner->listeningLogs()->count());
    }

    public function test_the_practice_tick_alone_keeps_the_streak_alive(): void
    {
        $learner = User::factory()->create();
        PiPracticeLog::factory()->for($learner, 'learner')->create(['practiced_on' => now()->toDateString()]);

        $this->assertSame(1, $learner->currentStreak());
    }

    public function test_the_day_on_screen_cannot_be_rewritten_from_the_browser(): void
    {
        $this->actingAs(User::factory()->create());

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test('pi-practice.index')->set('day', 4);
    }

    public function test_the_today_box_shows_the_voice_practice_row_below_the_days_steps(): void
    {
        $this->actingAs($this->learnerWhoFinishedDay1(yesterday: true));

        $this->get(route('home'))
            ->assertSeeInOrder(["Today's steps", 'Voice practice · Grammar'])
            ->assertSee('Last 10 minutes of your day');
    }

    public function test_the_today_box_row_shows_the_done_state_after_practicing(): void
    {
        $learner = $this->learnerWhoFinishedDay1(yesterday: true);
        PiPracticeLog::factory()->for($learner, 'learner')->create(['practiced_on' => now()->toDateString()]);
        $this->actingAs($learner);

        $this->get(route('home'))
            ->assertSee('Voice practice · Grammar')
            ->assertSee('Practiced today')
            ->assertDontSee('Last 10 minutes of your day');
    }

    public function test_a_finished_programme_with_nothing_done_today_has_no_voice_practice_row(): void
    {
        $this->actingAs($this->finishedLearner());

        $this->get(route('home'))->assertDontSee('Voice practice ·');
    }

    public function test_a_mission_overview_links_to_its_voice_practice(): void
    {
        $learner = $this->learnerWhoFinishedDay1();
        $this->actingAs($learner);

        $this->get(route('missions.show', [Mission::first(), 'overview']))
            ->assertSee('Voice practice for this mission')
            ->assertSeeHtml('href="'.route('pi.practice', ['M01', 1]).'"');
    }
}
