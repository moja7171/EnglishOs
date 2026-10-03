<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\FollowRequestReceived;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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

    public function test_notifications_that_were_unread_on_open_stay_highlighted(): void
    {
        $me = User::factory()->create();
        $alice = User::factory()->create();
        $me->notify(new FollowRequestReceived($alice));
        $notification = $me->unreadNotifications()->first();

        $this->actingAs($me);

        $component = Livewire::test('notifications.bell')->call('markAllAsRead');

        $this->assertSame([$notification->id], $component->get('freshIds'));
        $this->assertSame(0, $component->instance()->unreadCount());
    }

    public function test_clear_all_deletes_only_the_users_own_notifications(): void
    {
        $me = User::factory()->create();
        $someoneElse = User::factory()->create();
        $alice = User::factory()->create();
        $me->notify(new FollowRequestReceived($alice));
        $someoneElse->notify(new FollowRequestReceived($alice));

        $this->actingAs($me);

        Livewire::test('notifications.bell')
            ->call('clearAll')
            ->assertSee('Nothing yet');

        $this->assertSame(0, $me->notifications()->count());
        $this->assertSame(1, $someoneElse->notifications()->count());
    }

    public function test_prune_command_removes_only_old_read_notifications(): void
    {
        $me = User::factory()->create();
        $alice = User::factory()->create();
        foreach (range(1, 3) as $ignored) {
            $me->notify(new FollowRequestReceived($alice));
        }
        [$oldRead, $recentRead, $oldUnread] = $me->notifications()->get()->all();

        $oldRead->forceFill(['read_at' => now()->subDays(40)])->save();
        $recentRead->forceFill(['read_at' => now()->subDays(2)])->save();

        $this->artisan('notifications:prune-read')->assertSuccessful();

        $remaining = $me->notifications()->pluck('id')->all();
        $this->assertNotContains($oldRead->id, $remaining);
        $this->assertContains($recentRead->id, $remaining);
        $this->assertContains($oldUnread->id, $remaining);
    }

    public function test_accepting_from_the_bell_befriends_and_removes_the_notification(): void
    {
        $me = User::factory()->create();
        $alice = User::factory()->create();
        $alice->follow($me);
        $notification = $me->notifications()->first();

        $this->actingAs($me);

        Livewire::test('notifications.bell')
            ->assertSee('Accept')
            ->call('acceptRequest', $notification->id);

        $this->assertTrue($me->fresh()->canMessageWith($alice));
        $this->assertNull($me->notifications()->find($notification->id));
    }

    public function test_declining_from_the_bell_removes_the_request_and_the_notification(): void
    {
        $me = User::factory()->create();
        $alice = User::factory()->create();
        $alice->follow($me);
        $notification = $me->notifications()->first();

        $this->actingAs($me);

        Livewire::test('notifications.bell')->call('rejectRequest', $notification->id);

        $this->assertSame(0, $me->pendingFollowRequestsCount());
        $this->assertFalse($me->fresh()->canMessageWith($alice));
        $this->assertSame(0, $me->notifications()->count());
    }

    public function test_an_already_answered_request_shows_no_buttons(): void
    {
        $me = User::factory()->create();
        $alice = User::factory()->create();
        $alice->follow($me);
        $me->acceptFollowRequest($alice);

        $this->actingAs($me);

        Livewire::test('notifications.bell')
            ->assertSee("{$alice->name} wants to connect")
            ->assertDontSee('Decline');
    }

    public function test_a_user_cannot_answer_someone_elses_request_notification(): void
    {
        $me = User::factory()->create();
        $victim = User::factory()->create();
        $alice = User::factory()->create();
        $alice->follow($victim);
        $notification = $victim->notifications()->first();

        $this->actingAs($me);

        try {
            Livewire::test('notifications.bell')->call('acceptRequest', $notification->id);
            $this->fail('Expected the foreign notification lookup to fail.');
        } catch (ModelNotFoundException) {
            // Scoped to the signed-in user's own notifications — a 404 in production.
        }

        $this->assertSame(1, $victim->pendingFollowRequestsCount());
        $this->assertSame(1, $victim->notifications()->count());
    }
}
