<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use App\Services\GeminiClient;
use App\Services\GroqClient;
use App\Services\PexelsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class StorySequenceStepTest extends TestCase
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
            'phases' => [[
                'phase' => 'build',
                'steps' => [
                    [
                        'key' => 'story_sequence',
                        'sequence_images' => [
                            ['image_query' => 'alarm clock morning', 'caption' => 'She wakes up'],
                            ['image_query' => 'breakfast kitchen', 'caption' => 'She has breakfast'],
                        ],
                        'sequencing_words' => ['First', 'Then'],
                    ],
                    ['key' => 'activation'],
                ],
            ]],
        ]);

        $this->actingAs($learner);

        return MissionRun::findOrStart($learner, $mission);
    }

    public function test_a_recording_is_required(): void
    {
        Storage::fake('public');
        $run = $this->makeRun();

        Livewire::test('missions.steps.story-sequence', ['run' => $run])
            ->call('save')
            ->assertHasErrors(['recording']);

        $this->assertDatabaseCount('evidences', 0);
    }

    public function test_every_sequence_image_is_fetched_by_its_own_identifier(): void
    {
        $run = $this->makeRun();

        $this->mock(PexelsClient::class, function ($mock) {
            $mock->shouldReceive('imageUrlFor')
                ->with('M01-story-0', 'alarm clock morning')->once()
                ->andReturn('http://localhost/storage/vocabulary-images/m01-story-0.jpg');
            $mock->shouldReceive('imageUrlFor')
                ->with('M01-story-1', 'breakfast kitchen')->once()
                ->andReturn('http://localhost/storage/vocabulary-images/m01-story-1.jpg');
        });

        $component = Livewire::test('missions.steps.story-sequence', ['run' => $run])
            ->assertSeeHtml('http://localhost/storage/vocabulary-images/m01-story-0.jpg')
            ->assertSeeHtml('http://localhost/storage/vocabulary-images/m01-story-1.jpg')
            ->assertSee('First')
            ->assertSee('Then');

        // Captions are ground truth for the AI prompt only — never shown to the learner.
        $component->assertDontSee('She wakes up')->assertDontSee('She has breakfast');
    }

    public function test_saving_transcribes_reviews_and_stores_evidence_then_advances_the_run(): void
    {
        Storage::fake('public');
        $run = $this->makeRun();

        $this->mock(PexelsClient::class, fn ($mock) => $mock->shouldReceive('imageUrlFor')->andReturn(null));
        $this->mock(GroqClient::class, function ($mock) {
            $mock->shouldReceive('transcribe')->once()->andReturn('First she wakes up. Then she has breakfast.');
        });
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode([
                'strength' => 'Good use of Present Simple throughout.',
                'expression' => 'Then',
                'correction' => 'Try "After that" for extra variety.',
            ]));
        });

        Livewire::test('missions.steps.story-sequence', ['run' => $run])
            ->set('recording', UploadedFile::fake()->create('story.webm', 400, 'audio/webm'))
            ->call('save')
            ->assertSet('completed', true)
            ->assertSee('Good use of Present Simple throughout.')
            ->call('proceed')
            ->assertRedirect(route('missions.show', $run->mission));

        $this->assertDatabaseHas('evidences', [
            'mission_run_id' => $run->id,
            'phase' => 'story_sequence',
            'type' => Evidence::TYPE_AUDIO,
        ]);

        $this->assertSame('activation', $run->fresh()->currentStepKey());
    }

    public function test_a_failed_review_never_blocks_saving_the_recording(): void
    {
        Storage::fake('public');
        $run = $this->makeRun();

        $this->mock(PexelsClient::class, fn ($mock) => $mock->shouldReceive('imageUrlFor')->andReturn(null));
        $this->mock(GroqClient::class, function ($mock) {
            $mock->shouldReceive('transcribe')->once()->andThrow(new \RuntimeException('AI unavailable'));
        });

        Livewire::test('missions.steps.story-sequence', ['run' => $run])
            ->set('recording', UploadedFile::fake()->create('story.webm', 400, 'audio/webm'))
            ->call('save')
            ->assertSet('completed', true)
            ->assertSet('feedback', null);

        $this->assertDatabaseHas('evidences', ['mission_run_id' => $run->id, 'phase' => 'story_sequence', 'type' => Evidence::TYPE_AUDIO]);
    }

    public function test_continue_is_hidden_until_a_recording_exists(): void
    {
        Storage::fake('public');
        $run = $this->makeRun();

        $this->mock(PexelsClient::class, fn ($mock) => $mock->shouldReceive('imageUrlFor')->andReturn(null));

        Livewire::test('missions.steps.story-sequence', ['run' => $run])
            ->assertDontSeeHtml('x-on:click="$wire.save()"')
            ->set('recording', UploadedFile::fake()->create('story.webm', 400, 'audio/webm'))
            ->assertSeeHtml('x-on:click="$wire.save()"');
    }

    public function test_read_only_mode_reloads_the_saved_transcript_and_feedback(): void
    {
        $run = $this->makeRun();

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'story_sequence',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode([
                'transcript' => 'First she wakes up.',
                'feedback' => [
                    'strength' => 'Clear sequencing.',
                    'expression' => 'First',
                    'correction' => 'Add a time expression next time.',
                ],
            ]),
        ]);
        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'story_sequence',
            'type' => Evidence::TYPE_AUDIO,
            'content_ref' => 'http://localhost/storage/missions/m01/evidence/story.webm',
        ]);

        $this->mock(PexelsClient::class, fn ($mock) => $mock->shouldReceive('imageUrlFor')->andReturn(null));

        Livewire::test('missions.steps.story-sequence', ['run' => $run, 'readOnly' => true])
            ->assertSee('First she wakes up.')
            ->assertSee('Clear sequencing.')
            ->assertDontSee('Continue');
    }
}
