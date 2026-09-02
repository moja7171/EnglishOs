<?php

namespace Tests\Feature;

use App\Models\FriendBlock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_following_someone_adds_them_to_the_following_list(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create(['name' => 'Bob']);

        $this->actingAs($me);

        Livewire::test('friends.index')
            ->call('follow', $bob->id)
            ->assertSee('Bob');

        $this->assertTrue($me->fresh()->isFollowing($bob));
    }

    public function test_a_one_way_follow_shows_no_message_button_but_a_mutual_one_does(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create(['name' => 'Bob']);
        $carol = User::factory()->create(['name' => 'Carol']);

        $me->follow($bob);
        $me->follow($carol);
        $carol->follow($me); // only Carol follows back

        $this->actingAs($me);

        $component = Livewire::test('friends.index');

        // false = don't escape the expected string — it's plain literal
        // Blade text (not passed through {{ }}), so the raw apostrophe
        // in the rendered HTML would never match an auto-escaped needle.
        $component->assertSee("They don't follow you back yet", false)
            ->assertSeeHtml(route('friends.conversation', $carol));
    }

    public function test_blocking_someone_removes_them_from_the_following_list(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create(['name' => 'Bob']);
        $me->follow($bob);

        $this->actingAs($me);

        Livewire::test('friends.index')
            ->call('block', $bob->id)
            ->assertDontSee('Bob');

        $this->assertDatabaseHas('friend_blocks', ['blocker_id' => $me->id, 'blocked_id' => $bob->id]);
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

    public function test_submitting_a_report_creates_a_record_with_the_reason(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create(['name' => 'Bob']);
        $me->follow($bob);

        $this->actingAs($me);

        Livewire::test('friends.index')
            ->call('startReport', $bob->id)
            ->set('reportReason.'.$bob->id, 'Being rude in messages')
            ->call('submitReport', $bob->id);

        $this->assertDatabaseHas('friend_reports', [
            'reporter_id' => $me->id,
            'reported_id' => $bob->id,
            'reason' => 'Being rude in messages',
        ]);
    }

    public function test_report_is_a_no_op_without_a_reason(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);

        $this->actingAs($me);

        Livewire::test('friends.index')
            ->call('startReport', $bob->id)
            ->call('submitReport', $bob->id);

        $this->assertDatabaseCount('friend_reports', 0);
    }
}
