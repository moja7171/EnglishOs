<?php

namespace Tests\Feature;

use App\Models\DayActivity;
use App\Models\DirectMessage;
use App\Models\Evidence;
use App\Models\FriendBlock;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\PairPracticeSession;
use App\Models\User;
use App\Notifications\PairPracticeNudge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_a_friends_waiting_invite_shows_at_the_top_with_a_one_tap_join(): void
    {
        $session = PairPracticeSession::factory()->waiting()->create();
        [$starter, $friend] = $session->players();

        $this->actingAs($friend);
        Livewire::test('friends.index')
            ->assertSee($starter->name.' is waiting for you')
            ->assertSeeHtml(e(route('pair-practice.show', ['session' => $session, 'join' => 1])));

        $this->actingAs($starter);
        Livewire::test('friends.index')->assertSee('Waiting for '.$friend->name.'…');
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

    private function friendOf(User $me, array $attributes = []): User
    {
        $friend = User::factory()->create($attributes);
        $me->follow($friend);
        $friend->acceptFollowRequest($me);

        return $friend;
    }

    public function test_two_people_with_one_name_show_different_usernames(): void
    {
        $this->actingAs(User::factory()->create());
        $first = User::factory()->create(['name' => 'Ali Rezaei']);
        $second = User::factory()->create(['name' => 'Ali Rezaei']);

        Livewire::test('friends.index')
            ->set('search', 'ali')
            ->assertSee($first->handle())
            ->assertSee($second->handle());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function waysToFindSomeone(): array
    {
        return [
            'username with the @' => ['@ali_tehran'],
            'part of the username' => ['tehran'],
            'name in another case' => ['SEYED'],
            'name with spaces like the username' => ['ali tehran'],
            'Arabic letters for a Persian name' => ['علي'],
        ];
    }

    #[DataProvider('waysToFindSomeone')]
    public function test_a_person_is_found_by_name_or_username_however_it_is_typed(string $term): void
    {
        $this->actingAs(User::factory()->create());
        User::factory()->create(['name' => 'Seyed علی', 'username' => 'ali_tehran']);

        Livewire::test('friends.index')
            ->set('search', $term)
            ->assertSee('@ali_tehran');
    }

    public function test_an_underscore_in_the_search_is_not_a_wildcard(): void
    {
        $this->actingAs(User::factory()->create());
        User::factory()->create(['name' => 'Xena', 'username' => 'alixtehran']);

        Livewire::test('friends.index')
            ->set('search', 'ali_tehran')
            ->assertDontSee('Xena');
    }

    public function test_someone_hidden_from_search_stays_hidden_even_by_exact_username(): void
    {
        $this->actingAs(User::factory()->create());
        User::factory()->create(['name' => 'Private Person', 'username' => 'private_person', 'discoverable' => false]);

        Livewire::test('friends.index')
            ->set('search', '@private_person')
            ->assertDontSee('Private Person');
    }

    public function test_my_friends_come_first_and_open_the_chat_instead_of_a_follow_button(): void
    {
        $me = User::factory()->create();
        $friend = $this->friendOf($me, ['name' => 'Sara Friend', 'discoverable' => false]);
        User::factory()->create(['name' => 'Sara Stranger']);
        $this->actingAs($me);

        Livewire::test('friends.index')
            ->set('search', 'sara')
            ->assertSeeInOrder(['Your friends', 'Sara Friend', 'Add new', 'Sara Stranger'])
            ->assertSeeHtml(route('friends.conversation', $friend));
    }

    public function test_new_people_are_ranked_by_exact_username_then_shared_friends_then_name(): void
    {
        $me = User::factory()->create();
        $friend = $this->friendOf($me);
        User::factory()->create(['name' => 'Aaa Reza']);
        $shared = User::factory()->create(['name' => 'Zzz Reza']);
        $shared->follow($friend);
        $friend->acceptFollowRequest($shared);
        User::factory()->create(['name' => 'Zzzz Other', 'username' => 'reza']);
        $this->actingAs($me);

        Livewire::test('friends.index')
            ->set('search', 'reza')
            ->assertSeeInOrder(['Zzzz Other', 'Zzz Reza', '1 mutual friend', 'Aaa Reza']);
    }

    public function test_search_results_grow_with_show_more_and_start_over_on_a_new_search(): void
    {
        $this->actingAs(User::factory()->create());
        User::factory()->count(21)->sequence(fn ($sequence) => ['name' => sprintf('Reza %02d', $sequence->index)])->create();

        Livewire::test('friends.index')
            ->set('search', 'reza')
            ->assertSee('Reza 19')
            ->assertDontSee('Reza 20')
            ->call('showMoreResults')
            ->assertSee('Reza 20')
            ->assertDontSee('Show more')
            ->set('search', 'rez')
            ->assertSet('searchLimit', 20);
    }

    public function test_a_long_friend_list_starts_short_but_never_hides_an_unread_chat(): void
    {
        $me = User::factory()->create();
        $friends = collect(range(1, 22))->map(fn (int $number) => $this->friendOf($me, ['name' => sprintf('Friend %02d', $number)]));
        $this->actingAs($me);

        DirectMessage::create(['sender_id' => $friends[21]->id, 'recipient_id' => $me->id, 'type' => DirectMessage::TYPE_MESSAGE, 'body' => 'Still waiting']);
        $friends->take(21)->each(fn (User $friend) => DirectMessage::create(['sender_id' => $me->id, 'recipient_id' => $friend->id, 'type' => DirectMessage::TYPE_MESSAGE, 'body' => 'Hello']));

        Livewire::test('friends.index')
            ->assertSee('Friend 22')
            ->assertDontSee('Friend 01')
            ->assertSee('Show more (1)')
            ->call('showMoreFriends')
            ->assertSee('Friend 01');
    }

    public function test_the_today_strip_shows_only_friends_who_practised(): void
    {
        $me = User::factory()->create();
        $this->friendOf($me);
        $this->actingAs($me);

        Livewire::test('friends.index')->assertDontSee('aria-label="Today"', false);
    }

    public function test_the_lesson_friend_picker_lists_the_latest_partner_first_with_usernames(): void
    {
        $me = User::factory()->create();
        $this->friendOf($me, ['name' => 'Aaa Never']);
        $partner = $this->friendOf($me, ['name' => 'Zzz Partner']);
        PairPracticeSession::factory()->create(['user_a_id' => $me->id, 'user_b_id' => $partner->id]);
        $this->actingAs($me);

        $this->blade('<x-friend-picker label="Practice" :href-for="fn ($friend) => \'/friend\'" />')
            ->assertSeeInOrder(['Zzz Partner', $partner->handle(), 'Aaa Never']);
    }
}
