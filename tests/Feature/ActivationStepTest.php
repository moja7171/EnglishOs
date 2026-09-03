<?php

namespace Tests\Feature;

use App\Models\Evidence;
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

class ActivationStepTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(): MissionRun
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [
                [
                    'phase' => 'build',
                    'steps' => [
                        ['key' => 'activation', 'task' => 'Write 5 personal sentences, then record 2 minutes.'],
                        ['key' => 'ai_conversation_1'],
                    ],
                ],
            ],
        ]);

        $this->actingAs($learner);

        return MissionRun::findOrStart($learner, $mission);
    }

    public function test_recording_is_required(): void
    {
        Storage::fake('public');
        $run = $this->makeRun();

        Livewire::test('missions.steps.activation', ['run' => $run])
            ->set('sentences.0', 'I usually wake up at 7.')
            ->set('sentences.1', 'I have breakfast at 8.')
            ->set('sentences.2', 'I go to work by bus.')
            ->set('sentences.3', 'I exercise in the evening.')
            ->set('sentences.4', 'I go to bed at 11.')
            ->call('save')
            ->assertHasErrors(['audioFile']);

        $this->assertDatabaseCount('evidences', 0);
    }

    public function test_five_sentences_are_required(): void
    {
        Storage::fake('public');
        $run = $this->makeRun();

        Livewire::test('missions.steps.activation', ['run' => $run])
            ->set('sentences.0', 'I usually wake up at 7.')
            ->set('audioFile', UploadedFile::fake()->create('speaking.webm', 500, 'audio/webm'))
            ->call('save')
            ->assertHasErrors(['sentences']);
    }

    public function test_the_recap_offers_talking_about_the_task_with_a_mutual_friend(): void
    {
        Storage::fake('public');
        $run = $this->makeRun();
        $friend = User::factory()->create(['name' => 'Priya']);
        $run->learner->follow($friend);
        $friend->acceptFollowRequest($run->learner);

        $this->mock(GroqClient::class, function ($mock) {
            $mock->shouldReceive('transcribeWithDuration')->once()->andReturn(['text' => 'I wake up at seven.', 'duration' => 90.0]);
        });
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(5)->andReturn(json_encode(['severity' => 'none', 'hint' => '']))->ordered();
            $mock->shouldReceive('chat')->once()->andReturn(json_encode(['highlight' => 'خوب.', 'tip' => 'ادامه بده.']))->ordered();
        });

        Livewire::test('missions.steps.activation', ['run' => $run])
            ->set('sentences.0', 'I usually wake up at 7.')
            ->set('sentences.1', 'I have breakfast at 8.')
            ->set('sentences.2', 'I go to work by bus.')
            ->set('sentences.3', 'I exercise in the evening.')
            ->set('sentences.4', 'I go to bed at 11.')
            ->set('audioFile', UploadedFile::fake()->create('speaking.webm', 500, 'audio/webm'))
            ->call('save')
            ->assertSee('Talk about this with a friend')
            ->assertSee('Priya');
    }

    public function test_valid_submission_stores_both_evidence_rows_and_shows_the_recap(): void
    {
        Storage::fake('public');
        $run = $this->makeRun();

        $this->mock(GroqClient::class, function ($mock) {
            $mock->shouldReceive('transcribeWithDuration')->once()->andReturn([
                'text' => 'I usually wake up at seven and have breakfast.',
                'duration' => 90.0,
            ]);
        });
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->times(5)
                ->andReturn(json_encode(['severity' => 'none', 'hint' => '']))
                ->ordered();
            $mock->shouldReceive('chat')
                ->once()
                ->andReturn(json_encode(['highlight' => 'خیلی روان صحبت کردی.', 'tip' => 'دفعه‌ی بعد یه جزئیات بیشتر اضافه کن.']))
                ->ordered();
        });

        $component = Livewire::test('missions.steps.activation', ['run' => $run])
            ->set('sentences.0', 'I usually wake up at 7.')
            ->set('sentences.1', 'I have breakfast at 8.')
            ->set('sentences.2', 'I go to work by bus.')
            ->set('sentences.3', 'I exercise in the evening.')
            ->set('sentences.4', 'I go to bed at 11.')
            ->set('audioFile', UploadedFile::fake()->create('speaking.webm', 500, 'audio/webm'))
            ->call('save')
            ->assertSet('completed', true)
            ->assertSet('transcript', 'I usually wake up at seven and have breakfast.')
            ->assertSet('reflection.highlight', 'خیلی روان صحبت کردی.')
            ->assertSee('خیلی روان صحبت کردی.');

        $this->assertDatabaseCount('evidences', 2);
        $this->assertDatabaseHas('evidences', ['mission_run_id' => $run->id, 'phase' => 'activation', 'type' => Evidence::TYPE_TEXT]);
        $this->assertDatabaseHas('evidences', ['mission_run_id' => $run->id, 'phase' => 'activation', 'type' => Evidence::TYPE_AUDIO]);

        $textEvidence = Evidence::where('phase', 'activation')->where('type', Evidence::TYPE_TEXT)->first();
        $content = json_decode($textEvidence->content_ref, true);
        $this->assertCount(5, $content['sentences']);
        $this->assertSame('I usually wake up at seven and have breakfast.', $content['transcript']);
        $this->assertSame('خیلی روان صحبت کردی.', $content['reflection']['highlight']);

        $audioEvidence = Evidence::where('phase', 'activation')->where('type', Evidence::TYPE_AUDIO)->first();
        $this->assertStringContainsString('missions/m01/evidence/', $audioEvidence->content_ref);

        // Evidence is already saved, so the run has already advanced — the
        // recap is just a courtesy screen before navigating away, matching
        // Listening's completed-screen pattern.
        $this->assertSame('ai_conversation_1', $run->fresh()->currentStepKey());

        $component->call('proceed')->assertRedirect(route('missions.show', $run->mission));
    }

    public function test_a_failed_transcription_does_not_block_the_recap(): void
    {
        Storage::fake('public');
        $run = $this->makeRun();

        $this->mock(GroqClient::class, function ($mock) {
            $mock->shouldReceive('transcribeWithDuration')->once()->andThrow(new \RuntimeException('Groq is down.'));
        });
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(5)->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        Livewire::test('missions.steps.activation', ['run' => $run])
            ->set('sentences.0', 'I usually wake up at 7.')
            ->set('sentences.1', 'I have breakfast at 8.')
            ->set('sentences.2', 'I go to work by bus.')
            ->set('sentences.3', 'I exercise in the evening.')
            ->set('sentences.4', 'I go to bed at 11.')
            ->set('audioFile', UploadedFile::fake()->create('speaking.webm', 500, 'audio/webm'))
            ->call('save')
            ->assertSet('completed', true)
            ->assertSet('transcript', null)
            ->assertSet('reflection', null);

        $this->assertDatabaseCount('evidences', 2);
    }

    public function test_a_long_enough_recording_gets_a_real_pace_signal_in_the_reflection_prompt(): void
    {
        Storage::fake('public');
        $run = $this->makeRun();

        // Exactly 20 whitespace-separated tokens (2 of them fillers) over
        // 60 real seconds — chosen so words-per-minute works out to a
        // clean, easily-verified 20, derived from the mocked duration and
        // transcript, never fabricated.
        $this->mock(GroqClient::class, function ($mock) {
            $mock->shouldReceive('transcribeWithDuration')->once()->andReturn([
                'text' => 'I usually um wake up at seven and uh have breakfast then I go to work by bus every day',
                'duration' => 60.0,
            ]);
        });
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(5)->andReturn(json_encode(['severity' => 'none', 'hint' => '']))->ordered();
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(function (array $messages, ?string $systemPrompt) {
                    return str_contains($systemPrompt, '20 words per minute')
                        && str_contains($systemPrompt, '60 seconds')
                        && str_contains($systemPrompt, 'with 2 filler words');
                })
                ->andReturn(json_encode(['highlight' => 'خوب بود.', 'tip' => 'ادامه بده.']))
                ->ordered();
        });

        Livewire::test('missions.steps.activation', ['run' => $run])
            ->set('sentences.0', 'I usually wake up at 7.')
            ->set('sentences.1', 'I have breakfast at 8.')
            ->set('sentences.2', 'I go to work by bus.')
            ->set('sentences.3', 'I exercise in the evening.')
            ->set('sentences.4', 'I go to bed at 11.')
            ->set('audioFile', UploadedFile::fake()->create('speaking.webm', 500, 'audio/webm'))
            ->call('save');
    }

    public function test_a_very_short_recording_skips_the_pace_signal_entirely(): void
    {
        Storage::fake('public');
        $run = $this->makeRun();

        $this->mock(GroqClient::class, function ($mock) {
            $mock->shouldReceive('transcribeWithDuration')->once()->andReturn([
                'text' => 'Hi.',
                'duration' => 2.0, // too short for a meaningful words-per-minute figure
            ]);
        });
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(5)->andReturn(json_encode(['severity' => 'none', 'hint' => '']))->ordered();
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(fn (array $messages, ?string $systemPrompt) => ! str_contains($systemPrompt, 'words per minute'))
                ->andReturn(json_encode(['highlight' => 'خوب بود.', 'tip' => 'ادامه بده.']))
                ->ordered();
        });

        Livewire::test('missions.steps.activation', ['run' => $run])
            ->set('sentences.0', 'I usually wake up at 7.')
            ->set('sentences.1', 'I have breakfast at 8.')
            ->set('sentences.2', 'I go to work by bus.')
            ->set('sentences.3', 'I exercise in the evening.')
            ->set('sentences.4', 'I go to bed at 11.')
            ->set('audioFile', UploadedFile::fake()->create('speaking.webm', 500, 'audio/webm'))
            ->call('save');
    }

    public function test_a_major_ai_verdict_on_a_sentence_blocks_continue(): void
    {
        Storage::fake('public');
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->once()
                ->andReturn(json_encode(['severity' => 'major', 'hint' => 'That is just a fragment.']))
                ->ordered();
            $mock->shouldReceive('chat')
                ->times(4)
                ->andReturn(json_encode(['severity' => 'none', 'hint' => '']))
                ->ordered();
        });

        Livewire::test('missions.steps.activation', ['run' => $run])
            ->set('sentences.0', 'bus stop')
            ->set('sentences.1', 'I have breakfast at 8.')
            ->set('sentences.2', 'I go to work by bus.')
            ->set('sentences.3', 'I exercise in the evening.')
            ->set('sentences.4', 'I go to bed at 11.')
            ->set('audioFile', UploadedFile::fake()->create('speaking.webm', 500, 'audio/webm'))
            ->call('save')
            ->assertHasErrors(['sentences']);

        $this->assertDatabaseCount('evidences', 0);
    }

    public function test_clicking_check_on_an_empty_sentence_shows_an_error(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldNotReceive('chat');
        });

        Livewire::test('missions.steps.activation', ['run' => $run])
            ->call('checkOne', 0)
            ->assertSet('checkErrors.0', 'Write something first.')
            ->assertSee('Write something first.');
    }

    public function test_the_practice_section_offers_clickable_vocabulary_chips(): void
    {
        $run = $this->makeRun();

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'vocabulary_builder',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode(['selected_words' => ['wake up', 'have a shower', 'go to bed']]),
        ]);

        Livewire::test('missions.steps.activation', ['run' => $run])
            ->assertSee('Tap a word to drop it into your next sentence')
            ->assertSee('wake up')
            ->assertSee('have a shower')
            ->assertSee('go to bed')
            ->assertSeeHtml('$wire.sentences.findIndex')
            ->assertSeeHtml("\$wire.set('sentences.' + idx, 'Wake up')");
    }

    public function test_three_failed_checks_on_a_sentence_offer_to_reveal_the_correction(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(3)->andReturn(json_encode(['severity' => 'major', 'hint' => 'Try again.']));
        });

        $component = Livewire::test('missions.steps.activation', ['run' => $run])
            ->set('sentences.0', 'attempt one');

        $component->call('checkOne', 0);
        $component->call('checkOne', 0)
            ->assertSee('One more try — after that I can write the correct one for you');
        $component->call('checkOne', 0)
            ->assertSet('offerReveal.0', true)
            ->assertDontSee('One more try — after that I can write the correct one for you');
    }

    public function test_accepting_the_reveal_writes_the_ai_correction_into_the_sentence(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(3)->andReturn(json_encode(['severity' => 'major', 'hint' => 'Try again.']));
            $mock->shouldReceive('chat')->once()->andReturn('I usually wake up at seven.');
        });

        $component = Livewire::test('missions.steps.activation', ['run' => $run])
            ->set('sentences.0', 'bad fragment');

        $component->call('checkOne', 0);
        $component->call('checkOne', 0);
        $component->call('checkOne', 0)->assertSet('offerReveal.0', true);

        $component->call('revealCorrection', 0)
            ->assertSet('sentences.0', 'I usually wake up at seven.')
            ->assertSet('feedback.0.severity', 'none')
            ->assertSet('offerReveal.0', null);
    }

    public function test_uploading_a_recording_shows_playback_before_continuing(): void
    {
        Storage::fake('public');
        $run = $this->makeRun();

        Livewire::test('missions.steps.activation', ['run' => $run])
            ->set('audioFile', UploadedFile::fake()->create('speaking.webm', 500, 'audio/webm'))
            ->assertSee('Listen back')
            ->assertSee('Recording saved');
    }

    public function test_read_only_mode_plays_back_the_saved_recording_with_the_shared_audio_player(): void
    {
        Storage::fake('public');
        $run = $this->makeRun();

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'activation',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode(['sentences' => ['I usually wake up at 7.']]),
        ]);
        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'activation',
            'type' => Evidence::TYPE_AUDIO,
            'content_ref' => 'http://localhost/storage/missions/m01/evidence/speaking.webm',
        ]);

        Livewire::test('missions.steps.activation', ['run' => $run, 'readOnly' => true])
            ->assertSet('sentences.0', 'I usually wake up at 7.')
            ->assertSeeHtml('http://localhost/storage/missions/m01/evidence/speaking.webm')
            ->assertDontSeeHtml('<audio controls');
    }

    public function test_read_only_mode_shows_the_saved_transcript_and_reflection(): void
    {
        Storage::fake('public');
        $run = $this->makeRun();

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'activation',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode([
                'sentences' => ['I usually wake up at 7.'],
                'transcript' => 'I usually wake up at seven and have breakfast.',
                'reflection' => ['highlight' => 'خیلی روان صحبت کردی.', 'tip' => 'دفعه‌ی بعد یه جزئیات بیشتر اضافه کن.'],
            ]),
        ]);
        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'activation',
            'type' => Evidence::TYPE_AUDIO,
            'content_ref' => 'http://localhost/storage/missions/m01/evidence/speaking.webm',
        ]);

        Livewire::test('missions.steps.activation', ['run' => $run, 'readOnly' => true])
            ->assertSee('I usually wake up at seven and have breakfast.')
            ->assertSee('خیلی روان صحبت کردی.')
            ->assertSee('دفعه‌ی بعد یه جزئیات بیشتر اضافه کن.')
            ->assertDontSee('Continue');
    }

    public function test_sentence_inputs_carry_a_draft_key_scoped_to_the_run(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.activation', ['run' => $run])
            ->assertSeeHtml("eos-draft:{$run->id}:activation:sentences.0");
    }

    public function test_read_only_mode_does_not_wire_up_draft_persistence(): void
    {
        Storage::fake('public');
        $run = $this->makeRun();

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'activation',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode(['sentences' => ['I usually wake up at 7.']]),
        ]);
        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'activation',
            'type' => Evidence::TYPE_AUDIO,
            'content_ref' => 'http://localhost/storage/missions/m01/evidence/speaking.webm',
        ]);

        Livewire::test('missions.steps.activation', ['run' => $run, 'readOnly' => true])
            ->assertDontSeeHtml('x-draft');
    }

    public function test_a_successful_save_dispatches_a_clear_draft_event(): void
    {
        Storage::fake('public');
        $run = $this->makeRun();

        $this->mock(GroqClient::class, function ($mock) {
            $mock->shouldReceive('transcribeWithDuration')->once()->andReturn([
                'text' => 'I usually wake up at seven.',
                'duration' => 45.0,
            ]);
        });
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->times(5)
                ->andReturn(json_encode(['severity' => 'none', 'hint' => '']))
                ->ordered();
            $mock->shouldReceive('chat')
                ->once()
                ->andReturn(json_encode(['highlight' => 'خوب بود.', 'tip' => 'ادامه بده.']))
                ->ordered();
        });

        Livewire::test('missions.steps.activation', ['run' => $run])
            ->set('sentences.0', 'I usually wake up at 7.')
            ->set('sentences.1', 'I have breakfast at 8.')
            ->set('sentences.2', 'I go to work by bus.')
            ->set('sentences.3', 'I exercise in the evening.')
            ->set('sentences.4', 'I go to bed at 11.')
            ->set('audioFile', UploadedFile::fake()->create('speaking.webm', 500, 'audio/webm'))
            ->call('save')
            ->assertDispatched('clear-draft', prefix: "eos-draft:{$run->id}:activation:");
    }

    public function test_the_same_warm_up_questions_from_mission_brief_are_shown_before_recording(): void
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [
                [
                    'phase' => 'foundation',
                    'steps' => [
                        ['key' => 'mission_brief', 'warm_up_questions' => ['What time do you usually wake up?']],
                    ],
                ],
                [
                    'phase' => 'build',
                    'steps' => [
                        ['key' => 'activation', 'task' => 'Write 5 personal sentences, then record 2 minutes.'],
                    ],
                ],
            ],
        ]);
        $this->actingAs($learner);
        $run = MissionRun::findOrStart($learner, $mission);

        Livewire::test('missions.steps.activation', ['run' => $run])
            ->assertSee('Same questions as Day 1')
            ->assertSee('What time do you usually wake up?');
    }

    public function test_no_warm_up_questions_block_renders_when_the_mission_has_none(): void
    {
        // makeRun()'s fixture mission has no mission_brief step at all.
        $run = $this->makeRun();

        Livewire::test('missions.steps.activation', ['run' => $run])
            ->assertDontSee('Same questions as Day 1');
    }
}
