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

class PictureDescriptionStepTest extends TestCase
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
                'phase' => 'practice',
                'steps' => [
                    [
                        'key' => 'picture_description',
                        'image_query' => 'family breakfast table morning kitchen busy',
                        'guiding_questions' => ['What do you see?', 'What is each person doing?'],
                    ],
                    ['key' => 'reading_comprehension'],
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

        Livewire::test('missions.steps.picture-description', ['run' => $run])
            ->call('save')
            ->assertHasErrors(['recording']);

        $this->assertDatabaseCount('evidences', 0);
    }

    public function test_the_scene_image_is_fetched_from_pexels_as_a_landscape_banner(): void
    {
        $run = $this->makeRun();

        $this->mock(PexelsClient::class, function ($mock) {
            $mock->shouldReceive('imageUrlFor')
                ->with('M01-picture-description', 'family breakfast table morning kitchen busy', 'landscape')
                ->once()
                ->andReturn('http://localhost/storage/vocabulary-images/m01-picture-description.jpg');
        });

        Livewire::test('missions.steps.picture-description', ['run' => $run])
            ->assertSeeHtml('http://localhost/storage/vocabulary-images/m01-picture-description.jpg')
            ->assertSee('What do you see?');
    }

    public function test_saving_transcribes_reviews_and_stores_evidence_then_advances_the_run(): void
    {
        Storage::fake('public');
        $run = $this->makeRun();

        $this->mock(PexelsClient::class, fn ($mock) => $mock->shouldReceive('imageUrlFor')->andReturn(null));
        $this->mock(GroqClient::class, function ($mock) {
            $mock->shouldReceive('transcribeWithDuration')->once()
                ->andReturn(['text' => 'There is a family eating breakfast together.', 'duration' => 24.5]);
        });
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode([
                'strength' => 'You described the scene clearly.',
                'expression' => 'there is a family',
                'correction' => 'Try "is pouring" instead of "pour" for something happening right now.',
                'severity' => 'minor',
            ]));
        });

        Livewire::test('missions.steps.picture-description', ['run' => $run])
            ->set('recording', UploadedFile::fake()->create('description.webm', 400, 'audio/webm'))
            ->call('save')
            ->assertSet('completed', true)
            ->assertSet('durationSeconds', 24.5)
            ->assertSee('You described the scene clearly.')
            ->assertSee('25s') // rounded from the real 24.5s Whisper duration
            ->assertSee('7 words') // real word count of the transcript
            ->call('proceed')
            ->assertRedirect(route('missions.show', $run->mission));

        $this->assertDatabaseHas('evidences', [
            'mission_run_id' => $run->id,
            'phase' => 'picture_description',
            'type' => Evidence::TYPE_AUDIO,
        ]);

        $this->assertSame('reading_comprehension', $run->fresh()->currentStepKey());
    }

    public function test_major_severity_records_a_struggle_signal_for_tone_adaptation(): void
    {
        Storage::fake('public');
        $run = $this->makeRun();

        $this->mock(PexelsClient::class, fn ($mock) => $mock->shouldReceive('imageUrlFor')->andReturn(null));
        $this->mock(GroqClient::class, function ($mock) {
            $mock->shouldReceive('transcribeWithDuration')->once()
                ->andReturn(['text' => 'A woman pour coffee.', 'duration' => 5.0]);
        });
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode([
                'strength' => 'You named the people in the scene.',
                'expression' => 'a woman',
                'correction' => 'Use "is pouring", not "pour", for something happening right now.',
                'severity' => 'major',
            ]));
        });

        Livewire::test('missions.steps.picture-description', ['run' => $run])
            ->set('recording', UploadedFile::fake()->create('description.webm', 400, 'audio/webm'))
            ->call('save');

        $this->assertSame(1, $run->fresh()->struggle_signal_count);
    }

    public function test_a_failed_review_never_blocks_saving_the_recording(): void
    {
        Storage::fake('public');
        $run = $this->makeRun();

        $this->mock(PexelsClient::class, fn ($mock) => $mock->shouldReceive('imageUrlFor')->andReturn(null));
        $this->mock(GroqClient::class, function ($mock) {
            $mock->shouldReceive('transcribeWithDuration')->once()->andThrow(new \RuntimeException('AI unavailable'));
        });

        Livewire::test('missions.steps.picture-description', ['run' => $run])
            ->set('recording', UploadedFile::fake()->create('description.webm', 400, 'audio/webm'))
            ->call('save')
            ->assertSet('completed', true)
            ->assertSet('feedback', null);

        $this->assertDatabaseHas('evidences', ['mission_run_id' => $run->id, 'phase' => 'picture_description', 'type' => Evidence::TYPE_AUDIO]);
    }

    public function test_continue_is_hidden_until_a_recording_exists(): void
    {
        Storage::fake('public');
        $run = $this->makeRun();

        $this->mock(PexelsClient::class, fn ($mock) => $mock->shouldReceive('imageUrlFor')->andReturn(null));

        Livewire::test('missions.steps.picture-description', ['run' => $run])
            ->assertDontSeeHtml('x-on:click="$wire.save()"')
            ->set('recording', UploadedFile::fake()->create('description.webm', 400, 'audio/webm'))
            ->assertSeeHtml('x-on:click="$wire.save()"');
    }

    public function test_read_only_mode_reloads_the_saved_transcript_and_feedback(): void
    {
        $run = $this->makeRun();

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'picture_description',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode([
                'transcript' => 'A family is eating breakfast.',
                'feedback' => [
                    'strength' => 'Good use of present continuous.',
                    'expression' => 'is eating',
                    'correction' => 'Try "at the table" instead of "on the table".',
                ],
            ]),
        ]);
        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'picture_description',
            'type' => Evidence::TYPE_AUDIO,
            'content_ref' => 'http://localhost/storage/missions/m01/evidence/description.webm',
        ]);

        $this->mock(PexelsClient::class, fn ($mock) => $mock->shouldReceive('imageUrlFor')->andReturn(null));

        Livewire::test('missions.steps.picture-description', ['run' => $run, 'readOnly' => true])
            ->assertSee('A family is eating breakfast.')
            ->assertSee('Good use of present continuous.')
            ->assertDontSee('Continue');
    }

    public function test_hotspot_markers_are_rendered_at_their_seeded_coordinates(): void
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [[
                'phase' => 'practice',
                'steps' => [
                    [
                        'key' => 'picture_description',
                        'image_query' => 'family eating breakfast morning kitchen',
                        'guiding_questions' => [
                            'What is the man doing?',
                            'What is the woman doing, and where is she standing?',
                            'Where is the baby, and what is different about her spot at the table?',
                            'What food can you see on the counter?',
                        ],
                        'hotspots' => [
                            ['x' => 17, 'y' => 32, 'question_index' => 0],
                            ['x' => 29, 'y' => 32, 'question_index' => 1],
                            ['x' => 62, 'y' => 58, 'question_index' => 2],
                            ['x' => 15, 'y' => 85, 'question_index' => 3],
                        ],
                    ],
                ],
            ]],
        ]);
        $this->actingAs($learner);
        $run = MissionRun::findOrStart($learner, $mission);

        $this->mock(PexelsClient::class, fn ($mock) => $mock->shouldReceive('imageUrlFor')->andReturn('http://localhost/image.jpg'));

        Livewire::test('missions.steps.picture-description', ['run' => $run])
            ->assertSeeHtml('left: 17%; top: 32%')
            ->assertSeeHtml('left: 29%; top: 32%')
            ->assertSeeHtml('left: 62%; top: 58%')
            ->assertSeeHtml('left: 15%; top: 85%')
            ->assertSee('Where is the baby');
    }
}
