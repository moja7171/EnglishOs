<?php

namespace Tests\Feature;

use App\Models\PairPracticeSession;
use App\Models\User;
use App\Notifications\Channels\DeferredWebPushChannel;
use App\Notifications\FriendFinishedDay;
use App\Notifications\PairPracticeInvite;
use App\Notifications\StudyReminder;
use App\Services\Reminders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\TestCase;

/**
 * Where an alert goes (bell, phone, in-app banner), how often, and how it
 * is cleared once seen.
 */
class SmartNotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    /** 12:05 in Tehran. */
    private const TEHRAN_NOON = '2026-10-03 08:35:00';

    private function learner(array $attributes = []): User
    {
        $user = User::factory()->create(['timezone' => 'Asia/Tehran', ...$attributes]);
        $user->updatePushSubscription('https://push.example.test/send/'.$user->id, 'key', 'token', 'aes128gcm');

        return $user;
    }

    public function test_a_scheduled_reminder_also_lands_in_the_bell_and_the_push_carries_its_id(): void
    {
        Carbon::setTestNow(self::TEHRAN_NOON);
        $user = $this->learner(['study_reminder_time_1' => '12:00']);
        $pushed = null;
        $this->mock(WebPushChannel::class)->shouldReceive('send')->once()
            ->withArgs(function ($notifiable, $notification) use (&$pushed) {
                $pushed = $notification;

                return true;
            })
            ->andReturn([]);

        $this->artisan('study:send-reminders')->assertSuccessful();

        $row = $user->unreadNotifications()->sole();
        $this->assertSame(StudyReminder::class, $row->type);
        $this->assertSame(StudyReminder::TOPIC, $row->data['topic']);
        $this->assertSame($row->id, $pushed->id);
        $this->assertSame($row->id, $pushed->toWebPush($user, $pushed)->toArray()['data']['id']);
    }

    public function test_a_newer_reminder_replaces_the_older_one_in_the_bell(): void
    {
        $user = $this->learner();

        $user->notifyNow(new StudyReminder('12:00'), ['database']);
        $user->notifyNow(new StudyReminder('20:00'), ['database']);

        $this->assertSame(1, $user->notifications()->count());
    }

    public function test_friends_who_finish_the_same_day_share_one_bell_row(): void
    {
        $me = $this->learner();
        $sara = User::factory()->create(['name' => 'Sara']);
        $ali = User::factory()->create(['name' => 'Ali']);
        $reza = User::factory()->create(['name' => 'Reza']);

        $me->notify(new FriendFinishedDay($sara));
        $me->notify(new FriendFinishedDay($ali));
        $me->notify(new FriendFinishedDay($reza));

        $row = $me->notifications()->sole();
        $this->assertSame(['Reza', 'Ali', 'Sara'], $row->data['names']);
        $this->assertStringContainsString('Reza، Ali و ۱ نفر دیگه', $row->data['title']);
    }

    public function test_news_stops_buzzing_once_the_days_alerts_are_used_but_keeps_one_for_the_evening_reminder(): void
    {
        Carbon::setTestNow(self::TEHRAN_NOON);
        $me = $this->learner();
        $friend = User::factory()->create();

        foreach (range(1, Reminders::DAILY_LIMIT - Reminders::KEPT_FOR_REMINDERS) as $sent) {
            $this->assertSame(['database', DeferredWebPushChannel::class], (new FriendFinishedDay($friend))->via($me));
        }

        $this->assertSame(['database'], (new FriendFinishedDay($friend))->via($me));
        $this->assertTrue(app(Reminders::class)->hasRoomToday($me));
    }

    public function test_with_the_app_open_an_alert_skips_the_phone_except_a_live_invite(): void
    {
        Carbon::setTestNow(self::TEHRAN_NOON);
        $me = $this->learner();
        $friend = User::factory()->create();
        $me->markInApp();

        $this->assertSame(['database'], (new FriendFinishedDay($friend))->via($me));
        $this->assertContains(DeferredWebPushChannel::class, (new PairPracticeInvite(PairPracticeSession::factory()->create(), $friend))->via($me));
    }

    public function test_opening_a_tapped_alert_marks_its_bell_row_read(): void
    {
        $me = $this->learner();
        $me->notifyNow(new StudyReminder('12:00'), ['database']);
        $row = $me->notifications()->sole();

        $this->actingAs($me)->get(route('home', ['notification' => $row->id]))->assertOk();

        $this->assertNotNull($row->fresh()->read_at);
    }

    public function test_a_tapped_alert_cannot_mark_someone_elses_row(): void
    {
        $other = $this->learner();
        $other->notifyNow(new StudyReminder('12:00'), ['database']);
        $row = $other->notifications()->sole();

        $this->actingAs($this->learner())->get(route('home', ['notification' => $row->id]))->assertOk();

        $this->assertNull($row->fresh()->read_at);
    }

    public function test_a_fresh_alert_pops_up_as_a_banner_and_is_read_once_shown(): void
    {
        $me = $this->learner();
        $friend = User::factory()->create(['name' => 'Sara']);
        $me->notify(new FriendFinishedDay($friend));
        $row = $me->notifications()->sole();
        $this->actingAs($me);

        Livewire::test('notifications.bell')
            ->call('checkNew')
            ->assertDispatched('notification-banner', fn (string $name, array $params) => $params['items'][0]['id'] === $row->id
                && str_contains($params['items'][0]['title'], 'Sara')
                && $params['items'][0]['urgent'] === false)
            ->dispatch('notification-shown', id: $row->id);

        $this->assertNotNull($row->fresh()->read_at);
        $this->assertTrue($me->isInApp());
    }

    public function test_an_old_alert_stays_in_the_bell_without_a_banner(): void
    {
        $me = $this->learner();
        $me->notify(new FriendFinishedDay(User::factory()->create()));
        $this->travel(20)->minutes();
        $this->actingAs($me);

        Livewire::test('notifications.bell')
            ->call('checkNew')
            ->assertNotDispatched('notification-banner');
    }
}
