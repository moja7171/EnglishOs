<?php

namespace Tests\Feature;

use App\Models\DirectMessage;
use App\Models\User;
use App\Notifications\DirectMessageReceived;
use App\Services\ChatAlerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\TestCase;

class ChatAlertsTest extends TestCase
{
    use RefreshDatabase;

    private const AWAKE = '2026-11-04 08:00:00';

    private const NIGHT = '2026-11-04 21:00:00';

    private User $sender;

    private User $recipient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(self::AWAKE);
        $this->sender = User::factory()->create();
        $this->recipient = User::factory()->create(['timezone' => 'Asia/Tehran']);
    }

    private function send(): void
    {
        app(ChatAlerts::class)->deliver(DirectMessage::create([
            'sender_id' => $this->sender->id,
            'recipient_id' => $this->recipient->id,
            'type' => DirectMessage::TYPE_MESSAGE,
            'body' => 'hello',
        ]), $this->recipient);
    }

    private function expectPushes(int $times): void
    {
        $this->mock(WebPushChannel::class)->shouldReceive('send')->times($times)->andReturn([]);
    }

    private function row()
    {
        return $this->recipient->notifications()->where('type', DirectMessageReceived::class)->sole();
    }

    public function test_a_first_message_buzzes_and_is_not_held(): void
    {
        $this->expectPushes(1);

        $this->send();
        defer()->invoke();

        $this->assertArrayNotHasKey('push_held', $this->row()->data);
    }

    public function test_a_burst_buzzes_once_and_again_after_the_pause_on_the_same_bell_row(): void
    {
        $this->expectPushes(2);

        $this->send();
        $this->send();
        $this->send();
        defer()->invoke();

        $this->travel(ChatAlerts::REPEAT_MINUTES + 1)->minutes();
        $this->send();
        defer()->invoke();

        $this->assertNotNull($this->row());
    }

    public function test_the_repeat_push_marks_the_same_row_read_when_tapped(): void
    {
        $notification = null;
        $this->mock(WebPushChannel::class)->shouldReceive('send')->andReturnUsing(function ($notifiable, $sent) use (&$notification) {
            $notification = $sent;

            return [];
        });

        $this->send();
        $this->travel(ChatAlerts::REPEAT_MINUTES + 1)->minutes();
        $this->send();
        defer()->invoke();

        $this->assertSame($this->row()->id, $notification->id);
    }

    public function test_a_message_while_the_app_is_open_waits_and_buzzes_once_after_leaving(): void
    {
        $this->expectPushes(1);
        $this->recipient->markInApp();

        $this->send();
        $this->assertTrue($this->row()->data['push_held']);

        $this->artisan('chat:send-held-alerts')->assertSuccessful();
        $this->assertTrue($this->row()->data['push_held'], 'still in the app');

        $this->recipient->markLeftApp();
        $this->artisan('chat:send-held-alerts')->assertSuccessful();
        $this->artisan('chat:send-held-alerts')->assertSuccessful();
        defer()->invoke();

        $this->assertArrayNotHasKey('push_held', $this->row()->data);
    }

    public function test_a_later_message_is_not_silenced_by_an_earlier_one_that_was_held(): void
    {
        $this->expectPushes(1);
        $this->recipient->markInApp();
        $this->send();

        $this->recipient->markLeftApp();
        $this->send();
        defer()->invoke();

        $this->assertArrayNotHasKey('push_held', $this->row()->data);
    }

    public function test_a_message_in_quiet_hours_waits_for_morning(): void
    {
        $this->expectPushes(1);
        $this->travelTo(self::NIGHT);
        $this->send();

        $this->artisan('chat:send-held-alerts')->assertSuccessful();
        $this->assertTrue($this->row()->data['push_held']);

        $this->travelTo('2026-11-05 05:00:00');
        $this->artisan('chat:send-held-alerts')->assertSuccessful();
        defer()->invoke();

        $this->assertArrayNotHasKey('push_held', $this->row()->data);
    }

    public function test_a_held_message_read_in_the_meantime_never_buzzes(): void
    {
        $this->expectPushes(0);
        $this->recipient->markInApp();
        $this->send();
        $this->recipient->markLeftApp();

        $this->row()->markAsRead();

        $this->artisan('chat:send-held-alerts')->assertSuccessful();
        defer()->invoke();
    }

    public function test_a_held_message_gone_stale_never_buzzes(): void
    {
        $this->expectPushes(0);
        $this->recipient->markInApp();
        $this->send();
        $this->recipient->markLeftApp();

        $this->travel(ChatAlerts::HELD_FOR_HOURS + 1)->hours();

        $this->artisan('chat:send-held-alerts')->assertSuccessful();
        defer()->invoke();
    }

    public function test_a_muted_sender_stays_silent_even_if_muted_after_the_message_was_held(): void
    {
        $this->expectPushes(0);
        $this->recipient->markInApp();
        $this->send();
        $this->recipient->markLeftApp();

        $this->recipient->following()->attach($this->sender->id, ['status' => 'accepted', 'muted_at' => now()]);

        $this->artisan('chat:send-held-alerts')->assertSuccessful();
        defer()->invoke();

        $this->assertArrayNotHasKey('push_held', $this->row()->data);
    }

    public function test_going_to_the_background_ends_being_in_the_app_at_once(): void
    {
        $this->recipient->markInApp();

        $this->actingAs($this->recipient)->post(route('app.hidden'))->assertNoContent();

        $this->assertFalse($this->recipient->isInApp());
        $this->assertFalse(Cache::has('in-app:'.$this->recipient->id));
    }

    public function test_reporting_the_background_needs_a_session(): void
    {
        $this->post(route('app.hidden'))->assertRedirect(route('welcome'));
    }

    public function test_only_the_signed_in_learner_is_marked_as_gone(): void
    {
        $this->recipient->markInApp();

        $this->actingAs($this->sender)->post(route('app.hidden'))->assertNoContent();

        $this->assertTrue($this->recipient->isInApp());
    }
}
