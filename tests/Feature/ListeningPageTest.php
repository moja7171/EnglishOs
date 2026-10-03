<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\ListeningLog;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
     * A learner whose first day of M01 is done, so today is M01 · Day 2.
     */
    private function learnerOnDay2(): User
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission());

        foreach (['mission_brief', 'vocabulary_builder'] as $phase) {
            Evidence::create(['mission_run_id' => $run->id, 'phase' => $phase, 'type' => Evidence::TYPE_TEXT, 'content_ref' => 'x']);
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
            ->assertSee('Listen today')
            ->assertSee('M01 · My Daily Life · Day 1 of 4')
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
            ->assertSee('Day 1 of 4')
            ->assertSee('Back to today (M01 · Day 2)');
    }

    public function test_a_day_ahead_of_the_learner_is_sent_back_to_today(): void
    {
        $this->actingAs($this->learnerOnDay2());

        $this->get(route('listening.show', ['M01', 3]))->assertRedirect(route('listening.show'));
    }

    public function test_a_brand_new_learner_cannot_jump_to_a_later_mission(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('listening.show', ['M02', 1]))->assertRedirect(route('listening.show'));
    }

    public function test_unknown_missions_and_days_are_not_found(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/listening/M25/1')->assertNotFound();
        $this->get('/listening/M01/5')->assertNotFound();
    }

    public function test_the_path_marks_past_today_and_not_yet_reached_days(): void
    {
        $this->actingAs($this->learnerOnDay2());

        $this->get(route('listening.show'))
            ->assertSeeHtml('aria-label="M01 day 1"')
            ->assertSeeHtml('aria-label="M01 day 2, today"')
            ->assertSeeHtml('aria-label="M01 day 3, not reached yet"');
    }

    public function test_a_learner_who_finished_every_mission_can_open_any_day(): void
    {
        $this->actingAs($this->finishedLearner());

        $this->get(route('listening.show', ['M24', 4]))
            ->assertOk()
            ->assertSee('Debate & Discussion')
            ->assertDontSee('Back to today');
    }

    public function test_ticking_i_listened_files_the_tick_under_the_day_on_screen(): void
    {
        $learner = $this->learnerOnDay2();
        $this->actingAs($learner);

        Livewire::test('listening.index', ['requestedMission' => 'M01', 'requestedDay' => 1])
            ->call('markListened')
            ->assertSee('Listened today')
            ->assertDontSee('Counts toward your streak');

        $this->assertDatabaseHas('listening_logs', [
            'learner_id' => $learner->id,
            'listened_on' => now()->toDateString(),
            'mission_code' => 'M01',
            'day_number' => 1,
        ]);
    }

    public function test_a_learner_who_already_ticked_today_sees_the_done_state(): void
    {
        $learner = User::factory()->create();
        ListeningLog::factory()->for($learner, 'learner')->create(['listened_on' => now()->toDateString()]);
        $this->actingAs($learner);

        $this->get(route('listening.show'))
            ->assertSee('Listened today')
            ->assertDontSee('I listened');
    }

    public function test_the_day_on_screen_cannot_be_rewritten_from_the_browser(): void
    {
        $this->actingAs(User::factory()->create());

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test('listening.index')->set('day', 4);
    }
}
