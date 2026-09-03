<?php

namespace Tests\Feature;

use App\Models\DirectMessage;
use App\Models\Evidence;
use App\Models\FriendBlock;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use App\Services\GeminiClient;
use App\Services\GroqClient;
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
        $bob->acceptFollowRequest($me);

        $this->actingAs($me);

        $this->get(route('friends.conversation', $bob))->assertOk();
    }

    public function test_sending_a_message_appears_in_both_users_threads(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->acceptFollowRequest($me);

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

    public function test_the_emoji_picker_is_wired_with_the_curated_set(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->acceptFollowRequest($me);

        $this->actingAs($me);

        Livewire::test('friends.conversation', ['other' => $bob])
            ->assertSeeHtml('showEmoji = !showEmoji')
            ->assertSee('😀')
            ->assertSeeHtml("\$wire.body = \$wire.body + '😀'");
    }

    public function test_read_receipts_reflect_whether_the_message_was_read(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->acceptFollowRequest($me);

        $unread = DirectMessage::create(['sender_id' => $me->id, 'recipient_id' => $bob->id, 'type' => DirectMessage::TYPE_MESSAGE, 'body' => 'Unread one.']);
        $read = DirectMessage::create(['sender_id' => $me->id, 'recipient_id' => $bob->id, 'type' => DirectMessage::TYPE_MESSAGE, 'body' => 'Already read.', 'read_at' => now()]);

        $this->actingAs($me);

        $html = Livewire::test('friends.conversation', ['other' => $bob])->html();

        $this->assertStringContainsString('title="Sent"', $html);
        $this->assertStringContainsString('title="Read"', $html);
    }

    public function test_consecutive_messages_from_the_same_sender_are_visually_grouped(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->acceptFollowRequest($me);

        DirectMessage::create(['sender_id' => $me->id, 'recipient_id' => $bob->id, 'type' => DirectMessage::TYPE_MESSAGE, 'body' => 'First.']);
        DirectMessage::create(['sender_id' => $me->id, 'recipient_id' => $bob->id, 'type' => DirectMessage::TYPE_MESSAGE, 'body' => 'Second, right after.']);

        $this->actingAs($me);

        $html = Livewire::test('friends.conversation', ['other' => $bob])->html();

        // The second consecutive message from the same sender gets the
        // tighter "grouped" spacing, not the normal turn spacing.
        $this->assertStringContainsString('mt-0.5', $html);
    }

    public function test_the_header_shows_an_avatar_initial_and_the_others_streak(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create(['name' => 'Priya']);
        $me->follow($bob);
        $bob->acceptFollowRequest($me);

        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'Outcome.',
            'phases' => [],
        ]);
        Evidence::create([
            'mission_run_id' => MissionRun::findOrStart($bob, $mission)->id,
            'phase' => 'mission_brief',
            'type' => Evidence::TYPE_SCORE,
            'content_ref' => '3',
        ]);

        $this->actingAs($me);

        Livewire::test('friends.conversation', ['other' => $bob])
            ->assertSee('P') // avatar initial
            ->assertSee('1-day streak');
    }

    public function test_a_prefill_query_param_pre_fills_the_composer(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->acceptFollowRequest($me);

        $this->actingAs($me);

        Livewire::withQueryParams(['prefill' => 'Hey — want to help me practice this: "What time do you usually wake up?"'])
            ->test('friends.conversation', ['other' => $bob])
            ->assertSet('body', 'Hey — want to help me practice this: "What time do you usually wake up?"');
    }

    public function test_an_overlong_prefill_is_capped(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->acceptFollowRequest($me);

        $this->actingAs($me);

        Livewire::withQueryParams(['prefill' => str_repeat('a', 600)])
            ->test('friends.conversation', ['other' => $bob])
            ->assertSet('body', fn ($body) => strlen($body) <= 503); // Str::limit adds an ellipsis
    }

    public function test_a_blank_message_is_a_no_op(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->acceptFollowRequest($me);

        $this->actingAs($me);

        Livewire::test('friends.conversation', ['other' => $bob])
            ->set('body', '   ')
            ->call('send');

        $this->assertDatabaseCount('direct_messages', 0);
    }

    public function test_sending_a_nudge_falls_back_to_a_preset_when_the_ai_call_fails(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->acceptFollowRequest($me);

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andThrow(new \RuntimeException('down')));

        $this->actingAs($me);

        Livewire::test('friends.conversation', ['other' => $bob])
            ->call('sendNudge')
            ->assertSee('Come practice with me today!');

        $this->assertDatabaseHas('direct_messages', [
            'sender_id' => $me->id,
            'recipient_id' => $bob->id,
            'type' => DirectMessage::TYPE_NUDGE,
            'body' => 'Come practice with me today!',
        ]);
    }

    public function test_a_streak_holder_gets_the_streak_preset_as_a_fallback(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->acceptFollowRequest($me);

        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'Outcome.',
            'phases' => [],
        ]);
        Evidence::create([
            'mission_run_id' => MissionRun::findOrStart($bob, $mission)->id,
            'phase' => 'mission_brief',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => '{}',
        ]);

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andThrow(new \RuntimeException('down')));

        $this->actingAs($me);

        Livewire::test('friends.conversation', ['other' => $bob])
            ->call('sendNudge')
            ->assertSee('Keep your 1-day streak going today!');
    }

    public function test_sending_a_nudge_uses_the_ai_generated_message_grounded_in_the_real_streak(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->acceptFollowRequest($me);

        $this->mock(GeminiClient::class, function ($mock) use ($me) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(fn ($messages, $systemPrompt) => str_contains($messages[0]['text'], "doesn't have an active practice streak")
                    && str_contains($systemPrompt, $me->name))
                ->andReturn('Missed you today — come get some practice in! 😊');
        });

        $this->actingAs($me);

        Livewire::test('friends.conversation', ['other' => $bob])
            ->call('sendNudge')
            ->assertSee('Missed you today — come get some practice in!');

        $this->assertDatabaseHas('direct_messages', [
            'sender_id' => $me->id,
            'recipient_id' => $bob->id,
            'type' => DirectMessage::TYPE_NUDGE,
            'body' => 'Missed you today — come get some practice in! 😊',
        ]);
    }

    public function test_receiving_a_message_marks_it_read_once_viewed(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->acceptFollowRequest($me);

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
        $bob->acceptFollowRequest($me);

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
        $bob->acceptFollowRequest($me);

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
        $bob->acceptFollowRequest($me);

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
        $bob->acceptFollowRequest($me);

        $this->actingAs($me);

        Livewire::test('friends.conversation', ['other' => $bob])
            ->set('voiceMessage', UploadedFile::fake()->create('voice-message.webm', 500, 'audio/webm'))
            ->call('sendVoiceMessage');

        $message = DirectMessage::where('type', DirectMessage::TYPE_AUDIO)->firstOrFail();
        Storage::disk('local')->assertExists($message->attachment_path);
        Storage::disk('public')->assertMissing($message->attachment_path);
    }

    public function test_a_voice_message_is_transcribed_and_stored_as_its_body(): void
    {
        Storage::fake('local');

        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->acceptFollowRequest($me);

        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->once()->andReturn('See you at the park tomorrow.'));

        $this->actingAs($me);

        Livewire::test('friends.conversation', ['other' => $bob])
            ->set('voiceMessage', UploadedFile::fake()->create('voice-message.webm', 500, 'audio/webm'))
            ->call('sendVoiceMessage')
            ->assertSee('See you at the park tomorrow.');

        $this->assertDatabaseHas('direct_messages', [
            'type' => DirectMessage::TYPE_AUDIO,
            'body' => 'See you at the park tomorrow.',
        ]);
    }

    public function test_a_failed_transcription_falls_back_to_a_generic_label(): void
    {
        Storage::fake('local');

        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->acceptFollowRequest($me);

        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->once()->andThrow(new \RuntimeException('down')));

        $this->actingAs($me);

        Livewire::test('friends.conversation', ['other' => $bob])
            ->set('voiceMessage', UploadedFile::fake()->create('voice-message.webm', 500, 'audio/webm'))
            ->call('sendVoiceMessage');

        $this->assertDatabaseHas('direct_messages', [
            'type' => DirectMessage::TYPE_AUDIO,
            'body' => 'Voice message',
        ]);
    }

    public function test_ai_feedback_requires_at_least_one_message_of_my_own(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->acceptFollowRequest($me);

        DirectMessage::create(['sender_id' => $bob->id, 'recipient_id' => $me->id, 'type' => DirectMessage::TYPE_MESSAGE, 'body' => 'Hey!']);

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldNotReceive('chat'));

        $this->actingAs($me);

        Livewire::test('friends.conversation', ['other' => $bob])
            ->call('generateFeedback')
            ->assertSee("there's nothing to give feedback on yet");
    }

    public function test_ai_feedback_judges_only_my_own_messages(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->acceptFollowRequest($me);

        DirectMessage::create(['sender_id' => $me->id, 'recipient_id' => $bob->id, 'type' => DirectMessage::TYPE_MESSAGE, 'body' => 'I goes to school every day.']);
        DirectMessage::create(['sender_id' => $bob->id, 'recipient_id' => $me->id, 'type' => DirectMessage::TYPE_MESSAGE, 'body' => 'Nice, me too.']);
        DirectMessage::create(['sender_id' => $me->id, 'recipient_id' => $bob->id, 'type' => DirectMessage::TYPE_NUDGE, 'body' => 'Keep it up!']);

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(fn ($messages, $systemPrompt) => str_contains($messages[0]['text'], 'Me: I goes to school every day.')
                    && str_contains($messages[0]['text'], 'Nice, me too.')
                    && ! str_contains($messages[0]['text'], 'Keep it up!')
                    && str_contains($systemPrompt, 'ONLY on "Me"'))
                ->andReturn(json_encode([
                    'strength' => 'You wrote a clear, complete sentence.',
                    'expression' => 'every day',
                    'correction' => '"I goes" should be "I go".',
                ]));
        });

        $this->actingAs($me);

        Livewire::test('friends.conversation', ['other' => $bob])
            ->call('generateFeedback')
            ->assertSee('"I goes" should be "I go".');
    }

    public function test_a_failed_ai_feedback_call_shows_a_friendly_message(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->acceptFollowRequest($me);

        DirectMessage::create(['sender_id' => $me->id, 'recipient_id' => $bob->id, 'type' => DirectMessage::TYPE_MESSAGE, 'body' => 'Hello!']);

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andThrow(new \RuntimeException('down')));

        $this->actingAs($me);

        Livewire::test('friends.conversation', ['other' => $bob])
            ->call('generateFeedback')
            ->assertSee("Couldn't get feedback from the AI Instructor");
    }

    public function test_sending_a_file_attaches_it_with_its_original_name(): void
    {
        Storage::fake('local');

        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->acceptFollowRequest($me);

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
        $bob->acceptFollowRequest($me);

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
        $bob->acceptFollowRequest($me);

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
