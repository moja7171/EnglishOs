<?php

namespace Tests\Feature;

use App\Models\DirectMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FriendsInboxBadgeTest extends TestCase
{
    use RefreshDatabase;

    private function befriend(User $a, User $b): void
    {
        $a->follow($b);
        $b->acceptFollowRequest($a);
    }

    private function say(User $from, User $to, string $body, ?string $readAt = null): DirectMessage
    {
        return DirectMessage::create([
            'sender_id' => $from->id,
            'recipient_id' => $to->id,
            'type' => DirectMessage::TYPE_MESSAGE,
            'body' => $body,
            'read_at' => $readAt,
        ]);
    }

    public function test_a_burst_of_unread_messages_counts_as_one_conversation(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $carol = User::factory()->create();
        $this->befriend($me, $bob);
        $this->befriend($me, $carol);

        foreach (['a', 'b', 'c'] as $text) {
            $this->say($bob, $me, $text);
        }
        $this->say($carol, $me, 'hi');

        $this->assertSame(2, $me->unreadConversationCount());
    }

    public function test_messages_from_someone_you_can_no_longer_message_never_light_the_badge(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $carol = User::factory()->create();
        $this->befriend($me, $bob);
        $this->befriend($me, $carol);
        $this->say($bob, $me, 'hi');
        $this->say($carol, $me, 'hello');

        $me->block($bob);
        $carol->unfollow($me);

        $this->assertSame(0, $me->unreadConversationCount());
        $this->assertSame(0, $me->friendsBadgeCount());
    }

    public function test_the_badge_adds_pending_requests_to_unread_conversations(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $dave = User::factory()->create();
        $this->befriend($me, $bob);
        $this->say($bob, $me, 'hi');
        $dave->follow($me);

        $this->assertSame(2, $me->friendsBadgeCount());
    }

    public function test_conversation_summaries_give_the_last_message_and_unread_count_per_friend(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $carol = User::factory()->create();
        $this->befriend($me, $bob);
        $this->befriend($me, $carol);

        $this->say($bob, $me, 'one');
        $this->say($bob, $me, 'two');
        $this->say($me, $bob, 'reply');
        $this->say($carol, $me, 'old', now()->toDateTimeString());
        $last = $this->say($bob, $me, 'latest from bob');

        $summaries = $me->conversationSummaries();

        $this->assertSame($last->id, $summaries[$bob->id]['last']->id);
        $this->assertSame(3, $summaries[$bob->id]['unread']);
        $this->assertSame('old', $summaries[$carol->id]['last']->body);
        $this->assertSame(0, $summaries[$carol->id]['unread']);
        $this->assertCount(2, $summaries);
    }

    public function test_conversation_summaries_use_a_fixed_number_of_queries(): void
    {
        $me = User::factory()->create();

        foreach (range(1, 5) as $ignored) {
            $friend = User::factory()->create();
            $this->befriend($me, $friend);
            $this->say($friend, $me, 'hi');
        }

        DB::enableQueryLog();
        $me->conversationSummaries();

        $this->assertCount(2, DB::getQueryLog());
    }
}
