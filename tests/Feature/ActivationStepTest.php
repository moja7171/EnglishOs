<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
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
}
