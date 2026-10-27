<?php

namespace Tests\Feature;

use App\Models\DayActivity;
use App\Models\DirectMessage;
use App\Models\Evidence;
use App\Models\FriendBlock;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use App\Notifications\PairPracticeNudge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class FriendsIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_searching_by_name_finds_other_users_but_never_yourself(): void
    {
        $me = User::factory()->create(['name' => 'Alice']);
        $bob = User::factory()->create(['name' => 'Bob Smith']);

        $this->actingAs($me);

        Livewire::test('friends.index')
            ->set('search', 'bob')
            ->assertSee('Bob Smith')
            ->assertDontSee('Alice');
    }

    public function test_following_someone_queues_a_pending_request_not_an_instant_follow(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create(['name' => 'Bob']);

        $this->actingAs($me);

        Livewire::test('friends.index')
            ->set('search', 'Bob')
            ->call('follow', $bob->id)
            ->assertSee('Requested');

        $this->assertFalse($me->fresh()->isFollowing($bob));
        $this->assertTrue($me->fresh()->hasPendingRequestTo($bob));
    }

    public function test_accepting_a_request_makes_both_sides_mutual_and_shows_a_message_button(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create(['name' => 'Bob']);
        $carol = User::factory()->create(['name' => 'Carol']);

        $me->follow($bob); // still pending — Bob hasn't accepted
        $me->follow($carol);
        $carol->acceptFollowRequest($me); // Carol accepts — both sides become mutual

        $this->actingAs($me);

        $component = Livewire::test('friends.index');

        $component->assertDontSee($bob->name)
            ->assertSeeHtml(route('friends.conversation', $carol));
    }

    public function test_incoming_requests_can_be_accepted_or_rejected(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create(['name' => 'Bob']);
        $carol = User::factory()->create(['name' => 'Carol']);

        $bob->follow($me);
        $carol->follow($me);

        $this->actingAs($me);

        $component = Livewire::test('friends.index')
            ->assertSee('wants to be friends')
            ->call('acceptRequest', $bob->id)
            ->call('rejectRequest', $carol->id);

        $this->assertTrue($me->fresh()->isFollowing($bob));
        $this->assertTrue($bob->fresh()->isFollowing($me));
        $this->assertFalse($carol->fresh()->hasPendingRequestTo($me));
        $this->assertFalse($me->fresh()->isFollowing($carol));

        $component->assertDontSee('wants to be friends');
    }

    public function test_a_blocked_user_never_appears_in_search_either_direction(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create(['name' => 'Bob Blocked']);

        FriendBlock::create(['blocker_id' => $bob->id, 'blocked_id' => $me->id]);

        $this->actingAs($me);

        Livewire::test('friends.index')
            ->set('search', 'Bob')
            ->assertDontSee('Bob Blocked');
    }

    public function test_friends_who_practiced_today_get_a_ring_and_the_rest_a_nudge(): void
    {
        $me = User::factory()->create();
        $mutualActive = User::factory()->create(['name' => 'Ann Active']);
        $mutualInactive = User::factory()->create(['name' => 'Ivy Idle']);
        $oneWay = User::factory()->create();

        $me->follow($mutualActive);
        $mutualActive->acceptFollowRequest($me);
        $me->follow($mutualInactive);
        $mutualInactive->acceptFollowRequest($me);
        $me->follow($oneWay);

        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'Outcome.',
            'phases' => [],
        ]);

        foreach ([$mutualActive, $oneWay] as $user) {
            Evidence::create([
                'mission_run_id' => MissionRun::findOrStart($user, $mission)->id,
                'phase' => 'mission_brief',
                'type' => Evidence::TYPE_SCORE,
                'content_ref' => '3',
            ]);
            DayActivity::factory()->create(['learner_id' => $user->id]); // a 2nd activity keeps today
        }

        $this->actingAs($me);

        Livewire::test('friends.index')
            ->assertSeeInOrder(['practiced today', $mutualActive->name, $mutualInactive->name])
            ->assertSee('Not today')
            ->assertSee('🔥 Nudge');
        $this->assertSame(1, substr_count(Livewire::test('friends.index')->html(), 'practiced today</span>'));
    }

    public function test_no_ring_when_no_mutual_friend_has_practiced(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->acceptFollowRequest($me);

        $this->actingAs($me);

        Livewire::test('friends.index')
            ->assertDontSee('practiced today</span>', false);
    }

    public function test_friends_are_ordered_by_latest_message_with_preview_and_unread_count(): void
    {
        $me = User::factory()->create();
        $alice = User::factory()->create(['name' => 'Alice Quiet']);
        $bob = User::factory()->create(['name' => 'Bob Talker']);
        $carol = User::factory()->create(['name' => 'Carol Recent']);

        foreach ([$alice, $bob, $carol] as $friend) {
            $me->follow($friend);
            $friend->acceptFollowRequest($me);
        }

        DirectMessage::create(['sender_id' => $bob->id, 'recipient_id' => $me->id, 'type' => DirectMessage::TYPE_MESSAGE, 'body' => 'older']);
        DirectMessage::create(['sender_id' => $carol->id, 'recipient_id' => $me->id, 'type' => DirectMessage::TYPE_MESSAGE, 'body' => 'newest from carol']);
        DirectMessage::create(['sender_id' => $carol->id, 'recipient_id' => $me->id, 'type' => DirectMessage::TYPE_MESSAGE, 'body' => 'second unread']);

        $this->actingAs($me);

        Livewire::test('friends.index')
            ->assertSeeInOrder(['Carol Recent', 'Bob Talker', 'Alice Quiet'])
            ->assertSee('second unread')
            ->assertSee('Say hello')
            ->assertSeeHtml('title="2 unread"');
    }

    public function test_one_way_follows_sit_apart_from_the_inbox_without_a_chat_link(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create(['name' => 'Bob Oneway']);
        $me->follow($bob);
        $bob->acceptFollowRequest($me);
        $bob->unfollow($me);

        $this->actingAs($me);

        Livewire::test('friends.index')
            ->assertSee('followed back yet')
            ->assertSee('Waiting')
            ->assertDontSeeHtml(route('friends.conversation', $bob));
    }

    public function test_following_search_results_offer_accept_when_they_already_asked_you(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create(['name' => 'Bob Asked']);
        $bob->follow($me);

        $this->actingAs($me);

        Livewire::test('friends.index')
            ->set('search', 'Bob Asked')
            ->assertSee('Accept request')
            ->call('acceptRequest', $bob->id);

        $this->assertTrue($me->fresh()->canMessageWith($bob));
    }

    public function test_unfollowing_removes_the_friend(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->acceptFollowRequest($me);

        $this->actingAs($me);

        Livewire::test('friends.index')->call('unfollow', $bob->id);

        $this->assertFalse($me->fresh()->isFollowing($bob));
        $this->assertFalse($me->fresh()->canMessageWith($bob));
    }

    public function test_a_friend_can_be_nudged_once_a_day(): void
    {
        Notification::fake();
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->acceptFollowRequest($me);

        $this->actingAs($me);

        Livewire::test('friends.index')
            ->call('nudge', $bob->id)
            ->call('nudge', $bob->id)
            ->assertSee('✓ Nudged');

        Notification::assertSentToTimes($bob, PairPracticeNudge::class, 1);
    }

    public function test_add_friends_offers_follow_back_to_people_who_follow_you(): void
    {
        $me = User::factory()->create();
        $kian = User::factory()->create(['name' => 'Kian Follower']);
        $kian->follow($me);
        $me->acceptFollowRequest($kian);
        $me->unfollow($kian);

        $this->actingAs($me);

        Livewire::test('friends.index')
            ->assertSee('They follow you')
            ->assertSee('Follow back')
            ->call('follow', $kian->id)
            ->assertDontSee('Follow back');
    }
}
