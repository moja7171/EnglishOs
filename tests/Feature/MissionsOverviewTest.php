<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\GrammarPoint;
use App\Models\ListeningLog;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use App\Services\PexelsClient;
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
            ->assertSee('1 item ready for Daily Review');
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
        // before this gate existed, or made while an admin bypass was on
        // — and must never be retroactively locked out of it.
        MissionRun::findOrStart($learner, $second);

        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertDontSee('Finish M01 first to unlock this one.');
    }

    public function test_a_mission_card_shows_its_cached_cover_image(): void
    {
        $learner = User::factory()->create();
        $mission = $this->makeMission();
        MissionRun::findOrStart($learner, $mission);
        $this->actingAs($learner);

        // Reads back the exact asset Mission Brief itself would have
        // cached under "M01-brief" — never triggers a fresh Pexels fetch.
        $this->mock(PexelsClient::class, function ($mock) {
            $mock->shouldReceive('cachedImageUrl')
                ->with('M01-brief')
                ->once()
                ->andReturn('http://localhost/storage/vocabulary-images/m01-brief.jpg');
            // The other 23 roadmap slots are all unbuilt here, each
            // asking for their own "coming soon" placeholder image —
            // irrelevant to this test, just needs to not throw.
            $mock->shouldReceive('imageUrlFor')->andReturn(null);
        });

        Livewire::test('missions.overview')
            ->assertSeeHtml('http://localhost/storage/vocabulary-images/m01-brief.jpg');
    }

    public function test_the_today_box_lists_the_three_picks_of_the_current_day_easiest_first(): void
    {
        $learner = User::factory()->create();
        MissionRun::findOrStart($learner, $this->makeMission());
        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertSeeInOrder(['Routines', 'Describing Your Day: Mornings', "Why you need a good night's sleep"]);
    }

    public function test_each_pick_in_the_today_box_opens_its_source_in_a_new_tab(): void
    {
        $learner = User::factory()->create();
        MissionRun::findOrStart($learner, $this->makeMission());
        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertSeeHtml('href="https://www.bbc.co.uk/learningenglish/english/features/real-easy-english/240607"')
            ->assertSeeHtml('target="_blank"');
    }

    public function test_the_today_box_makes_clear_listening_is_not_one_of_the_steps(): void
    {
        $learner = User::factory()->create();
        MissionRun::findOrStart($learner, $this->makeMission());
        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertSee('any time, any order')
            ->assertSee('Not a step, so no need to finish it first')
            ->assertSeeInOrder(['Listen', "Today's steps", 'in order']);
    }

    public function test_the_today_box_has_no_i_listened_button_only_a_link_to_the_page(): void
    {
        $learner = User::factory()->create();
        MissionRun::findOrStart($learner, $this->makeMission());
        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertDontSee('I listened')
            ->assertSee('Details & tick')
            ->assertSeeHtml('href="'.route('listening.show').'"');
    }

    public function test_the_today_box_reflects_a_tick_made_today(): void
    {
        $learner = User::factory()->create();
        ListeningLog::factory()->for($learner, 'learner')->create(['listened_on' => now()->toDateString()]);
        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertSee('Listened today')
            ->assertDontSee('Details & tick');
    }

    public function test_a_tick_from_yesterday_does_not_mark_today_as_listened(): void
    {
        $learner = User::factory()->create();
        ListeningLog::factory()->for($learner, 'learner')->create(['listened_on' => now()->subDay()->toDateString()]);
        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertSee('Details & tick')
            ->assertDontSee('Listened today');
    }

    public function test_another_learners_tick_does_not_mark_today_as_listened(): void
    {
        ListeningLog::factory()->create(['listened_on' => now()->toDateString()]);
        $this->actingAs(User::factory()->create());

        Livewire::test('missions.overview')
            ->assertSee('Details & tick')
            ->assertDontSee('Listened today');
    }

    public function test_the_today_box_previews_day_1_picks_before_any_run_exists(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('missions.overview')->assertSee('Routines');
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
            ->assertSee('Stress-free family meals');
    }

    public function test_the_today_box_has_no_listening_once_all_missions_are_complete(): void
    {
        $learner = User::factory()->create();

        foreach (range(1, Mission::TOTAL_ROADMAP_MISSIONS) as $number) {
            $run = MissionRun::findOrStart($learner, $this->makeMission(sprintf('M%02d', $number)));
            $run->update(['status' => MissionRun::STATUS_COMPLETE, 'completed_at' => now()]);
        }

        $this->actingAs($learner);

        Livewire::test('missions.overview')->assertDontSee('any time, any order');
    }

    public function test_no_thumbnail_without_a_cached_cover_image(): void
    {
        $learner = User::factory()->create();
        $mission = $this->makeMission();
        MissionRun::findOrStart($learner, $mission);
        $this->actingAs($learner);

        $this->mock(PexelsClient::class, function ($mock) {
            $mock->shouldReceive('cachedImageUrl')->with('M01-brief')->once()->andReturn(null);
            // The other 23 roadmap slots are all unbuilt here — their own
            // "coming soon" placeholder image must also stay absent, or
            // this test's <img>-free assertion would be testing the
            // wrong thing.
            $mock->shouldReceive('imageUrlFor')->andReturn(null);
        });

        Livewire::test('missions.overview')->assertDontSeeHtml('<img');
    }
}
