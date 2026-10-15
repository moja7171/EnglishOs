<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\StudyReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Mockery\MockInterface;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\TestCase;

class StudyReminderTest extends TestCase
{
    use RefreshDatabase;

    /** 12:05 in Tehran (UTC+3:30, no DST) — five minutes past a 12:00 slot. */
    private const TEHRAN_NOON = '2026-10-03 08:35:00';

    /** 20:05 in Tehran. */
    private const TEHRAN_EVENING = '2026-10-03 16:35:00';

    private function learner(array $attributes = [], bool $withPhone = true): User
    {
        $user = User::factory()->create(array_merge([
            'timezone' => 'Asia/Tehran',
            'study_reminder_time_1' => '12:00',
            'study_reminder_time_2' => '20:00',
        ], $attributes));

        if ($withPhone) {
            $user->updatePushSubscription('https://push.example.test/send/'.$user->id, 'key', 'token', 'aes128gcm');
        }

        return $user;
    }

    private function expectAlerts(int $times): MockInterface
    {
        $channel = $this->mock(WebPushChannel::class);
        $channel->shouldReceive('send')->times($times)->andReturn([]);

        return $channel;
    }

    private function runCommand(): void
    {
        $this->artisan('study:send-reminders')->assertSuccessful();
    }

    public function test_each_slot_fires_at_its_own_time_and_is_marked_sent(): void
    {
        $user = $this->learner();
        $this->expectAlerts(2);

        Carbon::setTestNow(self::TEHRAN_NOON);
        $this->runCommand();

        $this->assertSame('2026-10-03', $user->fresh()->last_study_reminder_1_on->toDateString());
        $this->assertNull($user->fresh()->last_study_reminder_2_on);

        Carbon::setTestNow(self::TEHRAN_EVENING);
        $this->runCommand();

        $this->assertSame('2026-10-03', $user->fresh()->last_study_reminder_2_on->toDateString());
    }

    public function test_a_slot_is_sent_at_most_once_a_day(): void
    {
        Carbon::setTestNow(self::TEHRAN_NOON);
        $this->learner();
        $this->expectAlerts(1);

        $this->runCommand();
        Carbon::setTestNow(now()->addMinutes(15));
        $this->runCommand();
    }

    public function test_it_is_sent_even_when_the_learner_already_studied_today(): void
    {
        Carbon::setTestNow(self::TEHRAN_NOON);
        $user = $this->learner();
        $user->recordListeningToday('M01', 1);
        $this->expectAlerts(1);

        $this->runCommand();
    }

    public function test_nothing_is_sent_before_the_time_or_after_the_window(): void
    {
        $this->learner();
        $this->expectAlerts(0);

        Carbon::setTestNow('2026-10-03 08:25:00');
        $this->runCommand();

        Carbon::setTestNow('2026-10-03 10:45:00');
        $this->runCommand();
    }

    public function test_nothing_is_sent_when_the_slots_are_off(): void
    {
        Carbon::setTestNow(self::TEHRAN_NOON);
        $this->learner(['study_reminder_time_1' => null, 'study_reminder_time_2' => null]);
        $this->expectAlerts(0);

        $this->runCommand();
    }

    public function test_nothing_is_sent_without_a_phone_subscription(): void
    {
        Carbon::setTestNow(self::TEHRAN_NOON);
        $this->learner(withPhone: false);
        $this->expectAlerts(0);

        $this->runCommand();
    }

    public function test_two_slots_at_the_same_time_buzz_only_once(): void
    {
        Carbon::setTestNow(self::TEHRAN_NOON);
        $this->learner(['study_reminder_time_2' => '12:00']);
        $this->expectAlerts(1);

        $this->runCommand();
    }

    public function test_the_time_is_read_in_the_learners_own_timezone(): void
    {
        Carbon::setTestNow(self::TEHRAN_NOON);
        $this->learner(['timezone' => 'America/New_York']);
        $this->expectAlerts(0);

        $this->runCommand();
    }

    public function test_the_alert_is_in_persian_and_opens_the_home_page(): void
    {
        $user = User::factory()->create();

        foreach (range(0, count(StudyReminder::MESSAGES) - 1) as $index) {
            $notification = new StudyReminder($index);
            $payload = $notification->toWebPush($user, $notification)->toArray();

            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $payload['body']);
            $this->assertSame(['url' => route('home')], $payload['data']);
        }
    }

    public function test_the_picker_starts_with_both_slots_off(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('notifications.study-reminders')
            ->assertSet('times.0.enabled', false)
            ->assertSet('times.1.enabled', false);
    }

    public function test_the_picker_shows_the_saved_times(): void
    {
        $this->actingAs(User::factory()->create(['study_reminder_time_1' => '07:45']));

        Livewire::test('notifications.study-reminders')
            ->assertSet('times.0.enabled', true)
            ->assertSet('times.0.hour', '07')
            ->assertSet('times.0.minute', '45')
            ->assertSet('times.1.enabled', false);
    }

    public function test_a_learner_can_set_two_times_and_switch_one_off(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('notifications.study-reminders')
            ->set('times.0.enabled', true)
            ->set('times.1.enabled', true)
            ->set('times.1.hour', '21')
            ->set('times.1.minute', '30')
            ->assertSet('saved', true);

        $this->assertSame('12:00', $user->fresh()->study_reminder_time_1);
        $this->assertSame('21:30', $user->fresh()->study_reminder_time_2);

        Livewire::test('notifications.study-reminders')->set('times.0.enabled', false);

        $this->assertNull($user->fresh()->study_reminder_time_1);
        $this->assertSame('21:30', $user->fresh()->study_reminder_time_2);
    }

    public function test_a_malformed_time_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('notifications.study-reminders')
            ->set('times.0.enabled', true)
            ->set('times.0.hour', '25')
            ->assertHasErrors('times.0.hour');

        $this->assertSame('12:00', $user->fresh()->study_reminder_time_1);
    }

    public function test_switching_on_a_time_that_already_passed_today_starts_tomorrow(): void
    {
        Carbon::setTestNow(self::TEHRAN_EVENING);
        $user = User::factory()->create(['timezone' => 'Asia/Tehran']);
        $this->actingAs($user);

        Livewire::test('notifications.study-reminders')->set('times.0.enabled', true);

        $this->assertSame('2026-10-03', $user->fresh()->last_study_reminder_1_on->toDateString());
    }

    public function test_moving_a_time_ahead_after_enabling_it_still_fires_today(): void
    {
        Carbon::setTestNow(self::TEHRAN_EVENING);
        $user = User::factory()->create(['timezone' => 'Asia/Tehran']);
        $user->updatePushSubscription('https://push.example.test/send/'.$user->id, 'key', 'token', 'aes128gcm');
        $this->actingAs($user);

        // Switching on starts at 12:00, already past at 20:05, so it is parked until tomorrow...
        $component = Livewire::test('notifications.study-reminders')->set('times.0.enabled', true);
        $this->assertNotNull($user->fresh()->last_study_reminder_1_on);

        // ...but picking 20:06, still ahead today, must make today's alert go out.
        $component->set('times.0.hour', '20')->set('times.0.minute', '06');
        $this->assertNull($user->fresh()->last_study_reminder_1_on);

        $this->expectAlerts(1);
        Carbon::setTestNow('2026-10-03 16:36:00');
        $this->runCommand();
    }
}
