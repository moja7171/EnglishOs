<?php

namespace Tests\Feature;

use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\StepDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StepDraftTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(): MissionRun
    {
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [['phase' => 'build', 'steps' => [['key' => 'picture_description'], ['key' => 'story_sequence']]]],
        ]);

        return MissionRun::findOrStart(User::factory()->create(), $mission);
    }

    public function test_a_draft_nobody_saved_for_a_week_is_pruned_with_its_recordings(): void
    {
        Storage::fake('public');
        $run = $this->makeRun();
        $this->freezeTime();

        $this->travel(-8)->days();
        $stale = StepDraft::factory()->for($run, 'missionRun')->create(['paths' => ['missions/m01/evidence/old.webm']]);
        $this->travel(2)->days();
        $recent = StepDraft::factory()->for($run, 'missionRun')->create(['phase' => 'story_sequence', 'paths' => ['missions/m01/evidence/new.webm']]);
        $this->travelBack();
        Storage::disk('public')->put('missions/m01/evidence/old.webm', 'audio');
        Storage::disk('public')->put('missions/m01/evidence/new.webm', 'audio');

        $this->artisan('model:prune', ['--model' => [StepDraft::class]])->assertSuccessful();

        $this->assertModelMissing($stale);
        $this->assertModelExists($recent);
        Storage::disk('public')->assertMissing('missions/m01/evidence/old.webm');
        Storage::disk('public')->assertExists('missions/m01/evidence/new.webm');
    }
}
