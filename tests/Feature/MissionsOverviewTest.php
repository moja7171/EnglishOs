<?php

namespace Tests\Feature;

use App\Models\DayActivity;
use App\Models\Evidence;
use App\Models\GrammarPoint;
use App\Models\ListeningLog;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\PiChatFeedback;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MissionsOverviewTest extends TestCase
{
    use RefreshDatabase;

    private function makeMission(string $code = 'M01'): Mission
    {
        return Mission::create([
            'code' => $code,
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

    public function test_the_freeze_banner_shows_right_after_a_freeze_saved_yesterday(): void
    {
        $learner = User::factory()->create(['streak_ring_since' => now()->subDays(5)->toDateString()]);

        foreach (['test:1', 'test:2'] as $activity) {
            DayActivity::factory()->create(['learner_id' => $learner->id, 'on_date' => now()->subDays(2)->toDateString(), 'activity' => $activity]);
        }

        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertSee('A freeze saved your streak yesterday.');
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

    public function test_no_comeback_banner_while_the_streak_is_still_alive(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission());

        $this->recordEvidenceOn($run, now()->toDateString());

        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertDontSee('Fresh start');
    }

    public function test_the_streak_flame_is_greyed_out_until_today_is_logged(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission());
        $this->recordEvidenceOn($run, now()->subDay()->toDateString());

        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertSeeHtml('title="1-day streak"')
            ->assertSeeHtml('opacity-50 grayscale')
            ->assertDontSee('Practice today to keep your');
    }

    public function test_the_streak_flame_lights_up_once_today_keeps_the_streak(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission());
        $this->recordEvidenceOn($run, now()->subDay()->toDateString());
        $this->recordEvidenceOn($run, now()->toDateString());
        DayActivity::factory()->create(['learner_id' => $learner->id]); // a 2nd activity keeps today

        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertSeeHtml('title="2-day streak"')
            ->assertDontSeeHtml('opacity-50 grayscale');
    }

    public function test_no_streak_in_the_header_with_no_streak_to_show(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertDontSeeHtml('-day streak"');
    }

    public function test_a_due_grammar_point_counts_toward_the_daily_review_nudge(): void
    {
        $learner = User::factory()->create();
        GrammarPoint::create([
            'learner_id' => $learner->id,
            'mission_code' => 'M01',
            'focus' => 'Present Simple + Adverbs of Frequency',
            'example_sentence' => 'I usually wake up at 7.',
            'rule_reminder' => 'The adverb goes before the main verb.',
            'next_review_at' => now()->subMinute(),
        ]);
        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertSee('Daily Review · 1 item');
    }

    public function test_the_header_shows_mission_1_of_24_for_a_new_learner(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertSee('Mission 1 of 24');
    }

    public function test_the_header_shows_the_mission_the_learner_is_in_and_its_day(): void
    {
        $learner = User::factory()->create();
        MissionRun::findOrStart($learner, $this->makeMission())->update(['status' => MissionRun::STATUS_COMPLETE, 'completed_at' => now()]);
        MissionRun::findOrStart($learner, $this->makeSecondMission());
        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertSee('M02 · My Neighborhood')
            ->assertSee('Mission 2 of 24')
            ->assertDontSee('Day 2 of 96');
    }

    private function makeThreeDayMission(): Mission
    {
        return Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [
                ['phase' => 'foundation', 'label' => 'Day 1', 'steps' => [['key' => 'mission_brief', 'label' => 'Brief']]],
                ['phase' => 'build', 'label' => 'Build', 'steps' => [['key' => 'vocabulary', 'label' => 'Words']]],
                ['phase' => 'perform', 'label' => 'Perform', 'steps' => [['key' => 'speaking', 'label' => 'Talk']]],
            ],
        ]);
    }

    private function startOnDayTwo(User $learner): MissionRun
    {
        $run = MissionRun::findOrStart($learner, $this->makeThreeDayMission());
        $evidence = Evidence::create(['mission_run_id' => $run->id, 'phase' => 'mission_brief', 'type' => Evidence::TYPE_SCORE, 'content_ref' => '3']);
        $evidence->forceFill(['created_at' => now()->subDays(2)])->saveQuietly();

        return $run;
    }

    public function test_a_finished_day_behind_the_learner_is_a_whole_ring_without_listening_or_talk_ticks(): void
    {
        $learner = User::factory()->create();
        $this->startOnDayTwo($learner);
        $this->actingAs($learner);

        $days = Livewire::test('missions.overview')->instance()->missionDays;

        $this->assertSame([true, true, true], [$days[0]['lesson'], $days[0]['listened'], $days[0]['talked']]);
        $this->assertSame([false, false, false], [$days[1]['lesson'], $days[1]['listened'], $days[1]['talked']]);
    }

    public function test_daily_talk_done_on_an_earlier_day_leaves_no_minutes_for_it(): void
    {
        $learner = User::factory()->create();
        $this->startOnDayTwo($learner);
        $this->actingAs($learner);

        $before = Livewire::test('missions.overview')->instance();
        $this->assertFalse($before->piPractice['practicedToday']);

        PiChatFeedback::factory()->for($learner, 'learner')->create([
            'chat' => '',
            'mission_code' => 'M01',
            'day_number' => $before->piPractice['dayNumber'],
            'feedback' => ['practice_done' => true],
        ]);

        $this->assertTrue(Livewire::test('missions.overview')->instance()->piPractice['practicedToday']);
    }

    public function test_the_banner_rings_pick_the_day_shown_under_the_banner(): void
    {
        $learner = User::factory()->create();
        $this->startOnDayTwo($learner);
        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertSeeHtml('data-mission-banner')
            ->assertSeeHtml('aria-label="Day 1, done"')
            ->assertSeeHtml('aria-label="Day 2, today"')
            ->assertSeeHtml('aria-label="Day 3, not reached yet"')
            ->assertSeeHtml("\$dispatch('select-day', { day: 1 })")
            ->assertSeeHtml("\$dispatch('select-day', { day: 3 })");
    }

    public function test_the_missions_days_are_under_the_banner_on_home_without_a_second_banner(): void
    {
        $learner = User::factory()->create();
        $this->startOnDayTwo($learner);
        $this->actingAs($learner);

        $this->get(route('home'))
            ->assertOk()
            ->assertSeeLivewire('missions.runner')
            ->assertSee('aria-label="Day 2 steps"', false)
            ->assertSee('aria-label="Day 2 Lesson', false)
            ->assertSee('x-on:select-day.window', false);
    }

    public function test_the_banner_title_opens_the_missions_page_on_today(): void
    {
        $learner = User::factory()->create();
        $run = $this->startOnDayTwo($learner);
        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertSeeHtml('href="'.route('missions.show', [$run->mission, 'overview', 'day' => 2]).'"')
            ->assertSeeHtml('href="'.route('progress.index').'"');
    }

    public function test_an_old_day_link_on_home_just_shows_today(): void
    {
        $learner = User::factory()->create();
        $this->startOnDayTwo($learner);
        $this->actingAs($learner);

        Livewire::withQueryParams(['day' => 1])
            ->test('missions.overview')
            ->assertSeeHtml('aria-label="Day 2, today"')
            ->assertDontSee('Back to today');
    }

    public function test_between_missions_home_stays_on_the_finished_mission_with_the_next_one_as_a_strip(): void
    {
        $learner = User::factory()->create();
        MissionRun::findOrStart($learner, $this->makeMission())->update(['status' => MissionRun::STATUS_COMPLETE, 'completed_at' => now()->subDay()]);
        $next = $this->makeSecondMission();
        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertSeeHtml('data-mission-banner')
            ->assertSee('M01 · My Daily Life')
            ->assertSee('Mission done')
            ->assertSee('Next mission')
            ->assertSee('M02 · My Neighborhood')
            ->assertSeeHtml('href="'.route('missions.show', [$next, $next->stepKeys()[0]]).'"')
            ->assertSeeHtml('aria-label="Days of M01"')
            ->assertSeeHtml('aria-label="Day 1, done"')
            ->assertDontSee('Start a new mission');
    }

    public function test_the_finished_missions_last_day_is_the_one_unfolded_on_home(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission());
        $run->update(['status' => MissionRun::STATUS_COMPLETE, 'completed_at' => now()->subDay()]);
        $this->actingAs($learner);

        $component = Livewire::test('missions.runner', ['mission' => $run->mission, 'step' => 'overview', 'embedded' => true, 'startDay' => 1]);

        $this->assertSame(1, $component->instance()->openDayNumber);
    }

    public function test_a_mission_closed_for_review_says_so_instead_of_done(): void
    {
        $learner = User::factory()->create();
        MissionRun::findOrStart($learner, $this->makeMission())->update(['status' => MissionRun::STATUS_NEEDS_REVIEW, 'completed_at' => now()->subDay()]);
        $this->makeSecondMission();
        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertSee('Done · review later')
            ->assertDontSee('Mission done');
    }

    public function test_once_the_next_mission_is_started_home_becomes_that_mission(): void
    {
        $learner = User::factory()->create();
        MissionRun::findOrStart($learner, $this->makeMission())->update(['status' => MissionRun::STATUS_COMPLETE, 'completed_at' => now()->subDay()]);
        $next = $this->makeSecondMission();
        MissionRun::findOrStart($learner, $next);
        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertSee('M02 · My Neighborhood')
            ->assertDontSee('Next mission')
            ->assertDontSee('Mission done')
            ->assertDontSee('M01 · My Daily Life');
    }

    public function test_a_next_mission_not_built_yet_keeps_the_plain_headline(): void
    {
        $learner = User::factory()->create();
        MissionRun::findOrStart($learner, $this->makeMission())->update(['status' => MissionRun::STATUS_COMPLETE, 'completed_at' => now()->subDay()]);
        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertDontSeeHtml('data-mission-banner')
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

    public function test_a_mission_is_gated_until_its_predecessor_is_cleared(): void
    {
        $learner = User::factory()->create();
        $this->makeMission();
        $this->makeSecondMission();
        $this->actingAs($learner);

        $this->assertNotNull(MissionRun::gatingMission($learner, Mission::where('code', 'M02')->firstOrFail()));
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
            ->assertDontSee('Next: M02')
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

        $this->assertNull(MissionRun::gatingMission($learner, Mission::where('code', 'M02')->firstOrFail()));
    }

    public function test_a_mission_stays_gated_when_its_predecessor_needs_retry_evidence(): void
    {
        $learner = User::factory()->create();
        $first = $this->makeMission();
        $this->makeSecondMission();

        $run = MissionRun::findOrStart($learner, $first);
        $run->update(['status' => MissionRun::STATUS_RETRY_EVIDENCE, 'completed_at' => now()]);

        $this->actingAs($learner);

        $this->assertNotNull(MissionRun::gatingMission($learner, Mission::where('code', 'M02')->firstOrFail()));
    }

    public function test_progress_already_made_on_a_gated_mission_is_never_locked_out(): void
    {
        $learner = User::factory()->create();
        $this->makeMission();
        $second = $this->makeSecondMission();

        // The learner already has a run of their own for M02 — from
        // before this gate existed, or made while an admin bypass was on
        // — and must never be retroactively locked out of it.
        MissionRun::findOrStart($learner, $second);

        $this->actingAs($learner);

        $this->assertNull(MissionRun::gatingMission($learner, Mission::where('code', 'M02')->firstOrFail()));
    }

    public function test_only_one_suggestion_shows_below_today(): void
    {
        $this->actingAs(User::factory()->create(['pi_onboarded_at' => null]));

        Livewire::test('missions.overview')
            ->assertSee('Find your level')
            ->assertDontSee('How Daily talk works')
            ->assertDontSee('Practice with a friend');
    }

    public function test_with_the_days_below_home_has_no_extra_listening_row_but_the_days_listening_card(): void
    {
        $learner = User::factory()->create();
        MissionRun::findOrStart($learner, $this->makeMission());
        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertDontSee('Extra listening')
            ->assertDontSee('Describing Your Day: Mornings')
            ->assertSeeHtml('aria-label="Day 1 Listening"')
            ->assertDontSeeHtml('wire:click="markListened"');
    }

    public function test_the_banner_shows_the_days_time_and_the_next_card_is_the_highlighted_one(): void
    {
        $learner = User::factory()->create();
        MissionRun::findOrStart($learner, $this->makeMission());
        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertDontSee('Today · ')
            ->assertSeeHtml('aria-label="Days of M01"')
            ->assertSee('today')
            ->assertSee('min');
    }

    public function test_the_today_box_reflects_a_tick_made_today(): void
    {
        $learner = User::factory()->create();
        ListeningLog::factory()->for($learner, 'learner')->create(['listened_on' => now()->toDateString()]);
        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertSee('Extra listening')
            ->assertSeeHtml('aria-label="Extra listening"');
    }

    public function test_the_today_box_previews_day_1_picks_before_any_run_exists(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('missions.overview')->assertSee('Extra listening');
    }

    public function test_the_today_box_shows_the_next_missions_picks_when_a_checkpoint_is_offered(): void
    {
        $learner = User::factory()->create();

        foreach (range(1, 6) as $number) {
            $run = MissionRun::findOrStart($learner, $this->makeMission(sprintf('M%02d', $number)));
            $run->update([
                'status' => MissionRun::STATUS_COMPLETE,
                'started_at' => now()->subDays(7 - $number),
                'completed_at' => now()->subDays(7 - $number),
            ]);
        }

        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertSee('Your voice, M06 in')
            ->assertSee('Extra listening');
    }

    public function test_the_today_box_has_no_listening_once_all_missions_are_complete(): void
    {
        $learner = User::factory()->create();

        foreach (range(1, Mission::TOTAL_ROADMAP_MISSIONS) as $number) {
            $run = MissionRun::findOrStart($learner, $this->makeMission(sprintf('M%02d', $number)));
            $run->update(['status' => MissionRun::STATUS_COMPLETE, 'completed_at' => now()]);
        }

        $this->actingAs($learner);

        Livewire::test('missions.overview')->assertDontSee('Extra listening');
    }

    public function test_home_shows_where_friends_are_and_links_to_friends(): void
    {
        $learner = User::factory()->create();
        $friend = User::factory()->create(['name' => 'Ali']);
        $learner->follow($friend);
        $friend->acceptFollowRequest($learner);
        $friend->follow($learner);
        $learner->acceptFollowRequest($friend);
        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertSee("Ali hasn't started today")
            ->assertSeeHtml('href="'.route('friends.index').'"');
    }

    public function test_the_next_thing_today_is_one_big_card_with_one_start(): void
    {
        $learner = User::factory()->create();
        MissionRun::findOrStart($learner, $this->makeMission());
        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertSee('Start')
            ->assertDontSee('Fresh start')
            ->assertDontSee('more · Missions');
    }
}
