<?php

namespace Tests\Feature;

use App\Models\DirectMessage;
use App\Models\FriendBlock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FriendSystemModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_following_is_one_directional_until_the_other_side_follows_back(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $alice->follow($bob);

        $this->assertTrue($alice->isFollowing($bob));
        $this->assertFalse($bob->isFollowing($alice));
        $this->assertFalse($alice->isMutualWith($bob));
        $this->assertFalse($alice->canMessageWith($bob));
    }

    public function test_mutual_follow_unlocks_messaging(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $alice->follow($bob);
        $bob->follow($alice);

        $this->assertTrue($alice->isMutualWith($bob));
        $this->assertTrue($alice->canMessageWith($bob));
        $this->assertTrue($bob->canMessageWith($alice));
    }

    public function test_a_user_cannot_message_themselves(): void
    {
        $alice = User::factory()->create();

        $this->assertFalse($alice->canMessageWith($alice));
    }

    public function test_following_yourself_is_a_no_op(): void
    {
        $alice = User::factory()->create();

        $alice->follow($alice);

        $this->assertSame(0, $alice->following()->count());
    }

    public function test_unfollowing_breaks_mutuality_and_closes_messaging(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $alice->follow($bob);
        $bob->follow($alice);
        $this->assertTrue($alice->canMessageWith($bob));

        $alice->unfollow($bob);

        $this->assertFalse($alice->isMutualWith($bob));
        $this->assertFalse($alice->canMessageWith($bob));
        // Bob still follows Alice — that side is untouched.
        $this->assertTrue($bob->isFollowing($alice));
    }

    public function test_a_block_closes_messaging_in_both_directions_even_with_mutual_follow(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $alice->follow($bob);
        $bob->follow($alice);

        FriendBlock::create(['blocker_id' => $bob->id, 'blocked_id' => $alice->id]);

        $this->assertTrue($alice->isMutualWith($bob)); // the follow graph is untouched
        $this->assertFalse($alice->canMessageWith($bob)); // but messaging is closed
        $this->assertFalse($bob->canMessageWith($alice));
        $this->assertTrue($bob->hasBlocked($alice));
        $this->assertTrue($alice->isBlockedBy($bob));
    }

    public function test_conversation_with_returns_every_message_between_the_pair_in_order(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $carol = User::factory()->create();

        DirectMessage::create(['sender_id' => $alice->id, 'recipient_id' => $bob->id, 'body' => 'hi bob']);
        DirectMessage::create(['sender_id' => $bob->id, 'recipient_id' => $alice->id, 'body' => 'hi alice']);
        // Noise from an unrelated conversation — must never leak in.
        DirectMessage::create(['sender_id' => $carol->id, 'recipient_id' => $bob->id, 'body' => 'hi bob from carol']);

        $thread = $alice->conversationWith($bob)->get();

        $this->assertCount(2, $thread);
        $this->assertSame('hi bob', $thread->first()->body);
        $this->assertSame('hi alice', $thread->last()->body);

        // Symmetric — Bob sees the exact same thread looking the other way.
        $this->assertCount(2, $bob->conversationWith($alice)->get());
    }

    public function test_a_nudge_is_just_a_typed_direct_message(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        DirectMessage::create([
            'sender_id' => $alice->id,
            'recipient_id' => $bob->id,
            'type' => DirectMessage::TYPE_NUDGE,
            'body' => 'Keep your streak going!',
        ]);

        $message = $alice->conversationWith($bob)->first();

        $this->assertSame(DirectMessage::TYPE_NUDGE, $message->type);
    }
}
