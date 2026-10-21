<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VocabularyWord;
use App\Notifications\ReviewReminder;
use App\Services\Reminders;
use GuzzleHttp\Psr7\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Minishlink\WebPush\MessageSentReport;
use Mockery\MockInterface;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\TestCase;

class ReviewReminderTest extends TestCase
{
    use RefreshDatabase;

    /** 20:35 in Tehran (UTC+3:30, no DST) — five minutes past the fixed 20:30. */
    private const TEHRAN_EVENING = '2026-10-03 17:05:00';

    private function learner(array $attributes = [], bool $withDueWord = true, bool $withPhone = true): User
    {
        $user = User::factory()->create(array_merge(['timezone' => 'Asia/Tehran'], $attributes));

        if ($withPhone) {
            $user->updatePushSubscription('https://push.example.test/send/'.$user->id, 'key', 'token', 'aes128gcm');
        }

        if ($withDueWord) {
            $this->dueWord($user);
        }

        return $user;
    }

    private function dueWord(User $user, array $attributes = []): VocabularyWord
    {
        return VocabularyWord::create(array_merge([
            'learner_id' => $user->id,
            'word' => 'commute',
            'meaning' => 'to travel to work',
            'next_review_at' => now()->subMinute(),
        ], $attributes));
    }

    private function expectReminders(int $times): MockInterface
    {
        $channel = $this->mock(WebPushChannel::class);
        $channel->shouldReceive('send')->times($times)->andReturn([]);

        return $channel;
    }

    private function runCommand(): void
    {
        $this->artisan('review:send-reminders')->assertSuccessful();
    }

    public function test_the_reminder_goes_out_in_the_evening_and_is_marked_sent(): void
    {
        Carbon::setTestNow(self::TEHRAN_EVENING);
        $user = $this->learner();
        $this->expectReminders(1);

        $this->runCommand();

        $this->assertSame('2026-10-03', $user->fresh()->last_review_reminder_on->toDateString());
    }

    public function test_it_is_sent_at_most_once_a_day(): void
    {
        Carbon::setTestNow(self::TEHRAN_EVENING);
        $this->learner();
        $this->expectReminders(1);

        $this->runCommand();
        Carbon::setTestNow(now()->addMinutes(15));
        $this->runCommand();
    }

    public function test_nothing_is_sent_before_the_reminder_time(): void
    {
        Carbon::setTestNow('2026-10-03 16:55:00');
        $this->learner();
        $this->expectReminders(0);

        $this->runCommand();
    }

    public function test_a_missed_window_is_not_sent_hours_late(): void
    {
        Carbon::setTestNow('2026-10-03 18:05:00');
        $this->learner();
        $this->expectReminders(0);

        $this->runCommand();
    }

    public function test_the_time_is_read_in_the_learners_own_timezone(): void
    {
        Carbon::setTestNow(self::TEHRAN_EVENING);
        $this->learner(['timezone' => 'America/New_York']);
        $this->expectReminders(0);

        $this->runCommand();
    }

    public function test_a_learner_in_another_timezone_is_reached_at_their_local_time(): void
    {
        Carbon::setTestNow('2026-10-04 00:35:00');
        $this->learner(['timezone' => 'America/New_York']);
        $this->expectReminders(1);

        $this->runCommand();
    }

    public function test_nothing_is_sent_when_the_learner_turned_reminders_off(): void
    {
        Carbon::setTestNow(self::TEHRAN_EVENING);
        $this->learner(['review_reminder_enabled' => false]);
        $this->expectReminders(0);

        $this->runCommand();
    }

    public function test_nothing_is_sent_without_a_phone_subscription(): void
    {
        Carbon::setTestNow(self::TEHRAN_EVENING);
        $this->learner(withPhone: false);
        $this->expectReminders(0);

        $this->runCommand();
    }

    public function test_nothing_is_sent_when_no_review_is_due(): void
    {
        Carbon::setTestNow(self::TEHRAN_EVENING);
        $this->learner(withDueWord: false);
        $this->expectReminders(0);

        $this->runCommand();
    }

    public function test_nothing_is_sent_to_someone_who_already_reviewed_today(): void
    {
        Carbon::setTestNow(self::TEHRAN_EVENING);
        $user = $this->learner();
        $this->dueWord($user, ['word' => 'already', 'last_reviewed_at' => now()->subHour(), 'next_review_at' => now()->addDay()]);
        $this->expectReminders(0);

        $this->runCommand();
    }

