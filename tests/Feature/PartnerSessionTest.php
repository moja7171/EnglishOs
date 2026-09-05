<?php

namespace Tests\Feature;

use App\Models\Mission;
use App\Models\PartnerSession;
use App\Models\PartnerSessionAnswer;
use App\Models\User;
use App\Notifications\PartnerAnswerReceived;
use App\Services\GroqClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class PartnerSessionTest extends TestCase
{
    use RefreshDatabase;

    private function makeMission(): Mission
    {
        return Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [
                [
                    'phase' => 'mission',
                    'steps' => [
                        [
                            'key' => 'ai_conversation_1',
                            'interview_questions' => [
                                'What time do you usually wake up?',
                                'What do you normally do in the morning?',
                            ],
                        ],
                        [
                            'key' => 'ai_conversation_2',
                            'rounds' => ['Describe your typical weekday.'],
                            'final_prompt' => 'Speak for 3 minutes about your daily life.',
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function test_conversation_prompts_flattens_interview_questions(): void
    {
        $mission = $this->makeMission();

        $this->assertSame(
            ['What time do you usually wake up?', 'What do you normally do in the morning?'],
            $mission->conversationPrompts('ai_conversation_1')
        );
    }

    public function test_conversation_prompts_flattens_rounds_plus_final_prompt(): void
    {
        $mission = $this->makeMission();

        $this->assertSame(
            ['Describe your typical weekday.', 'Speak for 3 minutes about your daily life.'],
            $mission->conversationPrompts('ai_conversation_2')
        );
    }

    public function test_conversation_prompts_is_empty_for_an_unrelated_step(): void
    {
        $mission = $this->makeMission();

        $this->assertSame([], $mission->conversationPrompts('writing'));
    }

    public function test_find_or_start_for_is_order_independent(): void
    {
        $mission = $this->makeMission();
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $viaAlice = PartnerSession::findOrStartFor($mission, 'ai_conversation_1', $alice, $bob);
        $viaBob = PartnerSession::findOrStartFor($mission, 'ai_conversation_1', $bob, $alice);

        $this->assertSame($viaAlice->id, $viaBob->id);
        $this->assertSame(1, PartnerSession::count());
    }

    public function test_partner_for_returns_the_other_participant(): void
    {
        $mission = $this->makeMission();
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $session = PartnerSession::findOrStartFor($mission, 'ai_conversation_1', $alice, $bob);

        $this->assertTrue($bob->is($session->partnerFor($alice)));
        $this->assertTrue($alice->is($session->partnerFor($bob)));
    }

    public function test_find_for_returns_null_when_no_session_exists_yet(): void
    {
        $mission = $this->makeMission();
        $alice = User::factory()->create();

        $this->assertNull(PartnerSession::findFor($mission, 'ai_conversation_1', $alice));
    }

    public function test_find_for_finds_the_existing_session_without_creating_a_new_one(): void
    {
        $mission = $this->makeMission();
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $started = PartnerSession::findOrStartFor($mission, 'ai_conversation_1', $alice, $bob);

        $this->assertTrue($started->is(PartnerSession::findFor($mission, 'ai_conversation_1', $alice)));
        $this->assertTrue($started->is(PartnerSession::findFor($mission, 'ai_conversation_1', $bob)));
        $this->assertSame(1, PartnerSession::count());
    }

    public function test_partner_last_activity_at_falls_back_to_when_the_session_started(): void
    {
        $mission = $this->makeMission();
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $session = PartnerSession::findOrStartFor($mission, 'ai_conversation_1', $alice, $bob);

        $this->assertTrue($session->created_at->equalTo($session->partnerLastActivityAt($bob)));
    }

    public function test_partner_last_activity_at_reflects_that_participants_latest_answer_only(): void
    {
        $mission = $this->makeMission();
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $session = PartnerSession::findOrStartFor($mission, 'ai_conversation_1', $alice, $bob);

        PartnerSessionAnswer::create([
            'partner_session_id' => $session->id,
            'question_index' => 0,
            'responder_id' => $bob->id,
            'type' => PartnerSessionAnswer::TYPE_TEXT,
            'body' => 'Seven in the morning.',
        ]);

        $this->assertTrue($session->partnerLastActivityAt($bob)->greaterThanOrEqualTo($session->created_at));
        // Alice never answered — her "last activity" is still just the session's start.
        $this->assertTrue($session->created_at->equalTo($session->partnerLastActivityAt($alice)));
    }

    public function test_the_start_route_requires_mutual_follow(): void
    {
        $mission = $this->makeMission();
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $alice->follow($bob); // one-way only

        $this->actingAs($alice);

        $this->get(route('missions.practice-with-friend', ['mission' => $mission, 'step' => 'ai_conversation_1', 'friend' => $bob]))
            ->assertForbidden();
    }

    public function test_the_start_route_finds_or_creates_then_redirects_to_the_shared_session(): void
    {
        $mission = $this->makeMission();
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $alice->follow($bob);
        $bob->acceptFollowRequest($alice);

        $this->actingAs($alice);

        $response = $this->get(route('missions.practice-with-friend', ['mission' => $mission, 'step' => 'ai_conversation_1', 'friend' => $bob]));

        $session = PartnerSession::first();
        $response->assertRedirect(route('partner-sessions.show', $session));
    }

    public function test_a_third_party_cannot_view_someone_elses_session(): void
    {
        $mission = $this->makeMission();
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $stranger = User::factory()->create();
        $alice->follow($bob);
        $bob->acceptFollowRequest($alice);

        $session = PartnerSession::findOrStartFor($mission, 'ai_conversation_1', $alice, $bob);

        $this->actingAs($stranger);
        $this->get(route('partner-sessions.show', $session))->assertForbidden();
    }

    public function test_saving_a_text_answer_is_visible_to_the_partner(): void
    {
        $mission = $this->makeMission();
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $alice->follow($bob);
        $bob->acceptFollowRequest($alice);

        $session = PartnerSession::findOrStartFor($mission, 'ai_conversation_1', $alice, $bob);

        $this->actingAs($alice);
        Livewire::test('partner-session', ['session' => $session])
            ->set('textAnswers.0', 'Seven in the morning.')
            ->call('saveTextAnswer', 0);

        $this->actingAs($bob);
        Livewire::test('partner-session', ['session' => $session])
            ->assertSee('Seven in the morning.')
            ->assertSee('Waiting for '.$alice->name)
            ->assertDontSee('Waiting for '.$bob->name);
    }

    public function test_saving_a_new_answer_notifies_the_partner(): void
    {
        Notification::fake();
        $mission = $this->makeMission();
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $alice->follow($bob);
        $bob->acceptFollowRequest($alice);

        $session = PartnerSession::findOrStartFor($mission, 'ai_conversation_1', $alice, $bob);

        $this->actingAs($alice);
        Livewire::test('partner-session', ['session' => $session])
            ->set('textAnswers.0', 'Seven in the morning.')
            ->call('saveTextAnswer', 0);

        Notification::assertSentTo($bob, PartnerAnswerReceived::class);
        Notification::assertNotSentTo($alice, PartnerAnswerReceived::class);
    }

    public function test_resaving_an_answer_does_not_notify_again(): void
    {
        Notification::fake();
        $mission = $this->makeMission();
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $alice->follow($bob);
        $bob->acceptFollowRequest($alice);

        $session = PartnerSession::findOrStartFor($mission, 'ai_conversation_1', $alice, $bob);

        $this->actingAs($alice);
        $component = Livewire::test('partner-session', ['session' => $session])
            ->set('textAnswers.0', 'First try.')
            ->call('saveTextAnswer', 0);

        $component->set('textAnswers.0', 'Better answer.')->call('saveTextAnswer', 0);

        Notification::assertSentToTimes($bob, PartnerAnswerReceived::class, 1);
    }

    public function test_an_empty_text_answer_is_a_no_op(): void
    {
        $mission = $this->makeMission();
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $alice->follow($bob);
        $bob->acceptFollowRequest($alice);

        $session = PartnerSession::findOrStartFor($mission, 'ai_conversation_1', $alice, $bob);

        $this->actingAs($alice);
        Livewire::test('partner-session', ['session' => $session])
            ->set('textAnswers.0', '   ')
            ->call('saveTextAnswer', 0);

        $this->assertDatabaseCount('partner_session_answers', 0);
    }

    public function test_resaving_an_answer_updates_it_rather_than_duplicating(): void
    {
        $mission = $this->makeMission();
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $alice->follow($bob);
        $bob->acceptFollowRequest($alice);

        $session = PartnerSession::findOrStartFor($mission, 'ai_conversation_1', $alice, $bob);

        $this->actingAs($alice);
        $component = Livewire::test('partner-session', ['session' => $session])
            ->set('textAnswers.0', 'First try.')
            ->call('saveTextAnswer', 0);

        $component->set('textAnswers.0', 'Better answer.')->call('saveTextAnswer', 0);

        $this->assertDatabaseCount('partner_session_answers', 1);
        $this->assertDatabaseHas('partner_session_answers', ['body' => 'Better answer.']);
    }

    public function test_answering_by_voice_transcribes_and_saves_the_recording(): void
    {
        Storage::fake('local');
        $mission = $this->makeMission();
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $alice->follow($bob);
        $bob->acceptFollowRequest($alice);

        $session = PartnerSession::findOrStartFor($mission, 'ai_conversation_1', $alice, $bob);

        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->once()->andReturn('Around seven.'));

        $this->actingAs($alice);
        Livewire::test('partner-session', ['session' => $session])
            ->set('voiceAnswer', UploadedFile::fake()->create('answer.webm', 100, 'audio/webm'))
            ->call('saveVoiceAnswer', 0)
            ->assertSet('textAnswers.0', 'Around seven.');

        $answer = PartnerSessionAnswer::where('question_index', 0)->firstOrFail();
        $this->assertSame(PartnerSessionAnswer::TYPE_VOICE, $answer->type);
        $this->assertSame('Around seven.', $answer->body);
        Storage::disk('local')->assertExists($answer->attachment_path);
    }

    public function test_only_a_participant_can_download_an_answer_attachment(): void
    {
        Storage::fake('local');
        $mission = $this->makeMission();
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $stranger = User::factory()->create();
        $alice->follow($bob);
        $bob->acceptFollowRequest($alice);

        $session = PartnerSession::findOrStartFor($mission, 'ai_conversation_1', $alice, $bob);

        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->once()->andReturn('Around seven.'));

        $this->actingAs($alice);
        Livewire::test('partner-session', ['session' => $session])
            ->set('voiceAnswer', UploadedFile::fake()->create('answer.webm', 100, 'audio/webm'))
            ->call('saveVoiceAnswer', 0);

        $answer = PartnerSessionAnswer::where('question_index', 0)->firstOrFail();

        $this->actingAs($stranger);
        $this->get(route('partner-sessions.attachment', $answer))->assertForbidden();

        $this->actingAs($bob);
        $this->get(route('partner-sessions.attachment', $answer))->assertOk();
    }

    public function test_each_question_has_a_read_aloud_button_the_partner_must_click(): void
    {
        $mission = $this->makeMission();
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $alice->follow($bob);
        $bob->acceptFollowRequest($alice);

        $session = PartnerSession::findOrStartFor($mission, 'ai_conversation_1', $alice, $bob);

        $this->actingAs($alice);
        Livewire::test('partner-session', ['session' => $session])
            ->assertSeeHtml('data-text="What time do you usually wake up?"')
            ->assertSee('Read aloud')
            ->assertDontSeeHtml('x-init');
    }
}
