<?php

namespace Tests\Feature;

use App\Models\DirectMessage;
use App\Models\User;
use App\Notifications\Channels\DeferredWebPushChannel;
use App\Notifications\DirectMessageReceived;
use App\Notifications\FollowRequestReceived;
use App\Notifications\PushTest;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Minishlink\WebPush\MessageSentReport;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\TestCase;

class PhoneAlertsTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://push.example.test/send/abc123';

    private function directMessage(User $from, User $to): DirectMessage
    {
        return DirectMessage::create([
            'sender_id' => $from->id,
            'recipient_id' => $to->id,
            'type' => DirectMessage::TYPE_MESSAGE,
            'body' => 'hello',
        ]);
    }

    private function report(bool $success, int $status = 201, string $reason = 'OK'): MessageSentReport
    {
        return new MessageSentReport(new Request('POST', self::ENDPOINT), new Response($status), $success, $reason);
    }

    public function test_a_notification_goes_to_the_bell_and_the_phone(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->assertSame(
            ['database', DeferredWebPushChannel::class],
            (new FollowRequestReceived($alice))->via($bob),
        );
    }

    public function test_the_push_repeats_the_bell_text_and_opens_the_same_page(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $notification = new FollowRequestReceived($alice);

        $payload = $notification->toWebPush($bob, $notification)->toArray();

        $this->assertSame("{$alice->name} wants to connect", $payload['body']);
        $this->assertSame(['url' => route('friends.index')], $payload['data']);
        $this->assertArrayNotHasKey('tag', $payload);
    }

    public function test_messages_from_one_sender_share_a_tag_so_they_collapse_on_the_lock_screen(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $notification = new DirectMessageReceived($this->directMessage($alice, $bob));

        $payload = $notification->toWebPush($bob, $notification)->toArray();

        $this->assertSame("dm-{$alice->id}", $payload['tag']);
        $this->assertTrue($payload['renotify']);
    }

    public function test_the_push_is_sent_after_the_request_not_during_it(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->mock(WebPushChannel::class)->shouldReceive('send')->once()->with($bob, \Mockery::type(FollowRequestReceived::class))->andReturn([]);

        $bob->notify(new FollowRequestReceived($alice));

        $this->assertCount(1, $bob->notifications, 'the bell row is written immediately');

        defer()->invoke();
    }

    public function test_a_failing_push_never_breaks_the_notification(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->mock(WebPushChannel::class)->shouldReceive('send')->andThrow(new \RuntimeException('push service unreachable'));

        $bob->notify(new FollowRequestReceived($alice));
        defer()->invoke();

        $this->assertCount(1, $bob->notifications);
    }

    public function test_a_device_subscription_is_saved_for_the_signed_in_user(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('notifications.push-toggle')
            ->call('savePushSubscription', self::ENDPOINT, 'public-key', 'auth-token', 'aes128gcm')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('push_subscriptions', [
            'subscribable_id' => $user->id,
            'endpoint' => self::ENDPOINT,
            'public_key' => 'public-key',
        ]);
    }

    public function test_saving_the_same_device_twice_keeps_one_subscription(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('notifications.push-toggle')
            ->call('savePushSubscription', self::ENDPOINT, 'old-key', 'auth-token', 'aes128gcm')
            ->call('savePushSubscription', self::ENDPOINT, 'new-key', 'auth-token', 'aes128gcm');

        $this->assertSame(1, $user->pushSubscriptions()->count());
        $this->assertSame('new-key', $user->pushSubscriptions()->first()->public_key);
    }

    public function test_a_shared_device_moves_to_whoever_signed_in_last(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $first->updatePushSubscription(self::ENDPOINT, 'key', 'token', 'aes128gcm');

        $this->actingAs($second);
        Livewire::test('notifications.push-toggle')
            ->call('savePushSubscription', self::ENDPOINT, 'key', 'token', 'aes128gcm');

        $this->assertSame(0, $first->pushSubscriptions()->count());
        $this->assertSame(1, $second->pushSubscriptions()->count());
    }

    public function test_only_https_endpoints_are_accepted(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('notifications.push-toggle')
            ->call('savePushSubscription', 'http://push.example.test/abc', 'key', 'token', 'aes128gcm')
            ->assertHasErrors('endpoint');

        $this->assertSame(0, $user->pushSubscriptions()->count());
    }

    public function test_turning_alerts_off_removes_only_that_device(): void
    {
        $user = User::factory()->create();
        $user->updatePushSubscription(self::ENDPOINT, 'key', 'token', 'aes128gcm');
        $user->updatePushSubscription('https://push.example.test/send/other', 'key', 'token', 'aes128gcm');
        $this->actingAs($user);

        Livewire::test('notifications.push-toggle')->call('removePushSubscription', self::ENDPOINT);

        $this->assertSame(['https://push.example.test/send/other'], $user->pushSubscriptions()->pluck('endpoint')->all());
    }

    public function test_one_user_cannot_remove_another_users_device(): void
    {
        $owner = User::factory()->create();
        $owner->updatePushSubscription(self::ENDPOINT, 'key', 'token', 'aes128gcm');
        $this->actingAs(User::factory()->create());

        Livewire::test('notifications.push-toggle')->call('removePushSubscription', self::ENDPOINT);

        $this->assertSame(1, $owner->pushSubscriptions()->count());
    }

    public function test_the_test_button_needs_a_subscribed_device(): void
    {
        $this->actingAs(User::factory()->create());
        $this->mock(WebPushChannel::class)->shouldNotReceive('send');

        Livewire::test('notifications.push-toggle')
            ->call('sendTest')
            ->assertSet('testFailed', true)
            ->assertSee('turn alerts on first');
    }

    public function test_the_test_button_reports_a_delivered_push(): void
    {
        $user = User::factory()->create();
        $user->updatePushSubscription(self::ENDPOINT, 'key', 'token', 'aes128gcm');
        $this->actingAs($user);

        $this->mock(WebPushChannel::class)
            ->shouldReceive('send')->once()->with($user, \Mockery::type(PushTest::class))
            ->andReturn([$this->report(success: true)]);

        Livewire::test('notifications.push-toggle')
            ->call('sendTest')
            ->assertSet('testFailed', false)
            ->assertSee('Sent');
    }

    public function test_the_test_button_shows_why_a_push_was_refused(): void
    {
        $user = User::factory()->create();
        $user->updatePushSubscription(self::ENDPOINT, 'key', 'token', 'aes128gcm');
        $this->actingAs($user);

        $this->mock(WebPushChannel::class)
            ->shouldReceive('send')->once()
            ->andReturn([$this->report(success: false, status: 502, reason: 'cURL error 28: timed out')]);

        Livewire::test('notifications.push-toggle')
            ->call('sendTest')
            ->assertSet('testFailed', true)
            ->assertSee('cURL error 28');
    }
}