    public function test_a_review_after_the_learners_midnight_counts_as_today(): void
    {
        Carbon::setTestNow(self::TEHRAN_EVENING);
        $user = $this->learner();
        // 01:30 in Tehran on the 3rd — still the 2nd by the server's UTC clock.
        $this->dueWord($user, ['word' => 'late', 'last_reviewed_at' => '2026-10-02 22:00:00', 'next_review_at' => now()->addDay()]);
        $this->expectReminders(0);

        $this->runCommand();
    }

    public function test_a_push_the_service_refuses_is_logged(): void
    {
        Carbon::setTestNow(self::TEHRAN_EVENING);
        $this->learner();
        Log::spy();

        $refusal = new MessageSentReport(new Request('POST', 'https://push.example.test/send/1'), null, false, 'unreachable');
        $this->mock(WebPushChannel::class)->shouldReceive('send')->once()->andReturn([$refusal]);

        $this->runCommand();

        Log::shouldHaveReceived('error')->once();
    }

    public function test_a_failing_push_does_not_stop_the_other_learners(): void
    {
        Carbon::setTestNow(self::TEHRAN_EVENING);
        $first = $this->learner();
        $second = $this->learner();

        $this->mock(WebPushChannel::class)
            ->shouldReceive('send')->twice()
            ->andThrow(new \RuntimeException('push service unreachable'));

        $this->runCommand();

        $this->assertNotNull($first->fresh()->last_review_reminder_on);
        $this->assertNotNull($second->fresh()->last_review_reminder_on);
    }

    public function test_the_push_says_how_many_are_due_and_opens_the_review_page(): void
    {
        $user = User::factory()->create();
        $notification = new ReviewReminder(5);

        $payload = $notification->toWebPush($user, $notification)->toArray();

        $this->assertStringContainsString('۵ کلمه', $payload['body']);
        $this->assertSame(['url' => route('review.index')], $payload['data']);
        $this->assertSame('review-reminder', $payload['tag']);
        $this->assertSame('مرور کلمه‌ها', $payload['title']);
    }

    public function test_the_reminder_is_honoured_to_the_exact_minute(): void
    {
        Carbon::setTestNow('2026-10-03 16:59:00');
        $user = $this->learner();
        $this->expectReminders(1);

        $this->runCommand();

        $this->assertNull($user->fresh()->last_review_reminder_on);

        Carbon::setTestNow('2026-10-03 17:00:00');
        $this->runCommand();

        $this->assertNotNull($user->fresh()->last_review_reminder_on);
    }

    public function test_nothing_is_sent_when_another_reminder_already_went_out_today(): void
    {
        Carbon::setTestNow(self::TEHRAN_EVENING);
        $user = $this->learner();
        app(Reminders::class)->countSent($user);
        $this->expectReminders(0);

        $this->runCommand();

        $this->assertNull($user->fresh()->last_review_reminder_on);
    }

    public function test_yesterdays_reminders_do_not_hold_back_tonights_reminder(): void
    {
        Carbon::setTestNow(self::TEHRAN_EVENING);
        $user = $this->learner();
        Carbon::setTestNow(now()->subDay());
        app(Reminders::class)->countSent($user);
        app(Reminders::class)->countSent($user);
        Carbon::setTestNow(self::TEHRAN_EVENING);
        $this->expectReminders(1);

        $this->runCommand();
    }

    public function test_the_switch_shows_the_saved_choice(): void
    {
        $this->actingAs(User::factory()->create(['review_reminder_enabled' => false]));

        Livewire::test('notifications.review-reminder')->assertSet('enabled', false);
    }

    public function test_a_learner_can_turn_the_reminder_off_and_on_again(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $component = Livewire::test('notifications.review-reminder')->assertSet('enabled', true)->set('enabled', false);
        $this->assertFalse($user->fresh()->review_reminder_enabled);

        $component->set('enabled', true);
        $this->assertTrue($user->fresh()->review_reminder_enabled);
    }

    public function test_switching_it_remembers_the_browsers_timezone(): void
    {
        $user = User::factory()->create(['timezone' => null]);
        $this->actingAs($user);

        Livewire::withCookie('eos_tz', 'Europe/London')
            ->test('notifications.review-reminder')
            ->set('enabled', false);

        $this->assertSame('Europe/London', $user->fresh()->timezone);
    }

    public function test_the_page_warns_when_phone_alerts_are_not_on_yet(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('notifications.review-reminder')->assertSee('haven’t turned those on');
    }
}
