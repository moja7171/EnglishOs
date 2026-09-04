<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\FollowRequestReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationBellTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unread_notification_shows_in_the_badge_count(): void
    {
        $me = User::factory()->create();
        $alice = User::factory()->create();
        $me->notify(new FollowRequestReceived($alice));

        $this->actingAs($me);

        $component = Livewire::test('notifications.bell')
            ->assertSee("{$alice->name} wants to connect");

        $this->assertSame(1, $component->instance()->unreadCount());
    }

    public function test_no_badge_when_there_is_nothing_unread(): void
    {
        $me = User::factory()->create();
        $this->actingAs($me);

        $component = Livewire::test('notifications.bell')->assertSee('Nothing yet');

        $this->assertSame(0, $component->instance()->unreadCount());
    }

    public function test_opening_the_bell_marks_every_notification_read(): void
    {
        $me = User::factory()->create();
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $me->notify(new FollowRequestReceived($alice));
        $me->notify(new FollowRequestReceived($bob));

        $this->actingAs($me);

        $component = Livewire::test('notifications.bell');
        $this->assertSame(2, $component->instance()->unreadCount());

        $component->call('markAllAsRead');

        $this->assertSame(0, $component->instance()->unreadCount());
        $this->assertSame(0, $me->fresh()->unreadNotifications()->count());
    }

    public function test_a_users_own_notifications_never_leak_to_another_user(): void
    {
        $me = User::factory()->create();
        $someoneElse = User::factory()->create();
        $alice = User::factory()->create();
        $someoneElse->notify(new FollowRequestReceived($alice));

        $this->actingAs($me);

        Livewire::test('notifications.bell')->assertDontSee("{$alice->name} wants to connect");
    }
}
