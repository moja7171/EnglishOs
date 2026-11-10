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

    /**
     * Records a take for each of the two guiding questions.
     */
    private function recordBothTakes($component)
    {
        return $component
            ->set('recordings.0', UploadedFile::fake()->create('one.webm', 400, 'audio/webm'))
            ->call('keepTake', 0)
            ->set('recordings.1', UploadedFile::fake()->create('two.webm', 400, 'audio/webm'))
            ->call('keepTake', 1);
    }

    public function test_every_question_needs_its_own_recording(): void
    {
        Storage::fake('public');
        $run = $this->makeRun();

        Livewire::test('missions.steps.picture-description', ['run' => $run])
            ->call('save')
            ->assertHasErrors(['recordings'])
            ->set('recordings.0', UploadedFile::fake()->create('one.webm', 400, 'audio/webm'))
            ->call('keepTake', 0)
            ->call('save')
            ->assertHasErrors(['recordings'])
            ->assertSet('completed', false);

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
            $mock->shouldReceive('transcribeWithDuration')->twice()
                ->andReturn(
                    ['text' => 'There is a family.', 'duration' => 10.0],
                    ['text' => 'They are eating breakfast together.', 'duration' => 14.5],
                );
        });
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn($this->learnerFeedbackReply(['severity' => 'minor']));
        });

        $this->recordBothTakes(Livewire::test('missions.steps.picture-description', ['run' => $run]))
            ->call('save')
            ->assertSet('completed', true)
            ->assertSet('durationSeconds', 24.5)
            ->assertSee('I work in a hospital.')
            ->assertSee('25s') // rounded from the two real Whisper durations added up
            ->assertSee('9 words') // real word count of both answers
            ->call('proceed')
            ->assertRedirect(route('missions.show', $run->mission));

        $this->assertSame(2, Evidence::where('mission_run_id', $run->id)->where('phase', 'picture_description')->where('type', Evidence::TYPE_AUDIO)->count());
        $saved = json_decode(Evidence::where('phase', 'picture_description')->where('type', Evidence::TYPE_TEXT)->value('content_ref'), true);
        $this->assertSame('What is each person doing?', $saved['answers'][1]['question']);

        $this->assertSame('reading_comprehension', $run->fresh()->currentStepKey());
    }

    public function test_major_severity_records_a_struggle_signal_for_tone_adaptation(): void
    {
        Storage::fake('public');
        $run = $this->makeRun();

        $this->mock(PexelsClient::class, fn ($mock) => $mock->shouldReceive('imageUrlFor')->andReturn(null));
        $this->mock(GroqClient::class, function ($mock) {
            $mock->shouldReceive('transcribeWithDuration')->twice()
                ->andReturn(['text' => 'A woman pour coffee.', 'duration' => 5.0]);
        });
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn($this->learnerFeedbackReply(['severity' => 'major']));
        });

        $this->recordBothTakes(Livewire::test('missions.steps.picture-description', ['run' => $run]))
            ->call('save');

        $this->assertSame(1, $run->fresh()->struggle_signal_count);
    }

    public function test_a_failed_review_never_blocks_saving_the_recording(): void
    {
        Storage::fake('public');
        $run = $this->makeRun();

        $this->mock(PexelsClient::class, fn ($mock) => $mock->shouldReceive('imageUrlFor')->andReturn(null));
        $this->mock(GroqClient::class, function ($mock) {
            $mock->shouldReceive('transcribeWithDuration')->twice()->andThrow(new \RuntimeException('AI unavailable'));
        });

        $this->recordBothTakes(Livewire::test('missions.steps.picture-description', ['run' => $run]))
            ->call('save')
            ->assertSet('completed', true)
            ->assertSet('feedback', null)
            ->assertSee("feedback isn't available this time", false);

        $this->assertDatabaseHas('evidences', ['mission_run_id' => $run->id, 'phase' => 'picture_description', 'type' => Evidence::TYPE_AUDIO]);
    }

    public function test_continue_stays_on_screen_but_disabled_until_a_recording_exists(): void
    {
        Storage::fake('public');
        $run = $this->makeRun();

        $this->mock(PexelsClient::class, fn ($mock) => $mock->shouldReceive('imageUrlFor')->andReturn(null));

        // Present from the start (so the learner can see where this step
        // leads) but not clickable until there's something to save — see
        // <x-continue-button>.
        $component = Livewire::test('missions.steps.picture-description', ['run' => $run])
            ->assertSeeHtml('x-on:click="screen = 1; $wire.save()"')
            ->assertSeeHtml('x-bind:disabled="! (false)"')
            ->set('recordings.0', UploadedFile::fake()->create('one.webm', 400, 'audio/webm'))
            ->call('keepTake', 0)
            ->assertSeeHtml('x-on:click="question = 1"')
            ->assertSeeHtml('x-bind:disabled="! (true)"') // the first question's own Continue
            ->assertSeeHtml('x-bind:disabled="! (false)"'); // saving still waits for the second take

        $this->recordBothTakes($component)->assertSeeHtml('x-bind:disabled="! (true)"');
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

    /**
     * Epic F: the image is shown fully clear from the start — the old
     * blur-until-recording effect worked against checking the numbered
     * hotspots against the image BEFORE recording.
     */
    public function test_the_scene_image_is_never_blurred(): void
    {
        $run = $this->makeRun();

        $this->mock(PexelsClient::class, fn ($mock) => $mock->shouldReceive('imageUrlFor')->andReturn('http://localhost/image.jpg'));

        Livewire::test('missions.steps.picture-description', ['run' => $run])
            ->assertDontSeeHtml('blur-sm');
    }

    /**
     * Lesson design 11: one guiding question on screen at a time (‹ ›
     * move through them), and no "Try to cover" list or start screen.
     */
    public function test_the_guiding_questions_show_one_at_a_time(): void
    {
        $run = $this->makeRun();

        $this->mock(PexelsClient::class, fn ($mock) => $mock->shouldReceive('imageUrlFor')->andReturn(null));

        Livewire::test('missions.steps.picture-description', ['run' => $run])
            ->assertSeeHtml('x-show="question === 0"')
            ->assertSeeHtml('x-show="question === 1"')
            ->assertSee('Next question')
            ->assertDontSee('Try to cover')
            ->assertDontSee('Describe what you see');
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

    /**
     * A 24px marker is well under a comfortable mobile tap target, so its
     * hit area is widened with an invisible ::before without changing how
     * big the dot looks on the photo.
     */
    public function test_hotspot_markers_have_an_enlarged_tap_target(): void
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [[
                'phase' => 'practice',
                'steps' => [[
                    'key' => 'picture_description',
                    'image_query' => 'family breakfast table morning kitchen busy',
                    'guiding_questions' => ['What do you see?'],
                    'hotspots' => [['x' => 17, 'y' => 32, 'question_index' => 0]],
                ]],
            ]],
        ]);
        $this->actingAs($learner);
        $run = MissionRun::findOrStart($learner, $mission);

        $this->mock(PexelsClient::class, fn ($mock) => $mock->shouldReceive('imageUrlFor')->andReturn('http://localhost/image.jpg'));

        Livewire::test('missions.steps.picture-description', ['run' => $run])
            ->assertSeeHtml("before:absolute before:-inset-2.5 before:content-['']");
    }

    /**
     * Tapping a numbered dot on the picture brings up its question.
     */
    public function test_tapping_a_hotspot_shows_its_question(): void
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [[
                'phase' => 'practice',
                'steps' => [[
                    'key' => 'picture_description',
                    'image_query' => 'family breakfast table morning kitchen busy',
                    'guiding_questions' => ['What do you see?', 'Who is cooking?'],
                    'hotspots' => [['x' => 17, 'y' => 32, 'question_index' => 1]],
                ]],
            ]],
        ]);
        $this->actingAs($learner);
        $run = MissionRun::findOrStart($learner, $mission);

        $this->mock(PexelsClient::class, fn ($mock) => $mock->shouldReceive('imageUrlFor')->andReturn('http://localhost/image.jpg'));

        Livewire::test('missions.steps.picture-description', ['run' => $run])
            ->assertSeeHtml('x-on:click="question = 1"');
    }

    public function test_a_recording_is_kept_when_the_learner_leaves_before_saving(): void
    {
        Storage::fake('public');
        $run = $this->makeRun();
        $this->mock(PexelsClient::class, fn ($mock) => $mock->shouldReceive('imageUrlFor')->andReturn(null));

        $this->recordBothTakes(Livewire::test('missions.steps.picture-description', ['run' => $run]));

        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribeWithDuration')->twice()->andReturn(['text' => 'There is a family.', 'duration' => 10.0]));
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn($this->learnerFeedbackReply(['severity' => 'minor'])));

        $returned = Livewire::test('missions.steps.picture-description', ['run' => $run]);
        $keptPaths = $returned->get('recordingPaths');
        $this->assertCount(2, $keptPaths);
        Storage::disk('public')->assertExists($keptPaths[0]);
        Storage::disk('public')->assertExists($keptPaths[1]);

        $returned->call('save')->assertSet('completed', true);

        $this->assertSame(
            [Storage::disk('public')->url($keptPaths[0]), Storage::disk('public')->url($keptPaths[1])],
            Evidence::where('type', Evidence::TYPE_AUDIO)->orderBy('id')->pluck('content_ref')->all(),
        );
        $this->assertDatabaseCount('step_drafts', 0);
    }
}
