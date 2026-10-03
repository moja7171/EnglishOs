<?php

namespace Tests\Feature;

use App\Models\DirectMessage;
use App\Models\Evidence;
use App\Models\FriendBlock;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use App\Notifications\DirectMessageReceived;
use App\Services\GeminiClient;
use App\Services\GroqClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
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

    public function test_sending_a_message_notifies_the_recipient(): void
    {
        Notification::fake();

        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->acceptFollowRequest($me);

        $this->actingAs($me);

        Livewire::test('friends.conversation', ['other' => $bob])
            ->set('body', 'Hey, want to practice today?')
            ->call('send');

        Notification::assertSentTo($bob, DirectMessageReceived::class);
        Notification::assertNotSentTo($me, DirectMessageReceived::class);
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
            ->call('draftNudge')
            ->call('send')
            ->assertSee('Come practice with me today!');

        $this->assertDatabaseHas('direct_messages', [
            'sender_id' => $me->id,
            'recipient_id' => $bob->id,
            'type' => DirectMessage::TYPE_NUDGE,
            'body' => 'Come practice with me today!',
        ]);
    }

    public function test_sending_a_nudge_notifies_the_recipient_too(): void
    {
        Notification::fake();

        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->acceptFollowRequest($me);

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andThrow(new \RuntimeException('down')));

        $this->actingAs($me);

        Livewire::test('friends.conversation', ['other' => $bob])->call('draftNudge')->call('send');

        Notification::assertSentTo($bob, DirectMessageReceived::class);
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
            ->call('draftNudge')
            ->call('send')
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
            ->call('draftNudge')
            ->call('send')
            ->assertSee('Missed you today — come get some practice in!');

        $this->assertDatabaseHas('direct_messages', [
            'sender_id' => $me->id,
            'recipient_id' => $bob->id,
            'type' => DirectMessage::TYPE_NUDGE,
            'body' => 'Missed you today — come get some practice in! 😊',
        ]);
    }

    public function test_the_task_card_offers_questions_from_the_learners_current_mission(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create(['name' => 'Bob Smith']);
        $me->follow($bob);
        $bob->acceptFollowRequest($me);

        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'Outcome.',
            'phases' => [[
                'phase' => 'mission',
                'steps' => [[
                    'key' => 'ai_conversation_1',
                    'interview_questions' => ['What time do you wake up?', 'What do you eat for breakfast?', 'How do you get to work?'],
                ]],
            ]],
        ]);
        MissionRun::findOrStart($me, $mission);

        $this->actingAs($me);

        Livewire::test('friends.conversation', ['other' => $bob])
            ->assertSet('starterTitle', 'My Daily Life')
            ->assertCount('starterQuestions', 3)
            ->assertSee('What time do you wake up?')
            ->assertSee('How do you get to work?')
            ->assertSee('Task: interview')
            ->assertSee('about Bob Smith');
    }

    public function test_the_task_card_falls_back_to_generic_questions_without_a_mission_in_progress(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->acceptFollowRequest($me);

        $this->actingAs($me);

        Livewire::test('friends.conversation', ['other' => $bob])
            ->assertSet('starterTitle', null)
            ->assertCount('starterQuestions', 3)
            ->assertSee('Need something to talk about?');
    }

    public function test_a_recording_waits_for_a_preview_and_can_be_discarded_without_sending(): void
    {
        Storage::fake('local');

        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->acceptFollowRequest($me);

        $this->actingAs($me);

        Livewire::test('friends.conversation', ['other' => $bob])
            ->set('voiceMessage', UploadedFile::fake()->create('voice-message.webm', 500, 'audio/webm'))
            ->assertSee('Send voice message')
            ->assertSee('Record again')
            ->call('discardVoiceMessage')
            ->assertSet('voiceMessage', null)
            ->assertDontSee('Send voice message');

        $this->assertDatabaseCount('direct_messages', 0);
    }

    public function test_the_recipient_sees_a_voice_transcript_only_after_tapping_show_text(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->acceptFollowRequest($me);

        DirectMessage::create([
            'sender_id' => $bob->id,
            'recipient_id' => $me->id,
            'type' => DirectMessage::TYPE_AUDIO,
            'body' => 'I usually wake up at six',
            'attachment_path' => 'direct-messages/x.webm',
            'attachment_name' => 'voice-message.webm',
            'attachment_mime' => 'audio/webm',
        ]);

        $this->actingAs($me);

        Livewire::test('friends.conversation', ['other' => $bob])
            ->assertSee('Show text')
            ->assertSeeHtml('x-show="showText"');
    }

    public function test_a_nudge_is_only_a_draft_until_the_learner_sends_it(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->acceptFollowRequest($me);

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn('Come on, you can do it today!'));

        $this->actingAs($me);

        Livewire::test('friends.conversation', ['other' => $bob])
            ->call('draftNudge')
            ->assertSet('body', 'Come on, you can do it today!')
            ->assertSet('draftIsNudge', true);

        $this->assertDatabaseCount('direct_messages', 0);
    }

    public function test_an_edited_nudge_draft_is_still_sent_as_a_nudge_and_a_cleared_one_is_not(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->acceptFollowRequest($me);

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->andThrow(new \RuntimeException('down')));

        $this->actingAs($me);

        $chat = Livewire::test('friends.conversation', ['other' => $bob])
            ->call('draftNudge')
            ->set('body', 'My own words instead')
            ->call('send')
            ->assertSet('draftIsNudge', false);

        $this->assertDatabaseHas('direct_messages', ['body' => 'My own words instead', 'type' => DirectMessage::TYPE_NUDGE]);

        $chat->call('draftNudge')
            ->set('body', '')
            ->assertSet('draftIsNudge', false)
            ->set('body', 'A normal message')
            ->call('send');

        $this->assertDatabaseHas('direct_messages', ['body' => 'A normal message', 'type' => DirectMessage::TYPE_MESSAGE]);
    }

    public function test_drafting_a_nudge_never_overwrites_what_was_already_typed(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->acceptFollowRequest($me);

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->never());

        $this->actingAs($me);

        Livewire::test('friends.conversation', ['other' => $bob])
            ->set('body', 'half a thought')
            ->call('draftNudge')
            ->assertSet('body', 'half a thought')
            ->assertSet('draftIsNudge', false);
    }

    public function test_nudge_drafts_stop_calling_the_ai_after_five_per_friend(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->acceptFollowRequest($me);

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->times(5)->andReturn('AI nudge'));

        $this->actingAs($me);

        $chat = Livewire::test('friends.conversation', ['other' => $bob]);

        foreach (range(1, 5) as $ignored) {
            $chat->call('draftNudge')->assertSet('body', 'AI nudge')->call('send');
        }

        $chat->call('draftNudge')->assertSet('body', 'Come practice with me today!');
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

    public function test_reporting_from_the_conversation_stores_the_category_details_and_snapshot(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->acceptFollowRequest($me);

        DirectMessage::create(['sender_id' => $bob->id, 'recipient_id' => $me->id, 'body' => 'rude thing']);

        $this->actingAs($me);

        Livewire::test('friends.conversation', ['other' => $bob])
            ->call('startReport')
            ->set('reportCategory', 'rude')
            ->set('reportReason', 'They keep insulting me')
            ->call('submitReport')
            ->assertSet('reporting', false)
            ->assertSet('reportSent', true)
            ->assertSee('Report sent')
            ->assertSee('Also block');

        $this->assertDatabaseHas('friend_reports', [
            'reporter_id' => $me->id,
            'reported_id' => $bob->id,
            'category' => 'rude',
            'reason' => 'They keep insulting me',
            'message_snapshot' => 'rude thing',
        ]);
    }

    public function test_a_report_needs_a_category_but_not_details(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->acceptFollowRequest($me);

        $this->actingAs($me);

        $chat = Livewire::test('friends.conversation', ['other' => $bob])
            ->call('startReport')
            ->set('reportReason', 'details only')
            ->call('submitReport')
            ->assertSet('reporting', true);

        $this->assertDatabaseCount('friend_reports', 0);

        $chat->set('reportCategory', 'spam')->set('reportReason', '')->call('submitReport');

        $this->assertDatabaseHas('friend_reports', ['reported_id' => $bob->id, 'category' => 'spam', 'reason' => 'Spam']);
    }

    public function test_an_unknown_report_category_is_rejected(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->acceptFollowRequest($me);

        $this->actingAs($me);

        Livewire::test('friends.conversation', ['other' => $bob])
            ->set('reportCategory', 'made-up')
            ->call('submitReport');

        $this->assertDatabaseCount('friend_reports', 0);
    }

    public function test_the_report_confirmation_can_block_straight_away(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->acceptFollowRequest($me);

        $this->actingAs($me);

        Livewire::test('friends.conversation', ['other' => $bob])
            ->set('reportCategory', 'rude')
            ->call('submitReport')
            ->call('block')
            ->assertRedirect(route('friends.index'));

        $this->assertTrue($me->fresh()->hasBlocked($bob));
    }

    public function test_the_report_snapshot_is_the_other_persons_message_not_your_own(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->acceptFollowRequest($me);

        DirectMessage::create(['sender_id' => $bob->id, 'recipient_id' => $me->id, 'body' => 'rude thing']);
        DirectMessage::create(['sender_id' => $me->id, 'recipient_id' => $bob->id, 'body' => 'please stop']);
        DirectMessage::create(['sender_id' => $bob->id, 'recipient_id' => $me->id, 'type' => DirectMessage::TYPE_NUDGE, 'body' => 'Keep going!']);

        $this->actingAs($me);

        Livewire::test('friends.conversation', ['other' => $bob])
            ->set('reportCategory', 'rude')
            ->call('submitReport');

        $this->assertDatabaseHas('friend_reports', ['reported_id' => $bob->id, 'message_snapshot' => 'rude thing']);
    }

    public function test_losing_access_mid_conversation_locks_the_composer_and_keeps_the_draft(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->acceptFollowRequest($me);

        $this->actingAs($me);

        $chat = Livewire::test('friends.conversation', ['other' => $bob])
            ->assertDontSee('right now.');

        $bob->block($me);

        $chat->set('body', 'are you there?')
            ->call('send')
            ->assertSet('body', 'are you there?')
            ->assertSee('right now.')
            ->assertDontSeeHtml('wire:poll');

        $this->assertDatabaseCount('direct_messages', 0);
    }

    public function test_a_locked_conversation_does_not_mark_messages_read(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->acceptFollowRequest($me);
        $message = DirectMessage::create(['sender_id' => $bob->id, 'recipient_id' => $me->id, 'body' => 'hi']);

        $this->actingAs($me);

        $chat = Livewire::test('friends.conversation', ['other' => $bob]);
        $message->forceFill(['read_at' => null])->save();

        $bob->unfollow($me);
        $chat->call('$refresh');

        $this->assertNull($message->fresh()->read_at);
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
                    'strength' => 'جمله‌ات کامل و واضح بود.',
                    'expression' => 'every day',
                    'correction' => [
                        'original' => 'I goes to school every day.',
                        'corrected' => 'I go to school every day.',
                        'why' => 'با I فعل بدون s می‌آید.',
                        'suggestion' => 'چند جمله‌ی دیگر با I بنویس.',
                    ],
                ]));
        });

        $this->actingAs($me);

        Livewire::test('friends.conversation', ['other' => $bob])
            ->call('generateFeedback')
            ->assertSee('I goes to school every day.')
            ->assertSee('I go to school every day.')
            ->assertSee('با I فعل بدون s می‌آید.');
    }

    public function test_ai_feedback_can_report_that_there_is_nothing_to_fix(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->acceptFollowRequest($me);

        DirectMessage::create(['sender_id' => $me->id, 'recipient_id' => $bob->id, 'type' => DirectMessage::TYPE_MESSAGE, 'body' => 'I usually wake up at six.']);

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn(json_encode([
            'strength' => 'جمله‌ات درست بود.',
            'expression' => 'usually',
            'correction' => null,
        ])));

        $this->actingAs($me);

        Livewire::test('friends.conversation', ['other' => $bob])
            ->call('generateFeedback')
            ->assertSee('Nothing to fix in your recent messages')
            ->assertDontSee('Something to fix');
    }

    public function test_ai_feedback_only_looks_at_the_most_recent_messages(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->acceptFollowRequest($me);

        foreach (range(1, 45) as $number) {
            DirectMessage::create(['sender_id' => $me->id, 'recipient_id' => $bob->id, 'type' => DirectMessage::TYPE_MESSAGE, 'body' => "line-{$number}-end"]);
        }

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(fn ($messages) => str_contains($messages[0]['text'], 'line-45-end')
                    && str_contains($messages[0]['text'], 'line-6-end')
                    && ! str_contains($messages[0]['text'], 'line-5-end'))
                ->andReturn(json_encode(['strength' => 'خوب', 'expression' => 'ok', 'correction' => null]));
        });

        $this->actingAs($me);

        Livewire::test('friends.conversation', ['other' => $bob])->call('generateFeedback');
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
            ->assertSee('check your English right now', false);
    }

    public function test_dismissing_the_feedback_sheet_clears_it(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->acceptFollowRequest($me);

        $this->actingAs($me);

        // An empty thread short-circuits with a "send a few messages first"
        // error before any AI call — enough to have something to dismiss.
        Livewire::test('friends.conversation', ['other' => $bob])
            ->call('generateFeedback')
            ->assertSet('feedbackError', fn ($error) => $error !== null)
            ->call('dismissFeedback')
            ->assertSet('feedbackError', null)
            ->assertSet('feedback', null);
    }

    public function test_the_composer_is_a_labelled_multiline_box_inside_a_live_region_thread(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create(['name' => 'Bob Smith']);
        $me->follow($bob);
        $bob->acceptFollowRequest($me);

        $this->actingAs($me);

        Livewire::test('friends.conversation', ['other' => $bob])
            ->assertSeeHtml('<textarea')
            ->assertSeeHtml('aria-label="Message Bob Smith"')
            ->assertSeeHtml('role="log"')
            ->assertSee('Check my English');
    }

    public function test_message_times_use_the_learners_timezone_with_day_separators(): void
    {
        $this->travelTo('2026-10-03 12:00:00');

        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->acceptFollowRequest($me);

        DirectMessage::create(['sender_id' => $bob->id, 'recipient_id' => $me->id, 'body' => 'older one'])->forceFill(['created_at' => '2026-10-01 10:00:00'])->save();
        DirectMessage::create(['sender_id' => $bob->id, 'recipient_id' => $me->id, 'body' => 'late night'])->forceFill(['created_at' => '2026-10-02 22:30:00'])->save();

        $this->actingAs($me);

        // 22:30 UTC is already 02:00 on the 3rd in Tehran (the fallback zone).
        Livewire::test('friends.conversation', ['other' => $bob])
            ->assertSee('Thu, Oct 1')
            ->assertSee('Today')
            ->assertSee('2:00 AM');

        Livewire::withCookie('eos_tz', 'America/New_York')
            ->test('friends.conversation', ['other' => $bob])
            ->assertSee('Yesterday')
            ->assertSee('6:30 PM');
    }

    public function test_only_the_latest_page_loads_until_earlier_messages_are_requested(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->acceptFollowRequest($me);

        foreach (range(1, 60) as $number) {
            DirectMessage::create(['sender_id' => $bob->id, 'recipient_id' => $me->id, 'body' => "msg-{$number}-end"]);
        }

        $this->actingAs($me);

        Livewire::test('friends.conversation', ['other' => $bob])
            ->assertSee('Load earlier messages')
            ->assertSee('msg-60-end')
            ->assertDontSee('msg-5-end')
            ->call('loadEarlier')
            ->assertSee('msg-5-end')
            ->assertSee('msg-1-end')
            ->assertDontSee('Load earlier messages');

        $this->assertSame(0, DirectMessage::whereNull('read_at')->where('recipient_id', $me->id)->count());
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

    public function test_a_burst_of_messages_leaves_the_recipient_one_notification(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->acceptFollowRequest($me);

        $this->actingAs($me);

        $chat = Livewire::test('friends.conversation', ['other' => $bob]);
        $chat->set('body', 'one')->call('send');
        $chat->set('body', 'two')->call('send');
        $chat->set('body', 'three')->call('send');

        $this->assertSame(1, $this->directMessageNotifications($bob)->count());
    }

    public function test_a_nudge_keeps_its_own_notification_separate_from_messages(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->acceptFollowRequest($me);

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->andThrow(new \RuntimeException('down')));

        $this->actingAs($me);

        $chat = Livewire::test('friends.conversation', ['other' => $bob]);
        $chat->set('body', 'hi')->call('send');
        $chat->call('draftNudge')->call('send');
        $chat->call('draftNudge')->call('send');

        $this->assertSame(2, $this->directMessageNotifications($bob)->count());
    }

    public function test_each_sender_gets_their_own_notification(): void
    {
        $me = User::factory()->create();
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        foreach ([$me, $alice] as $friend) {
            $friend->follow($bob);
            $bob->acceptFollowRequest($friend);
        }

        $this->actingAs($me);
        Livewire::test('friends.conversation', ['other' => $bob])->set('body', 'hi')->call('send');

        $this->actingAs($alice);
        Livewire::test('friends.conversation', ['other' => $bob])->set('body', 'hello')->call('send');

        $this->assertSame(2, $this->directMessageNotifications($bob)->count());
    }

    public function test_opening_the_conversation_reads_that_senders_notification_and_the_next_message_starts_a_new_one(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->acceptFollowRequest($me);

        $this->actingAs($me);
        Livewire::test('friends.conversation', ['other' => $bob])->set('body', 'first')->call('send');
        $this->assertSame(1, $this->directMessageNotifications($bob, unreadOnly: true)->count());

        $this->actingAs($bob);
        Livewire::test('friends.conversation', ['other' => $me])->assertSee('first');
        $this->assertSame(0, $this->directMessageNotifications($bob, unreadOnly: true)->count());

        $this->actingAs($me);
        Livewire::test('friends.conversation', ['other' => $bob])->set('body', 'second')->call('send');

        $this->assertSame(2, $this->directMessageNotifications($bob)->count());
        $this->assertSame(1, $this->directMessageNotifications($bob, unreadOnly: true)->count());
    }

    private function directMessageNotifications(User $user, bool $unreadOnly = false)
    {
        $relation = $unreadOnly ? $user->unreadNotifications() : $user->notifications();

        return $relation->where('type', DirectMessageReceived::class);
    }
}
