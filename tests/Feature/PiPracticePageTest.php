<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\ListeningLog;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\PiChatFeedback;
use App\Models\PiPracticeLog;
use App\Models\User;
use App\Services\GeminiClient;
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
            ->assertSee('Daily talk')
            ->assertSee('M01 · My Daily Life · Day 1')
            ->assertSee('Pronunciation · 10 min')
            ->assertSee('Talk with Pi')
            ->assertSee('Get feedback')
            ->assertSee("Today's plan")
            ->assertSee('Be my warm, fun English buddy');
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

    public function test_pi_opens_in_its_android_app_and_the_prompt_can_be_copied_on_the_way(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('pi.practice'))
            ->assertSee('Copy & open Pi')
            ->assertSee('package=ai.inflection.pi')
            ->assertSee('open the Pi app and paste')
            ->assertDontSee('S.browser_fallback_url=https%3A%2F%2Fpi.ai');
    }

    public function test_each_day_of_the_mission_has_its_own_practice(): void
    {
        $this->actingAs($this->finishedLearner());

        $this->get(route('pi.practice', ['M01', 2]))->assertSee('5 questions');
        $this->get(route('pi.practice', ['M01', 3]))->assertSee('6 turns');
        $this->get(route('pi.practice', ['M01', 4]))
            ->assertSee('Retell &amp; Fix', false)
            ->assertSee('3 fixes');
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

    public function test_a_mission_finished_today_keeps_its_retell_day_as_the_target(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission());
        $this->finishDays($run, ['foundation', 'build', 'practice', 'challenge']);
        $run->update(['status' => MissionRun::STATUS_COMPLETE, 'completed_at' => now()]);
        $this->actingAs($learner);

        $this->get(route('pi.practice'))
            ->assertSee('M01 · My Daily Life · Day 4')
            ->assertSee('Retell &amp; Fix', false);
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

    public function test_an_earlier_day_shows_its_own_plan_and_tick_not_todays(): void
    {
        $learner = $this->learnerWhoFinishedDay1(yesterday: true);
        PiPracticeLog::factory()->for($learner, 'learner')->create(['day_number' => 2]);
        $this->actingAs($learner);

        $this->get(route('pi.practice', ['M01', 1]))
            ->assertSee('Day 1 plan')
            ->assertSee('Ticks itself after Feedback.')
            ->assertDontSeeHtml(' Practiced</span>');
    }

    public function test_an_earlier_day_done_before_shows_as_practiced_on_its_day_button(): void
    {
        $learner = $this->learnerWhoFinishedDay1(yesterday: true);
        PiChatFeedback::factory()->for($learner, 'learner')->create(['chat' => '', 'feedback' => ['practice_done' => true]]);
        $this->actingAs($learner);

        $this->get(route('pi.practice', ['M01', 1]))
            ->assertSeeHtml(' Practiced</span>')
            ->assertDontSee('Ticks itself after Feedback.')
            ->assertSeeInOrder(['title="Pronunciation"', '<span class="sr-only">, done</span>', 'Day 2 · today'], false);
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

    /**
     * The AI's verdict on whether the pasted chat really is the day's practice.
     */
    private function mockPracticeVerdict(bool $practiceDone): void
    {
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')
            ->withArgs(fn ($messages, $systemPrompt) => str_contains($systemPrompt, '"practice_done"'))
            ->andReturn($this->learnerFeedbackReply(['practice_done' => $practiceDone])));
    }

    private const A_PRACTICE_CHAT = "My words:\nRound 1 — Q: What did you do? / Me: I went to the park yesterday.";

    public function test_the_page_has_no_i_practiced_button_to_tick_by_hand(): void
    {
        $this->actingAs($this->learnerWhoFinishedDay1());

        $this->get(route('pi.practice'))
            ->assertSee('Ticks itself after Feedback.')
            ->assertDontSeeHtml('wire:click="markPracticed"');
    }

    public function test_feedback_that_sees_the_practice_done_ticks_it_under_the_day_on_screen(): void
    {
        $learner = $this->learnerWhoFinishedDay1();
        $this->actingAs($learner);
        $this->mockPracticeVerdict(true);

        Livewire::test('pi-practice.index', ['requestedMission' => 'M01', 'requestedDay' => 1])
            ->set('pastedChat', self::A_PRACTICE_CHAT)
            ->call('checkPastedChat')
            ->assertSeeHtml(' Practiced</span>')
            ->assertDontSee('Ticks itself after Feedback.');

        $this->assertDatabaseHas('pi_practice_logs', [
            'learner_id' => $learner->id,
            'practiced_on' => now()->toDateString(),
            'mission_code' => 'M01',
            'day_number' => 1,
        ]);
    }

    public function test_feedback_on_a_chat_that_is_not_the_days_practice_does_not_tick_it(): void
    {
        $learner = $this->learnerWhoFinishedDay1();
        $this->actingAs($learner);
        $this->mockPracticeVerdict(false);

        Livewire::test('pi-practice.index')
            ->set('pastedChat', self::A_PRACTICE_CHAT)
            ->call('checkPastedChat')
            ->assertSee('I work in a hospital.')
            ->assertSee('Not counted');

        $this->assertFalse($learner->hasPracticedWithPiToday());
    }

    public function test_a_pasted_pi_chat_gets_feedback_on_the_learners_own_turns(): void
    {
        $this->actingAs(User::factory()->create());

        $chat = "Pi: What did you do this weekend?\nMe: I go to the park with my sister and we eat ice cream.";

        $this->mock(GeminiClient::class, function ($mock) use ($chat) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(fn ($messages, $systemPrompt) => $messages[0]['text'] === $chat
                    && str_contains($systemPrompt, 'judge ONLY the learner\'s own turns'))
                ->andReturn($this->learnerFeedbackReply(['sentences' => [['original' => 'I go to the park with my sister.', 'status' => 'fix', 'corrected' => 'I went to the park with my sister.', 'why' => 'برای گذشته فعل گذشته لازم است.', 'pattern' => 'past simple']]]));
        });

        Livewire::test('pi-practice.index')
            ->set('pastedChat', $chat)
            ->call('checkPastedChat')
            ->assertSee('I went to the park')
            ->assertSee('برای گذشته فعل گذشته لازم است.');
    }

    public function test_a_pasted_pronunciation_drill_is_judged_on_the_lines_not_on_grammar(): void
    {
        $learner = $this->learnerWhoFinishedDay1(yesterday: true);
        $this->actingAs($learner);

        $chat = "My words:\nRound 1 — Line: I always get up early. / Me: I always get a pearly.";

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')
            ->once()
            ->withArgs(fn ($messages, $systemPrompt) => str_contains($systemPrompt, 'PRONUNCIATION drill')
                && str_contains($systemPrompt, 'NEVER judge their grammar')
                && ! str_contains($systemPrompt, 'never judge punctuation, capital letters or pronunciation'))
            ->andReturn($this->learnerFeedbackReply()));

        Livewire::test('pi-practice.index', ['requestedMission' => 'M01', 'requestedDay' => 1])
            ->set('pastedChat', $chat)
            ->call('checkPastedChat')
            ->assertHasNoErrors();
    }

    public function test_a_pasted_chat_from_a_later_day_is_judged_against_that_days_goal(): void
    {
        $learner = $this->learnerWhoFinishedDay1(yesterday: true);
        $this->actingAs($learner);

        $chat = "My words:\nRound 1 — Q: What do you do on Sundays? / Me: I sleep in and I watch films.";

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')
            ->once()
            ->withArgs(fn ($messages, $systemPrompt) => str_contains($systemPrompt, 'GRAMMAR practice')
                && str_contains($systemPrompt, 'never judge punctuation, capital letters or pronunciation'))
            ->andReturn($this->learnerFeedbackReply()));

        Livewire::test('pi-practice.index', ['requestedMission' => 'M01', 'requestedDay' => 2])
            ->set('pastedChat', $chat)
            ->call('checkPastedChat')
            ->assertHasNoErrors();
    }

    public function test_the_feedback_on_a_pasted_chat_is_still_there_when_the_learner_comes_back_to_that_day(): void
    {
        $learner = $this->learnerWhoFinishedDay1(yesterday: true);
        $this->actingAs($learner);

        $chat = "My words:\nRound 1 — Q: What did you do? / Me: I go to the park yesterday.";

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')
            ->twice()
            ->andReturn($this->learnerFeedbackReply(['sentences' => [['original' => 'I go to the park yesterday.', 'status' => 'fix', 'corrected' => 'I went to the park yesterday.', 'why' => 'برای گذشته فعل گذشته لازم است.', 'pattern' => 'past simple']]])));

        Livewire::test('pi-practice.index', ['requestedMission' => 'M01', 'requestedDay' => 1])
            ->set('pastedChat', $chat)
            ->call('checkPastedChat')
            ->call('checkPastedChat');

        $this->assertSame(1, $learner->piChatFeedback()->count());

        Livewire::test('pi-practice.index', ['requestedMission' => 'M01', 'requestedDay' => 1])
            ->assertSet('pastedChat', $chat)
            ->assertSee('I went to the park yesterday.');

        Livewire::test('pi-practice.index', ['requestedMission' => 'M01', 'requestedDay' => 2])
            ->assertSet('day', 2)
            ->assertSet('pastedChat', '')
            ->assertDontSee('I went to the park yesterday.');
    }

    public function test_closing_the_feedback_forgets_the_saved_chat_of_that_day(): void
    {
        $learner = $this->learnerWhoFinishedDay1();
        $this->actingAs($learner);
        PiChatFeedback::factory()->for($learner, 'learner')->create(['mission_code' => 'M01', 'day_number' => 1]);

        Livewire::test('pi-practice.index', ['requestedMission' => 'M01', 'requestedDay' => 1])
            ->call('dismissFeedback')
            ->assertSet('pastedChat', '')
            ->assertSet('feedback', null);

        $this->assertDatabaseMissing('pi_chat_feedback', ['learner_id' => $learner->id]);
    }

    public function test_closing_the_feedback_of_a_done_practice_keeps_the_days_tick_but_not_the_chat(): void
    {
        $learner = $this->learnerWhoFinishedDay1();
        $this->actingAs($learner);
        PiChatFeedback::factory()->for($learner, 'learner')->create(['feedback' => ['summary' => 'Done.', 'practice_done' => true]]);

        Livewire::test('pi-practice.index', ['requestedMission' => 'M01', 'requestedDay' => 1])
            ->call('dismissFeedback')
            ->assertSet('pastedChat', '')
            ->assertSet('feedback', null);

        Livewire::test('pi-practice.index', ['requestedMission' => 'M01', 'requestedDay' => 1])
            ->assertSet('pastedChat', '')
            ->assertSet('feedback', null);

        $this->assertSame([1], $learner->piPracticedDaysOf('M01'));
    }

    public function test_a_later_paste_that_is_not_the_practice_keeps_a_done_day_done(): void
    {
        $learner = $this->learnerWhoFinishedDay1();
        $this->actingAs($learner);
        PiChatFeedback::factory()->for($learner, 'learner')->create(['feedback' => ['summary' => 'Done.', 'practice_done' => true]]);
        $this->mockPracticeVerdict(false);

        Livewire::test('pi-practice.index', ['requestedMission' => 'M01', 'requestedDay' => 1])
            ->set('pastedChat', self::A_PRACTICE_CHAT)
            ->call('checkPastedChat')
            ->assertSee('Not counted');

        $this->assertSame([1], $learner->piPracticedDaysOf('M01'));
    }

    public function test_a_too_short_paste_is_not_sent_for_feedback(): void
    {
        $this->actingAs(User::factory()->create());

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldNotReceive('chat'));

        Livewire::test('pi-practice.index')
            ->set('pastedChat', 'hi')
            ->call('checkPastedChat')
            ->assertHasErrors(['pastedChat' => 'min']);
    }

    public function test_a_practice_seen_twice_on_the_same_day_keeps_a_single_row(): void
    {
        $learner = $this->learnerWhoFinishedDay1();
        $this->actingAs($learner);
        $this->mockPracticeVerdict(true);

        Livewire::test('pi-practice.index')
            ->set('pastedChat', self::A_PRACTICE_CHAT)
            ->call('checkPastedChat')
            ->call('checkPastedChat');

        $this->assertSame(1, $learner->piPracticeLogs()->count());
    }

    public function test_the_practice_tick_and_the_listening_tick_are_independent(): void
    {
        $learner = User::factory()->create();
        ListeningLog::factory()->for($learner, 'learner')->create(['listened_on' => now()->toDateString()]);
        $this->actingAs($learner);
        $this->mockPracticeVerdict(true);

        Livewire::test('pi-practice.index')
            ->set('pastedChat', self::A_PRACTICE_CHAT)
            ->call('checkPastedChat');

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

    public function test_the_today_box_shows_the_voice_practice_row_below_the_days_steps(): void
    {
        $this->actingAs($this->learnerWhoFinishedDay1(yesterday: true));

        $this->get(route('home'))
            ->assertSeeInOrder(['Continue', 'Build step', 'Daily talk · Grammar']);
    }

    public function test_the_voice_practice_rows_circle_on_home_cannot_tick_it_by_hand(): void
    {
        $this->actingAs($this->learnerWhoFinishedDay1(yesterday: true));

        Livewire::test('missions.overview')
            ->assertSee('Daily talk · Grammar')
            ->assertDontSeeHtml('wire:click="markPracticed"');
    }

    public function test_the_today_box_row_shows_the_done_state_after_practicing(): void
    {
        $learner = $this->learnerWhoFinishedDay1(yesterday: true);
        PiPracticeLog::factory()->for($learner, 'learner')->create(['practiced_on' => now()->toDateString()]);
        $this->actingAs($learner);

        $this->get(route('home'))
            ->assertSee('Daily talk · Grammar')
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
}
