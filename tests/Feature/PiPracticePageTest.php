<?php

namespace Tests\Feature;

use App\Models\AiPractice;
use App\Models\AskAnswerItem;
use App\Models\Evidence;
use App\Models\ListeningLog;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\PiChatFeedback;
use App\Models\PiPracticeLog;
use App\Models\User;
use App\Services\GeminiClient;
use Database\Seeders\AskAnswerSeeder;
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
        $this->get(route('pi.practice'))->assertRedirect(route('welcome'));
    }

    public function test_a_new_learner_sees_the_about_you_chat_for_day_1(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('pi.practice'))
            ->assertSee('Daily talk')
            ->assertSee('M01 · My Daily Life · Day 1')
            ->assertSee('About You · 10 min')
            ->assertSee('Talk with Pi')
            ->assertSee('I did it')
            ->assertSee('What Pi will do')
            ->assertSee('like two friends');
    }

    public function test_the_full_guide_links_to_both_voice_apps_in_a_new_tab(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('pi.setup'))
            ->assertSeeHtml('href="https://pi.ai"')
            ->assertSeeHtml('href="https://gemini.google.com/app"')
            ->assertSeeHtml('target="_blank"')
            ->assertSeeHtml('rel="noopener noreferrer"');
    }

    public function test_pi_opens_in_its_android_app_and_the_prompt_can_be_copied_on_the_way(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('pi.practice'))
            ->assertSee('Copy & open Pi')
            ->assertSee('package=ai.inflection.pi')
            ->assertDontSee('S.browser_fallback_url=https%3A%2F%2Fpi.ai');
    }

    public function test_each_day_of_the_mission_has_its_own_practice(): void
    {
        $this->actingAs($this->finishedLearner());

        $this->get(route('pi.practice', ['M01', 2]))->assertSee('A Little Story');
        $this->get(route('pi.practice', ['M01', 3]))->assertSee('Your Opinion');
        $this->get(route('pi.practice', ['M01', 4]))->assertSee('Your Experiences');
    }

    public function test_a_day_finished_earlier_today_stays_the_target_until_the_learner_practices(): void
    {
        $this->actingAs($this->learnerWhoFinishedDay1());

        $this->get(route('pi.practice'))
            ->assertSee('M01 · My Daily Life · Day 1')
            ->assertDontSee('Back to today');
    }

    public function test_once_the_learner_has_practiced_the_target_stays_on_the_day_todays_box_shows(): void
    {
        $learner = $this->learnerWhoFinishedDay1();
        PiPracticeLog::factory()->for($learner, 'learner')->create(['practiced_on' => now()->toDateString()]);
        $this->actingAs($learner);

        $this->get(route('pi.practice'))->assertSee('M01 · My Daily Life · Day 1');
    }

    public function test_a_day_finished_before_today_does_not_carry_over(): void
    {
        $this->actingAs($this->learnerWhoFinishedDay1(yesterday: true));

        $this->get(route('pi.practice'))->assertSee('M01 · My Daily Life · Day 2');
    }

    public function test_a_mission_finished_today_keeps_its_last_day_as_the_target(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission());
        $this->finishDays($run, ['foundation', 'build', 'practice', 'challenge']);
        $run->update(['status' => MissionRun::STATUS_COMPLETE, 'completed_at' => now()]);
        $this->actingAs($learner);

        $this->get(route('pi.practice'))
            ->assertSee('M01 · My Daily Life · Day 4')
            ->assertSee('Your Experiences');
    }

    public function test_a_learner_can_reopen_an_earlier_day_and_is_offered_the_way_back_to_today(): void
    {
        $learner = $this->learnerWhoFinishedDay1(yesterday: true);
        $this->actingAs($learner);

        $this->get(route('pi.practice', ['M01', 1]))
            ->assertSee('Daily talk')
            ->assertSee('M01 · My Daily Life · Day 1')
            ->assertSee('Back to today (M01 · Day 2)');
    }

    public function test_a_day_ahead_of_the_learner_shows_its_plan_but_no_pi_or_tick_button(): void
    {
        $this->actingAs($this->learnerWhoFinishedDay1());

        $this->get(route('pi.practice', ['M01', 3]))
            ->assertOk()
            ->assertSee('Preview only')
            ->assertSee('What Pi will do')
            ->assertDontSee('Copy &amp; open Pi', false)
            ->assertDontSeeHtml('wire:click="markPracticed"');
    }

    public function test_a_day_ahead_cannot_be_ticked(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);

        Livewire::test('pi-practice.index', ['requestedMission' => 'M02', 'requestedDay' => 1])
            ->call('markPracticed');

        $this->assertFalse($learner->hasPracticedWithPiToday());
    }

    public function test_unknown_missions_and_days_are_not_found(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/pi/M25/1')->assertNotFound();
        $this->get('/pi/M01/5')->assertNotFound();
    }

    public function test_an_earlier_day_shows_its_own_plan_and_tick_not_todays(): void
    {
        $learner = $this->learnerWhoFinishedDay1(yesterday: true);
        PiPracticeLog::factory()->for($learner, 'learner')->create(['day_number' => 2]);
        $this->actingAs($learner);

        $this->get(route('pi.practice', ['M01', 1]))
            ->assertSee('About You')
            ->assertDontSeeHtml(' Practiced</span>');
    }

    public function test_an_earlier_day_done_before_shows_as_practiced_on_its_day_button(): void
    {
        $learner = $this->learnerWhoFinishedDay1(yesterday: true);
        PiChatFeedback::factory()->for($learner, 'learner')->create(['chat' => '', 'feedback' => ['practice_done' => true]]);
        $this->actingAs($learner);

        $this->get(route('pi.practice', ['M01', 1]))
            ->assertSeeHtml(' Practiced</span>')
            ->assertSeeInOrder(['title="About You"', '<span class="sr-only">, done</span>', 'Day 2 · today'], false);
    }

    public function test_the_day_switcher_marks_reached_and_not_yet_reached_days(): void
    {
        $this->actingAs($this->learnerWhoFinishedDay1(yesterday: true));

        $this->get(route('pi.practice'))
            ->assertSeeHtml('aria-label="Days of M01"')
            ->assertSeeHtml('href="'.route('pi.practice', ['M01', 1]).'"')
            ->assertSee('Day 2 · today')
            ->assertSeeHtml('aria-label="Day 3, not reached yet"');
    }

    public function test_i_did_it_ticks_the_practice_under_the_day_on_screen_with_no_ai(): void
    {
        $learner = $this->learnerWhoFinishedDay1();
        $this->actingAs($learner);
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldNotReceive('chat'));

        Livewire::test('pi-practice.index', ['requestedMission' => 'M01', 'requestedDay' => 1])
            ->assertSeeHtml('wire:click="markPracticed"')
            ->call('markPracticed')
            ->assertSeeHtml(' Practiced</span>')
            ->assertSee('Done for this day')
            ->assertDontSeeHtml('wire:click="markPracticed"');

        $this->assertDatabaseHas('pi_practice_logs', [
            'learner_id' => $learner->id,
            'practiced_on' => now()->toDateString(),
            'mission_code' => 'M01',
            'day_number' => 1,
        ]);
    }

    public function test_the_prompt_stays_reachable_after_the_practice_is_ticked(): void
    {
        $this->actingAs($this->learnerWhoFinishedDay1());

        Livewire::test('pi-practice.index', ['requestedMission' => 'M01', 'requestedDay' => 1])
            ->call('markPracticed')
            ->assertSee('See prompt')
            ->assertSee('Copy & open Pi');
    }

    public function test_the_page_has_no_paste_or_feedback_step(): void
    {
        $this->actingAs($this->learnerWhoFinishedDay1());

        $this->get(route('pi.practice'))
            ->assertDontSee('Paste')
            ->assertDontSee('Your feedback')
            ->assertDontSee('transcript');
    }

    public function test_two_mission_days_done_on_one_date_both_keep_their_tick(): void
    {
        $learner = $this->learnerWhoFinishedDay1(yesterday: true);
        $this->actingAs($learner);

        Livewire::test('pi-practice.index', ['requestedMission' => 'M01', 'requestedDay' => 2])->call('markPracticed');
        Livewire::test('pi-practice.index', ['requestedMission' => 'M01', 'requestedDay' => 1])->call('markPracticed');

        $this->assertSame(1, $learner->piPracticeLogs()->count());
        $this->assertSame([1, 2], $learner->piPracticedDaysOf('M01'));
    }

    public function test_an_old_days_saved_chat_still_counts_as_its_tick(): void
    {
        $learner = $this->learnerWhoFinishedDay1(yesterday: true);
        PiChatFeedback::factory()->for($learner, 'learner')->create(['feedback' => ['summary' => 'Done.', 'practice_done' => true]]);

        $this->assertSame([1], $learner->piPracticedDaysOf('M01'));
    }

    public function test_a_practice_ticked_twice_on_the_same_day_keeps_a_single_row(): void
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

        Livewire::test('pi-practice.index')->call('markPracticed');

        $this->assertTrue($learner->hasListenedToday());
        $this->assertTrue($learner->hasPracticedWithPiToday());
        $this->assertSame(1, $learner->listeningLogs()->count());
    }

    public function test_the_practice_tick_and_the_listening_tick_together_keep_the_streak(): void
    {
        $learner = User::factory()->create();

        $learner->recordPiPracticeToday('M01', 1);
        $this->assertSame(0, $learner->currentStreak());

        $learner->recordListeningToday('M01', 1);
        $this->assertSame(1, $learner->currentStreak());
    }

    public function test_the_day_on_screen_cannot_be_rewritten_from_the_browser(): void
    {
        $this->actingAs(User::factory()->create());

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test('pi-practice.index')->set('day', 4);
    }

    public function test_home_shows_the_days_daily_talk_card_below_the_steps(): void
    {
        $this->actingAs($this->learnerWhoFinishedDay1(yesterday: true));

        $this->get(route('home'))
            ->assertSeeInOrder(['Lesson', 'Build step', 'Daily talk'])
            ->assertSeeHtml('aria-label="Day 2 Daily talk"')
            ->assertSeeHtml('href="'.route('pi.practice', ['M01', 2]).'"');
    }

    public function test_the_daily_talk_card_on_home_cannot_be_ticked_by_hand(): void
    {
        $this->actingAs($this->learnerWhoFinishedDay1(yesterday: true));

        $this->get(route('home'))
            ->assertSeeHtml('aria-label="Day 2 Daily talk"')
            ->assertDontSeeHtml('wire:click="markPracticed"');
    }

    public function test_the_daily_talk_card_on_home_shows_the_done_state_after_practicing(): void
    {
        $learner = $this->learnerWhoFinishedDay1(yesterday: true);
        PiPracticeLog::factory()->for($learner, 'learner')->create([
            'practiced_on' => now()->toDateString(),
            'mission_code' => 'M01',
            'day_number' => 2,
        ]);
        $this->actingAs($learner);

        $this->get(route('home'))
            ->assertSeeHtml('aria-label="Day 2 Daily talk, done"')
            ->assertDontSeeHtml('wire:click="markPracticed"');
    }

    public function test_a_finished_programme_with_nothing_done_today_has_no_voice_practice_row(): void
    {
        $this->actingAs($this->finishedLearner());

        $this->get(route('home'))->assertDontSee('Daily talk ·');
    }

    public function test_a_mission_overview_links_to_its_voice_practice(): void
    {
        $learner = $this->learnerWhoFinishedDay1();
        $this->actingAs($learner);

        $this->get(route('missions.show', [Mission::first(), 'overview']))
            ->assertSeeHtml('aria-label="Day 1 Daily talk"')
            ->assertSeeHtml('href="'.route('pi.practice', ['M01', 1]).'"');
    }

    public function test_the_back_link_leads_to_the_day_on_the_missions_page(): void
    {
        $this->actingAs($this->learnerWhoFinishedDay1());

        $this->get(route('pi.practice'))
            ->assertSeeHtml('href="'.route('missions.show', ['M01', 'overview', 'day' => 1]).'"')
            ->assertSee('M01 · My Daily Life · Day 1');
    }

    public function test_the_back_link_of_a_mission_not_built_yet_is_the_missions_page(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('pi.practice'))
            ->assertSeeHtml('href="'.route('missions.index').'"');
    }

    public function test_a_day_with_ask_and_answer_cards_makes_it_the_daily_talk_and_pi_the_optional_extra(): void
    {
        $this->seed(AskAnswerSeeder::class);
        $learner = $this->learnerWhoFinishedDay1();
        $this->actingAs($learner);

        Livewire::test('pi-practice.index')
            ->assertSee('With Teddy')
            ->assertSee('Free talk with Pi (optional)');

        $this->get(route('missions.index'))->assertOk();
    }

    public function test_a_day_with_cards_never_calls_its_daily_talk_pi(): void
    {
        $this->seed(AskAnswerSeeder::class);
        $this->actingAs($this->learnerWhoFinishedDay1());

        Livewire::test('pi-practice.index')
            ->assertSee('Ask & Answer')
            ->assertSee('With Teddy or a friend')
            ->assertDontSee('Daily talk');
    }

    public function test_a_day_without_cards_keeps_the_daily_talk_name_and_pi(): void
    {
        AskAnswerItem::query()->delete();
        $this->actingAs($this->learnerWhoFinishedDay1());

        Livewire::test('pi-practice.index')
            ->assertSee('Daily talk')
            ->assertSee('Talk with Pi')
            ->assertDontSee('Ask & Answer');
    }

    public function test_a_day_without_cards_stays_a_plain_pi_page(): void
    {
        AskAnswerItem::query()->delete();
        $this->actingAs($this->learnerWhoFinishedDay1());

        Livewire::test('pi-practice.index')
            ->assertDontSee('With Teddy')
            ->assertDontSee('Free talk with Pi (optional)');
    }

    public function test_starting_the_game_makes_a_teddy_practice_for_that_day_and_playing_it_ticks_the_daily_talk(): void
    {
        $this->seed(AskAnswerSeeder::class);
        $learner = $this->learnerWhoFinishedDay1();
        $this->actingAs($learner);

        Livewire::test('pi-practice.index')->call('startGame')->assertRedirect();

        $practice = AiPractice::where('learner_id', $learner->id)->latest('id')->first();
        $this->assertSame(['M01-1'], $practice->days);
        $this->assertSame('ask-answer', $practice->game());
        $this->assertSame(['M01-1'], collect($practice->cards())->pluck('source')->map(fn (string $source) => 'M01-'.substr($source, -1))->unique()->values()->all());
        $this->assertFalse($learner->hasPracticedWithPiToday());

        $practice->update(['answered' => AiPractice::MIN_COUNTED_ANSWERS]);
        $practice->creditTheDay();

        $this->assertTrue($learner->refresh()->hasPracticedWithPiToday());
        $this->assertContains(1, $learner->piPracticedDaysOf('M01'));
    }
}
