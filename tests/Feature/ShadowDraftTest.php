<?php

namespace Tests\Feature;

use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\ShadowDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ShadowDraftTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(): MissionRun
    {
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [['phase' => 'build', 'steps' => [['key' => 'video_shadowing']]]],
        ]);

        return MissionRun::findOrStart(User::factory()->create(), $mission);
    }

    public function test_a_recording_nobody_saved_for_a_week_is_pruned_with_its_file(): void
    {
        Storage::fake('public');
        $run = $this->makeRun();
        $this->freezeTime();

        $this->travel(-8)->days();
        $stale = ShadowDraft::factory()->for($run, 'missionRun')->create(['line_index' => 0]);
        $this->travel(2)->days();
        $recent = ShadowDraft::factory()->for($run, 'missionRun')->create(['line_index' => 1]);
        $this->travelBack();
        Storage::disk('public')->put($stale->path, 'audio');
        Storage::disk('public')->put($recent->path, 'audio');

        $this->artisan('model:prune', ['--model' => [ShadowDraft::class]])->assertSuccessful();

        $this->assertModelMissing($stale);
        $this->assertModelExists($recent);
        Storage::disk('public')->assertMissing($stale->path);
        Storage::disk('public')->assertExists($recent->path);
    }
}
