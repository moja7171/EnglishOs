<?php

namespace Tests\Feature;

use App\Models\AudioListen;
use App\Models\Evidence;
use App\Models\ListeningLog;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

class ListeningPageTest extends TestCase
{
    use RefreshDatabase;

    private function makeMission(string $code = 'M01'): Mission
    {
        return Mission::create([
            'code' => $code,
            'title' => 'Mission '.$code,
            'module' => 'Me',
            'outcome' => 'An outcome.',
            'phases' => [
                ['phase' => 'foundation', 'label' => 'Foundation', 'steps' => [
                    ['key' => 'mission_brief', 'label' => 'Mission Brief', 'duration_minutes' => 5],
                    ['key' => 'vocabulary_builder', 'label' => 'Vocabulary Builder', 'duration_minutes' => 15],
                ]],
                ['phase' => 'build', 'label' => 'Build', 'steps' => [
                    ['key' => 'grammar_in_context', 'label' => 'Grammar in Context', 'duration_minutes' => 12],
                ]],
            ],
        ]);
    }

    /**
     * A learner who finished the first day of M01 yesterday, so today is
     * M01 · Day 2.
     */
    private function learnerOnDay2(): User
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission());

        foreach (['mission_brief', 'vocabulary_builder'] as $phase) {
            $evidence = Evidence::create(['mission_run_id' => $run->id, 'phase' => $phase, 'type' => Evidence::TYPE_TEXT, 'content_ref' => 'x']);
            $evidence->forceFill(['created_at' => now()->subDay()])->save();
        }

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
        $this->get(route('listening.show'))->assertRedirect(route('login'));
    }

    public function test_a_new_learner_sees_todays_three_picks_easiest_first(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('listening.show'))
            ->assertSee('Listening')
            ->assertSee('Pick one — one is enough.')
            ->assertSee('M01 · My Daily Life · Day 1')
            ->assertSeeInOrder(['Routines', 'Describing Your Day: Mornings', "Why you need a good night's sleep"])
            ->assertSeeInOrder(['Lighter', 'Medium', 'Challenging']);
    }

    public function test_each_pick_opens_its_source_in_a_new_tab(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('listening.show'))
            ->assertSeeHtml('href="https://www.bbc.co.uk/learningenglish/english/features/real-easy-english/240607"')
            ->assertSeeHtml('target="_blank"')
            ->assertSeeHtml('rel="noopener noreferrer"');
    }

    public function test_a_pick_with_a_separate_transcript_page_links_to_it(): void
    {
        $this->actingAs($this->finishedLearner());

        $this->get(route('listening.show', ['M16', 1]))
            ->assertSeeHtml('href="https://www.ted.com/talks/suresh_subudhi_why_overtourism_could_ruin_your_next_vacation/transcript"');
    }

    public function test_a_learner_can_reopen_an_earlier_day_and_is_offered_the_way_back_to_today(): void
    {
        $this->actingAs($this->learnerOnDay2());

        $this->get(route('listening.show', ['M01', 1]))
            ->assertSee('Listening')
            ->assertSee('M01 · My Daily Life · Day 1')
            ->assertSee('Back to today (M01 · Day 2)');
    }

    public function test_a_day_ahead_of_the_learner_opens_as_a_preview_without_a_tick(): void
    {
        $this->actingAs($this->learnerOnDay2());

        $this->get(route('listening.show', ['M01', 3]))
            ->assertOk()
            ->assertSee('Preview only')
            ->assertSee('Pick one')
            ->assertDontSeeHtml('$wire.heardPick(');
    }

    public function test_a_real_listen_on_a_day_ahead_is_not_filed(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);

        Livewire::test('listening.index', ['requestedMission' => 'M02', 'requestedDay' => 1])
            ->call('heardPick', 0)
            ->assertDontSee('Listened');

        $this->assertDatabaseMissing('audio_listens', ['learner_id' => $learner->id]);
    }

    public function test_unknown_missions_and_days_are_not_found(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/listening/M25/1')->assertNotFound();
        $this->get('/listening/M01/5')->assertNotFound();
    }

    /**
     * M01 finished, M02 started: today is M02 · Day 1.
     */
    public function test_an_earlier_day_has_no_daily_tick_and_does_not_borrow_todays(): void
    {
        $learner = $this->learnerOnDay2();
        ListeningLog::factory()->for($learner, 'learner')->create(['day_number' => 2]);
        $this->actingAs($learner);

        $response = $this->get(route('listening.show', ['M01', 1]));

        $response
            ->assertDontSee('Listened today')
            ->assertDontSeeHtml('wire:click="markListened"');
        $this->assertStringNotContainsString(', done', Str::between($response->getContent(), 'aria-label="Days of M01"', 'Day 2 · today'));
    }

    public function test_an_earlier_day_with_a_pick_heard_shows_as_listened(): void
    {
        $learner = $this->learnerOnDay2();
        AudioListen::factory()->pick(1)->create(['learner_id' => $learner->id]);
        $this->actingAs($learner);

        $this->get(route('listening.show', ['M01', 1]))
            ->assertDontSee('Listened today')
            ->assertSeeInOrder(['Day 1', '<span class="sr-only">, done</span>', 'Day 2 · today'], false);
    }

    public function test_a_learner_who_finished_every_mission_can_open_any_day(): void
    {
        $this->actingAs($this->finishedLearner());

        $this->get(route('listening.show', ['M24', 4]))
            ->assertOk()
            ->assertSee('Debate & Discussion')
            ->assertDontSee('Back to today');
    }

    public function test_the_page_offers_a_switcher_for_the_four_days_of_the_mission_on_screen(): void
    {
        $this->actingAs($this->learnerOnDay2());

        $this->get(route('listening.show', ['M01', 1]))
            ->assertSeeHtml('aria-label="Days of M01"')
            ->assertSeeHtml('href="'.route('listening.show', ['M01', 2]).'"')
            ->assertSee('Day 2 · today')
            ->assertSeeHtml('aria-label="Day 3, not reached yet"');
    }

    public function test_a_learner_who_already_ticked_today_sees_the_done_state(): void
    {
        $learner = User::factory()->create();
        ListeningLog::factory()->for($learner, 'learner')->create(['listened_on' => now()->toDateString()]);
        $this->actingAs($learner);

        $this->get(route('listening.show'))
            ->assertSee('Listened')
            ->assertDontSee('I listened');
    }

    public function test_the_day_on_screen_cannot_be_rewritten_from_the_browser(): void
    {
        $this->actingAs(User::factory()->create());

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test('listening.index')->set('day', 4);
    }

    public function test_a_real_listen_files_the_pick_and_ticks_the_day_on_screen(): void
    {
        $learner = $this->learnerOnDay2();
        $this->actingAs($learner);

        Livewire::test('listening.index', ['requestedMission' => 'M01', 'requestedDay' => 1])
            ->call('heardPick', 1)
            ->assertSee('Heard')
            ->assertSee('Listened');

        $this->assertDatabaseHas('audio_listens', [
            'learner_id' => $learner->id,
            'mission_code' => 'M01',
            'source' => AudioListen::SOURCE_LISTENING_PICK,
            'day_number' => 1,
            'pick_key' => AudioListen::pickKey(1, 1),
            'self_reported' => false,
        ]);
        $this->assertDatabaseHas('listening_logs', [
            'learner_id' => $learner->id,
            'listened_on' => now()->toDateString(),
            'mission_code' => 'M01',
            'day_number' => 1,
        ]);
    }

    public function test_the_same_listen_reported_twice_within_a_minute_counts_once(): void
    {
        $learner = $this->learnerOnDay2();
        $this->actingAs($learner);

        Livewire::test('listening.index')
            ->call('heardPick', 0)
            ->call('heardPick', 0);

        $this->assertSame(1, $learner->audioListens()->count());
        $this->assertSame(1, $learner->listeningLogs()->count());
    }

    public function test_a_level_that_is_not_one_of_the_days_picks_files_nothing(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);

        Livewire::test('listening.index')->call('heardPick', 7);

        $this->assertSame(0, $learner->audioListens()->count());
        $this->assertFalse($learner->hasListenedToday());
    }

    public function test_only_the_heard_pick_is_marked_heard(): void
    {
        $learner = User::factory()->create();
        AudioListen::factory()->pick(1, 1)->create(['learner_id' => $learner->id]);
        $this->actingAs($learner);

        $html = $this->get(route('listening.show'))->getContent();

        $this->assertSame(1, substr_count($html, '> Heard</span>'));
    }

    public function test_there_is_no_manual_tick(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('listening.show'))
            ->assertDontSee('I listened')
            ->assertDontSeeHtml('reportListen');
    }

    public function test_the_banner_leads_back_to_the_day_on_the_missions_page(): void
    {
        $this->actingAs($this->learnerOnDay2());

        $this->get(route('listening.show'))
            ->assertSeeHtml('href="'.route('missions.show', ['M01', 'overview', 'day' => 2]).'"');
    }
}
