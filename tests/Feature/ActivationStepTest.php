<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use App\Services\GeminiClient;
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

    public function test_valid_submission_stores_both_evidence_rows_and_advances_the_run(): void
    {
        Storage::fake('public');
        $run = $this->makeRun();

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
            ->assertRedirect(route('missions.show', $run->mission));

        $this->assertDatabaseCount('evidences', 2);
        $this->assertDatabaseHas('evidences', ['mission_run_id' => $run->id, 'phase' => 'activation', 'type' => Evidence::TYPE_TEXT]);
        $this->assertDatabaseHas('evidences', ['mission_run_id' => $run->id, 'phase' => 'activation', 'type' => Evidence::TYPE_AUDIO]);

        $audioEvidence = Evidence::where('phase', 'activation')->where('type', Evidence::TYPE_AUDIO)->first();
        $this->assertStringContainsString('missions/m01/evidence/', $audioEvidence->content_ref);

        $this->assertSame('ai_conversation_1', $run->fresh()->currentStepKey());
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

    public function test_the_practice_section_tips_the_learner_to_reuse_their_selected_vocabulary(): void
    {
        $run = $this->makeRun();

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'vocabulary_builder',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode(['selected_words' => ['wake up', 'have a shower', 'go to bed']]),
        ]);

        Livewire::test('missions.steps.activation', ['run' => $run])
            ->assertSee('Tip: try using some of your words from earlier')
            ->assertSee('wake up, have a shower, go to bed');
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

    public function test_read_only_mode_plays_back_the_saved_recording_with_the_shared_audio_player(): void
    {
        Storage::fake('public');
        $run = $this->makeRun();

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'activation',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode(['I usually wake up at 7.']),
        ]);
        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'activation',
            'type' => Evidence::TYPE_AUDIO,
            'content_ref' => 'http://localhost/storage/missions/m01/evidence/speaking.webm',
        ]);

        Livewire::test('missions.steps.activation', ['run' => $run, 'readOnly' => true])
            ->assertSeeHtml('http://localhost/storage/missions/m01/evidence/speaking.webm')
            ->assertDontSeeHtml('<audio controls');
    }
}
