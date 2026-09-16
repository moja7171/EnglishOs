<?php

namespace Tests\Feature;

use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\PlacementTest as PlacementTestResult;
use App\Models\User;
use App\Services\GeminiClient;
use App\Services\GroqClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Your voice, N months in" — S3 of
 * [[project_growth_without_discouragement_stories]]. See also
 * ProgramPlannerTest for checkpoint AVAILABILITY (whether the offer
 * shows); this file covers what happens once the learner takes it.
 */
class CheckpointTest extends TestCase
{
    use RefreshDatabase;

    private function checkpointMission(): Mission
    {
        return Mission::create([
            'code' => 'M06',
            'title' => 'Checkpoint Mission',
            'module' => 'Me',
            'outcome' => 'Outcome.',
            'phases' => [['label' => 'Day 1', 'steps' => [
                ['key' => 'grammar_in_context', 'label' => 'Grammar', 'duration_minutes' => 10, 'focus' => 'Past Simple'],
            ]]],
        ]);
    }

    public function test_finishing_saves_a_new_checkpoint_row_tied_to_the_mission(): void
    {
        Storage::fake('public');
        $learner = User::factory()->create();
        $mission = $this->checkpointMission();

        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->once()->andReturn('I usually get up at seven and go to work.'));
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn(json_encode([
            'level' => 'B1', 'reason' => 'Clear and fluent.',
        ])));

        Livewire::actingAs($learner)
            ->test('missions.checkpoint', ['mission' => $mission])
            ->set('recording', UploadedFile::fake()->create('checkpoint.webm', 100, 'audio/webm'))
            ->call('finish')
            ->assertSet('completed', true);

        $this->assertDatabaseHas('placement_tests', [
            'learner_id' => $learner->id,
            'kind' => PlacementTestResult::KIND_CHECKPOINT,
            'checkpoint_mission_code' => 'M06',
            'spoken_level' => 'B1',
        ]);
    }

    public function test_a_comparison_is_generated_against_the_earlier_recording(): void
    {
        Storage::fake('public');
        $learner = User::factory()->create();
        $mission = $this->checkpointMission();

        $learner->placementTests()->create([
            'kind' => PlacementTestResult::KIND_INITIAL,
            'level' => 'A2', 'recognition_level' => 'A2', 'spoken_level' => 'A2',
            'transcript' => 'I get up. I go work. I eat.',
            'audio_url' => '/storage/placement/old.webm',
            'detail' => [],
        ]);

        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->once()->andReturn(
            'I usually get up early, have a proper breakfast, and then head to work, which I really enjoy.'
        ));
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->twice()->andReturn(
            json_encode(['level' => 'B1', 'reason' => 'Much more fluent.']),
            json_encode(['observations' => ['Longer, more connected sentences.', 'Wider vocabulary range.'], 'focus' => 'Try linking ideas with "because" and "so".']),
        ));

        $component = Livewire::actingAs($learner)
            ->test('missions.checkpoint', ['mission' => $mission])
            ->set('recording', UploadedFile::fake()->create('checkpoint.webm', 100, 'audio/webm'))
            ->call('finish');

        $component->assertSet('completed', true)
            ->assertSee('Longer, more connected sentences.')
            ->assertSee('Try linking ideas');
    }

    public function test_no_earlier_recording_gets_an_honest_first_time_message_not_a_broken_comparison(): void
    {
        Storage::fake('public');
        $learner = User::factory()->create();
        $mission = $this->checkpointMission();

        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->once()->andReturn('I get up at seven.'));
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn(json_encode([
            'level' => 'A2', 'reason' => 'Basic but clear.',
        ])));

        Livewire::actingAs($learner)
            ->test('missions.checkpoint', ['mission' => $mission])
            ->set('recording', UploadedFile::fake()->create('checkpoint.webm', 100, 'audio/webm'))
            ->call('finish')
            ->assertSet('completed', true)
            ->assertSee('nothing to compare this one to yet')
            ->assertDontSee('no progress');
    }

    public function test_a_comparison_never_claims_no_progress(): void
    {
        // The copy rule itself (T3.7): whatever the AI or the fallback
        // path renders, the literal phrase "no progress" must never
        // appear — see the systemPrompt in
        // PlacementTest::compareTranscripts() and the view's own wording.
        $source = file_get_contents(base_path('resources/views/components/missions/⚡checkpoint.blade.php'));
        $this->assertStringNotContainsStringIgnoringCase('no progress', $source);
    }

    public function test_a_failed_ai_relay_still_saves_the_recording_without_losing_the_attempt(): void
    {
        Storage::fake('public');
        $learner = User::factory()->create();
        $mission = $this->checkpointMission();

        $learner->placementTests()->create([
            'kind' => PlacementTestResult::KIND_INITIAL,
            'level' => 'A2', 'recognition_level' => 'A2',
            'transcript' => 'I get up. I go work.',
            'detail' => [],
        ]);

        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->once()->andThrow(new \RuntimeException('relay down')));

        Livewire::actingAs($learner)
            ->test('missions.checkpoint', ['mission' => $mission])
            ->set('recording', UploadedFile::fake()->create('checkpoint.webm', 100, 'audio/webm'))
            ->call('finish')
            ->assertSet('completed', true);

        $this->assertDatabaseHas('placement_tests', [
            'learner_id' => $learner->id,
            'kind' => PlacementTestResult::KIND_CHECKPOINT,
            'spoken_level' => null,
        ]);
    }

    public function test_skipping_creates_no_row_and_does_not_block_anything(): void
    {
        $learner = User::factory()->create();
        $mission = $this->checkpointMission();

        Livewire::actingAs($learner)
            ->test('missions.checkpoint', ['mission' => $mission])
            ->call('skip')
            ->assertRedirect(route('home'));

        $this->assertDatabaseCount('placement_tests', 0);
    }

    public function test_taking_a_checkpoint_never_touches_mission_run_progress(): void
    {
        Storage::fake('public');
        $learner = User::factory()->create();
        $mission = $this->checkpointMission();
        $run = MissionRun::findOrStart($learner, $mission);
        $run->update(['status' => MissionRun::STATUS_COMPLETE, 'completed_at' => now()]);

        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->once()->andReturn('I get up at seven.'));
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn(json_encode([
            'level' => 'A2', 'reason' => 'Clear.',
        ])));

        Livewire::actingAs($learner)
            ->test('missions.checkpoint', ['mission' => $mission])
            ->set('recording', UploadedFile::fake()->create('checkpoint.webm', 100, 'audio/webm'))
            ->call('finish');

        // Nothing about taking the checkpoint writes Evidence or changes
        // the run's status — it is entirely outside Evidence Before
        // Progress (EOS-003 §7).
        $this->assertSame(MissionRun::STATUS_COMPLETE, $run->fresh()->status);
        $this->assertDatabaseCount('evidences', 0);
    }

    public function test_a_real_level_jump_updates_the_learners_standing_level(): void
    {
        Storage::fake('public');
        $learner = User::factory()->create(['cefr_level' => 'A2']);
        $mission = $this->checkpointMission();

        $learner->placementTests()->create([
            'kind' => PlacementTestResult::KIND_INITIAL,
            'level' => 'A2', 'recognition_level' => 'A2',
            'transcript' => 'I get up. I go work.',
            'detail' => [],
        ]);

        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->once()->andReturn(
            'I generally wake up early and have a proper breakfast before heading to the office, where I work until the evening.'
        ));
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->twice()->andReturn(
            json_encode(['level' => 'B1', 'reason' => 'Fluent and varied.']),
            json_encode(['observations' => ['Much richer detail.'], 'focus' => 'Keep going.']),
        ));

        Livewire::actingAs($learner)
            ->test('missions.checkpoint', ['mission' => $mission])
            ->set('recording', UploadedFile::fake()->create('checkpoint.webm', 100, 'audio/webm'))
            ->call('finish');

        $this->assertSame('B1', $learner->fresh()->cefr_level);
    }

    public function test_a_single_off_recording_never_pulls_the_standing_level_back_down(): void
    {
        Storage::fake('public');
        $learner = User::factory()->create(['cefr_level' => 'B1']);
        $mission = $this->checkpointMission();

        $learner->placementTests()->create([
            'kind' => PlacementTestResult::KIND_INITIAL,
            'level' => 'B1', 'recognition_level' => 'B1',
            'transcript' => 'A long, fluent transcript about daily life with varied vocabulary.',
            'detail' => [],
        ]);

        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->once()->andReturn('I get up.'));
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->twice()->andReturn(
            json_encode(['level' => 'A1', 'reason' => 'Very short answer.']),
            json_encode(['observations' => ['Shorter than before.'], 'focus' => 'Try adding more detail.']),
        ));

        Livewire::actingAs($learner)
            ->test('missions.checkpoint', ['mission' => $mission])
            ->set('recording', UploadedFile::fake()->create('checkpoint.webm', 100, 'audio/webm'))
            ->call('finish');

        // One off day must not silently demote the learner — see the
        // "never discouraging" constraint on the whole epic.
        $this->assertSame('B1', $learner->fresh()->cefr_level);
    }
}
