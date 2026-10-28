<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\PairPracticeSession;
use App\Models\User;
use App\Notifications\Channels\DeferredWebPushChannel;
use App\Notifications\ComebackReminder;
use App\Notifications\FriendFinishedDay;
use App\Notifications\FriendNotStudiedYet;
use App\Notifications\FriendStudyingNow;
use App\Notifications\PairBothFinished;
use App\Notifications\PairPracticeInvite;
use App\Notifications\PairPracticeNudge;
use App\Notifications\PairStreakWarning;
use App\Notifications\StudyReminder;
use App\Services\Reminders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Mockery;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\TestCase;

class SmartRemindersTest extends TestCase
{
    use RefreshDatabase;

    /** 12:05 in Tehran (UTC+3:30). */
    private const TEHRAN_NOON = '2026-10-03 08:35:00';

    private function makeMission(): Mission
    {
        return Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [
                ['phase' => 'foundation', 'label' => 'Day 1', 'steps' => [
                    ['key' => 'vocabulary_builder_1', 'label' => 'New Words', 'duration_minutes' => 5],
                    ['key' => 'listening_1', 'label' => 'Listening', 'duration_minutes' => 8],
                ]],
                ['phase' => 'build', 'label' => 'Day 2', 'steps' => [
                    ['key' => 'grammar_in_context', 'label' => 'Grammar', 'duration_minutes' => 6],
                ]],
            ],
        ]);
    }

    private function learner(array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['timezone' => 'Asia/Tehran'], $attributes));
        $user->updatePushSubscription('https://push.example.test/send/'.$user->id, 'key', 'token', 'aes128gcm');

        return $user;
    }

    private function makeMutualFriend(User $me): User
    {
        $friend = $this->learner();
        $me->follow($friend);
        $friend->acceptFollowRequest($me);

        return $friend;
    }

    /**
     * @param  list<string>  $stepKeys
     */
    private function answer(User $user, Mission $mission, array $stepKeys): void
    {
        $run = MissionRun::findOrStart($user, $mission);

        foreach ($stepKeys as $key) {
            Evidence::create(['mission_run_id' => $run->id, 'phase' => $key, 'type' => Evidence::TYPE_TEXT, 'content_ref' => '[]']);
        }
    }

    public function test_the_study_reminder_says_exactly_what_is_waiting(): void
    {
        Carbon::setTestNow(self::TEHRAN_NOON);
        $mission = $this->makeMission();
        $user = $this->learner(['study_reminder_time_1' => '12:00']);
        $this->answer($user, $mission, ['vocabulary_builder_1']);

        $this->mock(WebPushChannel::class)->shouldReceive('send')->once()->with(Mockery::on(fn (User $recipient) => $recipient->is($user)), Mockery::on(function (StudyReminder $notification) use ($user) {
            $body = $notification->toWebPush($user, $notification)->toArray()['body'];

            return str_contains($body, 'روز ۱ از M01: Listening') && str_contains($body, '۸ دقیقه');
        }))->andReturn([]);

        $this->artisan('study:send-reminders')->assertSuccessful();
    }

    public function test_no_study_reminder_once_todays_mission_day_is_finished(): void
    {
        Carbon::setTestNow(self::TEHRAN_NOON);
        $mission = $this->makeMission();
        $user = $this->learner(['study_reminder_time_1' => '12:00']);
        $this->answer($user, $mission, ['vocabulary_builder_1', 'listening_1']);

        $this->mock(WebPushChannel::class)->shouldReceive('send')->never();

        $this->artisan('study:send-reminders')->assertSuccessful();
        $this->assertSame('2026-10-03', $user->fresh()->last_study_reminder_1_on->toDateString());
    }

    public function test_no_more_than_three_automatic_alerts_a_day(): void
    {
        Carbon::setTestNow(self::TEHRAN_NOON);
        $user = $this->learner(['study_reminder_time_1' => '12:00']);

        foreach (range(1, Reminders::DAILY_LIMIT) as $sent) {
            app(Reminders::class)->countSent($user);
        }

        $this->mock(WebPushChannel::class)->shouldReceive('send')->never();

        $this->artisan('study:send-reminders')->assertSuccessful();
    }

    public function test_daily_reminders_stop_after_a_week_away(): void
    {
        Carbon::setTestNow(self::TEHRAN_NOON);
        $user = $this->learner(['study_reminder_time_1' => '12:00']);
        Carbon::setTestNow(now()->subDays(Reminders::AWAY_DAYS));
        $user->recordListeningToday('M01', 1);
        Carbon::setTestNow(self::TEHRAN_NOON);

        $this->mock(WebPushChannel::class)->shouldReceive('send')->never();

        $this->artisan('study:send-reminders')->assertSuccessful();
    }

    public function test_one_comeback_alert_goes_out_after_five_days_away(): void
    {
        $user = $this->learner();
        Carbon::setTestNow('2026-09-28 15:35:00');
        $user->recordListeningToday('M01', 1);

        $this->mock(WebPushChannel::class)->shouldReceive('send')->once()->with(Mockery::on(fn (User $recipient) => $recipient->is($user)), Mockery::type(ComebackReminder::class))->andReturn([]);

        // 19:05 in Tehran, four days later: not a comeback day yet.
        Carbon::setTestNow('2026-10-02 15:35:00');
        $this->artisan('reminders:send-comeback')->assertSuccessful();

        Carbon::setTestNow('2026-10-03 15:35:00');
        $this->artisan('reminders:send-comeback')->assertSuccessful();
        Carbon::setTestNow('2026-10-03 15:50:00');
        $this->artisan('reminders:send-comeback')->assertSuccessful();
    }

    public function test_friends_hear_once_a_day_when_a_learner_starts_studying_and_when_both_have_finished(): void
    {
        Notification::fake();
        $mission = $this->makeMission();
        $me = $this->learner();
        $friend = $this->makeMutualFriend($me);

        $this->answer($me, $mission, ['vocabulary_builder_1']);
        Notification::assertSentTo($friend, FriendStudyingNow::class, fn (FriendStudyingNow $alert) => str_contains($alert->toArray($friend)['title'], 'M01 · New Words'));

        // Finishing the day is a second alert about the same friend — one a day is enough.
        $this->answer($me, $mission, ['listening_1']);
        Notification::assertSentToTimes($friend, FriendStudyingNow::class, 1);
        Notification::assertNotSentTo($friend, FriendFinishedDay::class);

        $this->answer($friend, $mission, ['vocabulary_builder_1', 'listening_1']);
        Notification::assertSentTo($me, PairBothFinished::class);
        Notification::assertSentTo($friend, PairBothFinished::class);
        Notification::assertNotSentTo($me, FriendFinishedDay::class);
    }

    public function test_an_answer_that_finishes_the_day_says_your_turn_instead_of_studying_now(): void
    {
        Notification::fake();
        $mission = $this->makeMission();
        $me = $this->learner();
        $friend = $this->makeMutualFriend($me);
        $this->travel(-1)->days();
        $this->answer($me, $mission, ['vocabulary_builder_1']);
        $this->travelBack();

        $this->answer($me, $mission, ['listening_1']);

        Notification::assertSentToTimes($friend, FriendFinishedDay::class, 1);
        Notification::assertSentToTimes($friend, FriendStudyingNow::class, 1);
    }

    public function test_a_muted_friend_sends_no_news_about_their_day(): void
    {
        Notification::fake();
        $mission = $this->makeMission();
        $me = $this->learner();
        $friend = $this->makeMutualFriend($me);
        $friend->setMuted($me, true);

        $this->answer($me, $mission, ['vocabulary_builder_1', 'listening_1']);
        $this->answer($friend, $mission, ['vocabulary_builder_1', 'listening_1']);

        foreach ([FriendStudyingNow::class, FriendFinishedDay::class, PairBothFinished::class] as $alert) {
            Notification::assertNotSentTo($friend, $alert);
        }
        Notification::assertSentTo($me, PairBothFinished::class);
    }

    public function test_with_friend_alerts_switched_off_a_learner_hears_nothing_about_friends_days(): void
    {
        Notification::fake();
        $mission = $this->makeMission();
        $me = $this->learner();
        $friend = $this->makeMutualFriend($me);
        $friend->update(['friend_alerts' => false]);

        $this->answer($me, $mission, ['vocabulary_builder_1']);

        Notification::assertNotSentTo($friend, FriendStudyingNow::class);
    }

    public function test_after_studying_a_learner_hears_at_five_which_friends_have_not_yet(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-10-03 13:00:00'); // 16:30 in Tehran
        $mission = $this->makeMission();
        $me = $this->learner();
        $lazy = $this->makeMutualFriend($me);
        $muted = $this->makeMutualFriend($me);
        $me->setMuted($muted, true);
        $busy = $this->makeMutualFriend($me);
        $this->answer($busy, $mission, ['grammar_in_context']);

        $this->artisan('friends:send-nudge-hints');
        Notification::assertNotSentTo($me, FriendNotStudiedYet::class);

        Carbon::setTestNow('2026-10-03 13:31:00'); // 17:01
        $this->artisan('friends:send-nudge-hints');
        Notification::assertNotSentTo($me, FriendNotStudiedYet::class);

        Carbon::setTestNow('2026-10-04 13:31:00');
        $this->answer($me, $mission, ['grammar_in_context']);
        $this->answer($busy, $mission, ['grammar_in_context']);
        $this->artisan('friends:send-nudge-hints');
        $this->artisan('friends:send-nudge-hints');

        Notification::assertSentToTimes($me, FriendNotStudiedYet::class, 1);
        Notification::assertSentTo($me, FriendNotStudiedYet::class, fn (FriendNotStudiedYet $alert) => $alert->toArray($me)['names'] === [$lazy->name]);
    }

    public function test_friend_alerts_go_to_the_bell_only_during_quiet_hours_but_a_live_invite_still_buzzes(): void
    {
        $me = $this->learner();
        $friend = $this->learner();
        $nudge = new PairPracticeNudge($friend);
        $invite = new PairPracticeInvite(PairPracticeSession::factory()->waiting()->create(), $friend);

        // 23:30 in Tehran.
        Carbon::setTestNow('2026-10-03 20:00:00');
        $this->assertSame(['database'], $nudge->via($me));
        $this->assertSame(['database', DeferredWebPushChannel::class], $invite->via($me));

        // 09:00 in Tehran.
        Carbon::setTestNow('2026-10-03 05:30:00');
        $this->assertSame(['database', DeferredWebPushChannel::class], $nudge->via($me));
    }

    public function test_the_pair_streak_warning_reaches_the_one_who_has_not_finished_today(): void
    {
        Notification::fake();
        $mission = $this->makeMission();
        $me = $this->learner();
        $friend = $this->makeMutualFriend($me);

        Carbon::setTestNow('2026-10-02 10:00:00');
        $this->answer($me, $mission, ['vocabulary_builder_1', 'listening_1']);
        $this->answer($friend, $mission, ['vocabulary_builder_1', 'listening_1']);

        // 20:05 in Tehran the next day; the friend already finished today.
        Carbon::setTestNow('2026-10-03 10:00:00');
        $this->answer($friend, $mission, ['grammar_in_context']);
        Carbon::setTestNow('2026-10-03 16:35:00');

        $this->artisan('pair:send-streak-warnings')->assertSuccessful();

        Notification::assertSentTo($me, PairStreakWarning::class, fn (PairStreakWarning $notification) => str_contains($notification->toArray($me)['title'], '۱ روزهٔ تو و '.$friend->name));
        Notification::assertNotSentTo($friend, PairStreakWarning::class);
    }
}
