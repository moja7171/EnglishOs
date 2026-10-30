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

    /**
     * @param  array<string, mixed>  $storyExtras  extra story_sequence keys, e.g. the narration tense
     */
    private function makeRun(array $storyExtras = []): MissionRun
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
                        ...$storyExtras,
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

    public function test_a_story_without_pictures_does_not_tell_the_learner_to_refresh(): void
    {
        $run = $this->makeRun();

        $this->mock(PexelsClient::class, fn ($mock) => $mock->shouldReceive('imageUrlFor')->andReturn(null));

        Livewire::test('missions.steps.story-sequence', ['run' => $run])
            ->assertSee("The pictures aren't available right now", false)
            ->assertDontSee('refresh the page');
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
            $mock->shouldReceive('chat')->once()->andReturn($this->learnerFeedbackReply());
        });

        Livewire::test('missions.steps.story-sequence', ['run' => $run])
            ->set('recording', UploadedFile::fake()->create('story.webm', 400, 'audio/webm'))
            ->call('save')
            ->assertSet('completed', true)
            ->assertSee('I work in a hospital.')
            ->call('proceed')
            ->assertRedirect(route('missions.show', $run->mission));

        $this->assertDatabaseHas('evidences', [
            'mission_run_id' => $run->id,
            'phase' => 'story_sequence',
            'type' => Evidence::TYPE_AUDIO,
        ]);

        $this->assertSame('activation', $run->fresh()->currentStepKey());
    }

    public function test_a_routine_is_told_in_present_simple_by_default(): void
    {
        Storage::fake('public');
        $run = $this->makeRun();

        $this->mock(PexelsClient::class, fn ($mock) => $mock->shouldReceive('imageUrlFor')->andReturn(null));
        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->andReturn('First she wakes up.'));
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()
                ->withArgs(fn ($messages, $systemPrompt) => str_contains($systemPrompt, 'told in PRESENT SIMPLE')
                    && str_contains($systemPrompt, 'not past tense'))
                ->andReturn($this->learnerFeedbackReply());
        });

        Livewire::test('missions.steps.story-sequence', ['run' => $run])
            ->assertSee('Tell it · Present Simple')
            ->set('recording', UploadedFile::fake()->create('story.webm', 400, 'audio/webm'))
            ->call('save')
            ->assertSet('completed', true);
    }

    /**
     * M06's pictures tell a finished story, so the step asks for the Past
     * Simple on screen and the AI judges that tense, not M01's routine one.
     */
    public function test_a_finished_story_is_told_in_the_tense_the_mission_sets(): void
    {
        Storage::fake('public');
        $run = $this->makeRun([
            'narration_tense' => 'Past Simple',
            'narration_kind' => 'a story that already happened',
            'narration_example' => 'First, he was bored in class...',
            'narration_not' => 'present tense',
        ]);

        $this->mock(PexelsClient::class, fn ($mock) => $mock->shouldReceive('imageUrlFor')->andReturn(null));
        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->andReturn('First she woke up.'));
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()
                ->withArgs(fn ($messages, $systemPrompt) => str_contains($systemPrompt, 'told in PAST SIMPLE')
                    && str_contains($systemPrompt, 'not present tense')
                    && ! str_contains($systemPrompt, 'PRESENT SIMPLE'))
                ->andReturn($this->learnerFeedbackReply());
        });

        Livewire::test('missions.steps.story-sequence', ['run' => $run])
            ->assertSee('Tell it · Past Simple')
            ->assertDontSee('Tell it · Present Simple')
            ->set('recording', UploadedFile::fake()->create('story.webm', 400, 'audio/webm'))
            ->call('save')
            ->assertSet('completed', true);
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

    public function test_continue_stays_on_screen_but_disabled_until_a_recording_exists(): void
    {
        Storage::fake('public');
        $run = $this->makeRun();

        $this->mock(PexelsClient::class, fn ($mock) => $mock->shouldReceive('imageUrlFor')->andReturn(null));

        Livewire::test('missions.steps.story-sequence', ['run' => $run])
            ->assertSeeHtml('x-on:click="screen = 2; $wire.save()"')
            ->assertSeeHtml('x-bind:disabled="! (false)"')
            ->set('recording', UploadedFile::fake()->create('story.webm', 400, 'audio/webm'))
            ->assertSeeHtml('x-bind:disabled="! (true)"');
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

    public function test_a_recording_is_kept_when_the_learner_leaves_before_saving(): void
    {
        Storage::fake('public');
        $run = $this->makeRun();
        $this->mock(PexelsClient::class, fn ($mock) => $mock->shouldReceive('imageUrlFor')->andReturn(null));

        Livewire::test('missions.steps.story-sequence', ['run' => $run])
            ->set('recording', UploadedFile::fake()->create('take.webm', 400, 'audio/webm'));

        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->once()->andReturn('First she wakes up.'));
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn($this->learnerFeedbackReply()));

        $returned = Livewire::test('missions.steps.story-sequence', ['run' => $run]);
        $keptPath = $returned->get('recordingPath');
        Storage::disk('public')->assertExists($keptPath);

        $returned->call('save')->assertSet('completed', true);

        $this->assertSame(Storage::disk('public')->url($keptPath), Evidence::where('type', Evidence::TYPE_AUDIO)->value('content_ref'));
        $this->assertDatabaseCount('step_drafts', 0);
    }
}
