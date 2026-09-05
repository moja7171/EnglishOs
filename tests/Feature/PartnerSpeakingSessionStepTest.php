<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\PartnerSession;
use App\Models\PartnerSessionAnswer;
use App\Models\User;
use App\Services\GroqClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class PartnerSpeakingSessionStepTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(): MissionRun
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M02',
            'title' => 'People I Know',
            'module' => 'Relationships',
            'outcome' => 'I can describe people I know.',
            'phases' => [
                [
                    'phase' => 'challenge',
                    'steps' => [
                        [
                            'key' => 'partner_speaking_session',
                            'round_groups' => [
                                ['label' => 'Your Friends', 'questions' => ['Who is your closest friend?', 'How did you meet?']],
                                ['label' => 'Personality', 'questions' => ['What is your friend like?']],
                                ['label' => 'Deeper', 'questions' => ['Is it harder to make friends as an adult?']],
                            ],
                        ],
                        ['key' => 'ai_conversation_2'],
                    ],
                ],
            ],
        ]);

        $this->actingAs($learner);

        return MissionRun::findOrStart($learner, $mission);
    }

    private function makeMutualFriend(MissionRun $run): User
    {
        $friend = User::factory()->create();
        $run->learner->follow($friend);
        $friend->acceptFollowRequest($run->learner);

        return $friend;
    }

    public function test_choice_screen_shows_both_paths_even_with_zero_mutual_friends(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.partner-speaking-session', ['run' => $run])
            ->assertSet('mode', 'choice')
            ->assertSee('Do this with a partner')
            ->assertSee('Do this solo instead');
    }

    public function test_solo_path_requires_all_3_rounds_before_completing(): void
    {
        Storage::fake('public');
        $run = $this->makeRun();

        Livewire::test('missions.steps.partner-speaking-session', ['run' => $run])
            ->call('chooseSolo')
            ->set('soloRecordings.0', UploadedFile::fake()->create('r0.webm', 200, 'audio/webm'))
            ->set('soloRecordings.1', UploadedFile::fake()->create('r1.webm', 200, 'audio/webm'))
            ->call('saveSolo')
            ->assertHasErrors(['soloRecordings']);

        $this->assertDatabaseCount('evidences', 0);
    }

    public function test_solo_path_with_all_3_rounds_records_correct_evidence_and_advances_the_run(): void
    {
        Storage::fake('public');
        $run = $this->makeRun();

        $this->mock(GroqClient::class, function ($mock) {
            $mock->shouldReceive('transcribeWithConfidence')->times(3)->andReturn([
                'text' => 'We met at university.',
                'duration' => 20.0,
                'segments' => [['text' => 'We met at university.', 'confidence' => 'high']],
            ]);
        });

        $component = Livewire::test('missions.steps.partner-speaking-session', ['run' => $run])
            ->call('chooseSolo')
            ->set('soloRecordings.0', UploadedFile::fake()->create('r0.webm', 200, 'audio/webm'))
            ->set('soloRecordings.1', UploadedFile::fake()->create('r1.webm', 200, 'audio/webm'))
            ->set('soloRecordings.2', UploadedFile::fake()->create('r2.webm', 200, 'audio/webm'))
            ->call('saveSolo')
            ->assertHasNoErrors()
            ->assertSet('completed', true);

        $this->assertSame(
            4, // 1 TEXT metadata row + 3 AUDIO rows, one per round
            Evidence::where('mission_run_id', $run->id)->where('phase', 'partner_speaking_session')->count()
        );

        $textEvidence = Evidence::where('phase', 'partner_speaking_session')->where('type', Evidence::TYPE_TEXT)->first();
        $content = json_decode($textEvidence->content_ref, true);
        $this->assertSame('solo', $content['mode']);
        $this->assertCount(3, $content['transcripts']);
        $this->assertSame('We met at university.', $content['transcripts'][0]);

        $audioEvidences = Evidence::where('phase', 'partner_speaking_session')->where('type', Evidence::TYPE_AUDIO)->get();
        $this->assertCount(3, $audioEvidences);
        $roundIndices = $audioEvidences->map(fn ($e) => json_decode($e->content_ref, true)['round_index'])->sort()->values();
        $this->assertSame([0, 1, 2], $roundIndices->all());

        $component->call('proceed')->assertRedirect(route('missions.show', $run->mission));
        $this->assertSame('ai_conversation_2', $run->fresh()->currentStepKey());
    }

    public function test_a_failed_transcription_does_not_block_the_solo_recording_from_completing(): void
    {
        Storage::fake('public');
        $run = $this->makeRun();

        $this->mock(GroqClient::class, function ($mock) {
            $mock->shouldReceive('transcribeWithConfidence')->times(3)->andThrow(new \RuntimeException('Groq is down.'));
        });

        Livewire::test('missions.steps.partner-speaking-session', ['run' => $run])
            ->call('chooseSolo')
            ->set('soloRecordings.0', UploadedFile::fake()->create('r0.webm', 200, 'audio/webm'))
            ->set('soloRecordings.1', UploadedFile::fake()->create('r1.webm', 200, 'audio/webm'))
            ->set('soloRecordings.2', UploadedFile::fake()->create('r2.webm', 200, 'audio/webm'))
            ->call('saveSolo')
            ->assertHasNoErrors()
            ->assertSet('completed', true);

        $this->assertSame(3, Evidence::where('phase', 'partner_speaking_session')->where('type', Evidence::TYPE_AUDIO)->count());
    }

    public function test_sync_partner_completion_does_not_fire_when_only_the_partner_has_answered(): void
    {
        $run = $this->makeRun();
        $friend = $this->makeMutualFriend($run);
        $session = PartnerSession::findOrStartFor($run->mission, 'partner_speaking_session', $run->learner, $friend);

        foreach ($run->mission->conversationPrompts('partner_speaking_session') as $index => $prompt) {
            PartnerSessionAnswer::create([
                'partner_session_id' => $session->id,
                'question_index' => $index,
                'responder_id' => $friend->id,
                'type' => PartnerSessionAnswer::TYPE_TEXT,
                'body' => 'An answer from the partner.',
            ]);
        }

        Livewire::test('missions.steps.partner-speaking-session', ['run' => $run])
            ->assertSet('mode', 'partner')
            ->call('syncPartnerCompletion')
            ->assertSet('completed', false);

        $this->assertDatabaseCount('evidences', 0);
    }

    public function test_sync_partner_completion_fires_once_the_current_learner_has_answered_everything(): void
    {
        $run = $this->makeRun();
        $friend = $this->makeMutualFriend($run);
        $session = PartnerSession::findOrStartFor($run->mission, 'partner_speaking_session', $run->learner, $friend);

        foreach ($run->mission->conversationPrompts('partner_speaking_session') as $index => $prompt) {
            PartnerSessionAnswer::create([
                'partner_session_id' => $session->id,
                'question_index' => $index,
                'responder_id' => $run->learner_id,
                'type' => PartnerSessionAnswer::TYPE_TEXT,
                'body' => 'My own answer.',
            ]);
        }

        Livewire::test('missions.steps.partner-speaking-session', ['run' => $run])
            ->call('syncPartnerCompletion')
            ->assertSet('completed', true);

        $this->assertDatabaseCount('evidences', 1);
        $evidence = Evidence::where('phase', 'partner_speaking_session')->first();
        $content = json_decode($evidence->content_ref, true);
        $this->assertSame('partner', $content['mode']);
        $this->assertSame($session->id, $content['partner_session_id']);
        $this->assertSame('ai_conversation_2', $run->fresh()->currentStepKey());
    }

    public function test_sync_partner_completion_is_a_no_op_once_evidence_already_exists(): void
    {
        $run = $this->makeRun();
        $friend = $this->makeMutualFriend($run);
        $session = PartnerSession::findOrStartFor($run->mission, 'partner_speaking_session', $run->learner, $friend);

        foreach ($run->mission->conversationPrompts('partner_speaking_session') as $index => $prompt) {
            PartnerSessionAnswer::create([
                'partner_session_id' => $session->id,
                'question_index' => $index,
                'responder_id' => $run->learner_id,
                'type' => PartnerSessionAnswer::TYPE_TEXT,
                'body' => 'My own answer.',
            ]);
        }

        $component = Livewire::test('missions.steps.partner-speaking-session', ['run' => $run])
            ->call('syncPartnerCompletion');

        $component->call('syncPartnerCompletion');

        $this->assertDatabaseCount('evidences', 1);
    }

    public function test_stale_partner_banner_appears_after_48_hours_with_no_partner_activity(): void
    {
        $run = $this->makeRun();
        $friend = $this->makeMutualFriend($run);
        $session = PartnerSession::findOrStartFor($run->mission, 'partner_speaking_session', $run->learner, $friend);
        $session->forceFill(['created_at' => now()->subHours(72)])->save();

        Livewire::test('missions.steps.partner-speaking-session', ['run' => $run])
            ->assertSet('mode', 'partner')
            ->assertSee('Switch to solo');
    }

    public function test_stale_partner_banner_does_not_appear_when_the_partner_was_recently_active(): void
    {
        $run = $this->makeRun();
        $friend = $this->makeMutualFriend($run);
        $session = PartnerSession::findOrStartFor($run->mission, 'partner_speaking_session', $run->learner, $friend);
        $session->forceFill(['created_at' => now()->subHours(72)])->save();

        PartnerSessionAnswer::create([
            'partner_session_id' => $session->id,
            'question_index' => 0,
            'responder_id' => $friend->id,
            'type' => PartnerSessionAnswer::TYPE_TEXT,
            'body' => 'Still here.',
        ]);

        Livewire::test('missions.steps.partner-speaking-session', ['run' => $run])
            ->assertDontSee('Switch to solo');
    }

    public function test_stale_partner_banner_offers_switching_to_solo(): void
    {
        $run = $this->makeRun();
        $friend = $this->makeMutualFriend($run);
        $session = PartnerSession::findOrStartFor($run->mission, 'partner_speaking_session', $run->learner, $friend);
        $session->forceFill(['created_at' => now()->subHours(72)])->save();

        Livewire::test('missions.steps.partner-speaking-session', ['run' => $run])
            ->call('chooseSolo')
            ->assertSet('mode', 'solo');
    }
}
