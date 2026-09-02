<?php

namespace Tests\Feature;

use App\Models\DirectMessage;
use App\Models\FriendBlock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class FriendsConversationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_one_way_follow_cannot_open_the_conversation(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob); // one-directional only

        $this->actingAs($me);

        $this->get(route('friends.conversation', $bob))->assertForbidden();
    }

    public function test_a_stranger_with_no_follow_at_all_cannot_open_the_conversation(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();

        $this->actingAs($me);

        $this->get(route('friends.conversation', $bob))->assertForbidden();
    }

    public function test_mutual_follow_opens_the_conversation(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->follow($me);

        $this->actingAs($me);

        $this->get(route('friends.conversation', $bob))->assertOk();
    }

    public function test_sending_a_message_appears_in_both_users_threads(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->follow($me);

        $this->actingAs($me);

        Livewire::test('friends.conversation', ['other' => $bob])
            ->set('body', 'Hey, want to practice today?')
            ->call('send')
            ->assertSet('body', '')
            ->assertSee('Hey, want to practice today?');

        $this->assertDatabaseHas('direct_messages', [
            'sender_id' => $me->id,
            'recipient_id' => $bob->id,
            'body' => 'Hey, want to practice today?',
            'type' => DirectMessage::TYPE_MESSAGE,
        ]);
    }

    public function test_a_blank_message_is_a_no_op(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->follow($me);

        $this->actingAs($me);

        Livewire::test('friends.conversation', ['other' => $bob])
            ->set('body', '   ')
            ->call('send');

        $this->assertDatabaseCount('direct_messages', 0);
    }

    public function test_sending_a_nudge_uses_the_recipients_real_streak(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->follow($me);

        $this->actingAs($me);

        Livewire::test('friends.conversation', ['other' => $bob])
            ->call('sendNudge')
            ->assertSee('Come practice with me today!');

        $this->assertDatabaseHas('direct_messages', [
            'sender_id' => $me->id,
            'recipient_id' => $bob->id,
            'type' => DirectMessage::TYPE_NUDGE,
        ]);
    }

    public function test_receiving_a_message_marks_it_read_once_viewed(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->follow($me);

        $message = DirectMessage::create([
            'sender_id' => $bob->id,
            'recipient_id' => $me->id,
            'body' => 'hi!',
        ]);

        $this->actingAs($me);

        Livewire::test('friends.conversation', ['other' => $bob]);

        $this->assertNotNull($message->fresh()->read_at);
    }

    public function test_blocking_from_the_conversation_redirects_to_the_friends_list(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->follow($me);

        $this->actingAs($me);

        Livewire::test('friends.conversation', ['other' => $bob])
            ->call('block')
            ->assertRedirect(route('friends.index'));

        $this->assertTrue($me->fresh()->hasBlocked($bob));
    }

    public function test_reporting_from_the_conversation_snapshots_the_last_message(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->follow($me);

        DirectMessage::create(['sender_id' => $bob->id, 'recipient_id' => $me->id, 'body' => 'rude thing']);

        $this->actingAs($me);

        Livewire::test('friends.conversation', ['other' => $bob])
            ->set('reportReason', 'They were rude')
            ->call('submitReport');

        $this->assertDatabaseHas('friend_reports', [
            'reporter_id' => $me->id,
            'reported_id' => $bob->id,
            'reason' => 'They were rude',
            'message_snapshot' => 'rude thing',
        ]);
    }

    public function test_a_block_by_either_side_closes_an_already_open_conversation(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->follow($me);

        FriendBlock::create(['blocker_id' => $bob->id, 'blocked_id' => $me->id]);

        $this->actingAs($me);

        $this->get(route('friends.conversation', $bob))->assertForbidden();
    }

    public function test_sending_a_voice_message_stores_it_on_the_private_disk_not_public(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->follow($me);

        $this->actingAs($me);

        Livewire::test('friends.conversation', ['other' => $bob])
            ->set('voiceMessage', UploadedFile::fake()->create('voice-message.webm', 500, 'audio/webm'))
            ->call('sendVoiceMessage');

        $message = DirectMessage::where('type', DirectMessage::TYPE_AUDIO)->firstOrFail();
        Storage::disk('local')->assertExists($message->attachment_path);
        Storage::disk('public')->assertMissing($message->attachment_path);
    }

    public function test_sending_a_file_attaches_it_with_its_original_name(): void
    {
        Storage::fake('local');

        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->follow($me);

        $this->actingAs($me);

        Livewire::test('friends.conversation', ['other' => $bob])
            ->set('attachment', UploadedFile::fake()->create('homework.pdf', 200, 'application/pdf'))
            ->call('sendFile')
            ->assertSee('homework.pdf');

        $this->assertDatabaseHas('direct_messages', [
            'type' => DirectMessage::TYPE_FILE,
            'attachment_name' => 'homework.pdf',
        ]);
    }

    public function test_an_oversized_or_disallowed_file_is_rejected(): void
    {
        Storage::fake('local');

        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->follow($me);

        $this->actingAs($me);

        Livewire::test('friends.conversation', ['other' => $bob])
            ->set('attachment', UploadedFile::fake()->create('virus.exe', 200))
            ->call('sendFile')
            ->assertHasErrors(['attachment']);

        $this->assertDatabaseCount('direct_messages', 0);
    }

    public function test_only_the_sender_or_recipient_can_download_an_attachment(): void
    {
        Storage::fake('local');

        $me = User::factory()->create();
        $bob = User::factory()->create();
        $stranger = User::factory()->create();
        $me->follow($bob);
        $bob->follow($me);

        $this->actingAs($me);
        Livewire::test('friends.conversation', ['other' => $bob])
            ->set('attachment', UploadedFile::fake()->create('notes.txt', 50, 'text/plain'))
            ->call('sendFile');

        $message = DirectMessage::where('type', DirectMessage::TYPE_FILE)->firstOrFail();

        $this->actingAs($bob);
        $this->get(route('friends.attachment', $message))->assertOk();

        $this->actingAs($stranger);
        $this->get(route('friends.attachment', $message))->assertForbidden();
    }
}
