<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class VideoShadowingStepTest extends TestCase
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
                        [
                            'key' => 'video_shadowing',
                            'video_id' => 'KfVfjL8-R-0',
                            'source' => "Rachel's English — \"My Morning Routine\"",
                            'target_phrases' => [
                                ['phrase' => 'feel like (something)', 'meaning' => 'to want something at that particular moment'],
                                ['phrase' => 'get together', 'meaning' => 'to meet up and spend time with someone'],
                            ],
                            'shadow_lines' => [
                                "I don't **feel like** having **cereal** this **morning**.",
                                'What **time** are you guys **getting together**?',
                                'We always have a quick **snack** in the **afternoon**.',
                            ],
                        ],
                        ['key' => 'daily_listen_3'],
                    ],
                ],
            ],
        ]);

        $this->actingAs($learner);

        return MissionRun::findOrStart($learner, $mission);
    }

    private function fillWatchedFlags($component)
    {
        return $component
            ->set('watchedWithCaptions', true)
            ->set('watchedWithoutCaptions', true);
    }

    public function test_the_youtube_video_is_embedded(): void
    {
        $run = $this->makeRun();

        $html = Livewire::test('missions.steps.video-shadowing', ['run' => $run])->html();

        $this->assertStringContainsString('youtube-nocookie.com/embed/KfVfjL8-R-0', $html);
    }

    public function test_continue_is_blocked_until_both_watch_checkboxes_are_ticked(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.video-shadowing', ['run' => $run])
            ->set('shadowRecordings.0', UploadedFile::fake()->create('video-shadow-0.webm', 500, 'audio/webm'))
            ->set('shadowRecordings.1', UploadedFile::fake()->create('video-shadow-1.webm', 500, 'audio/webm'))
            ->call('save')
            ->assertHasErrors(['watched']);

        $this->assertDatabaseCount('evidences', 0);
    }

    public function test_continue_is_blocked_with_fewer_than_2_shadowed_lines(): void
    {
        $run = $this->makeRun();

        $this->fillWatchedFlags(Livewire::test('missions.steps.video-shadowing', ['run' => $run]))
            ->set('shadowRecordings.0', UploadedFile::fake()->create('video-shadow-0.webm', 500, 'audio/webm'))
            ->call('save')
            ->assertHasErrors(['shadowRecordings']);

        $this->assertDatabaseCount('evidences', 0);
    }

    public function test_saving_with_2_shadowed_lines_records_evidence_and_advances_the_run(): void
    {
        $run = $this->makeRun();

        $component = $this->fillWatchedFlags(Livewire::test('missions.steps.video-shadowing', ['run' => $run]))
            ->set('shadowRecordings.0', UploadedFile::fake()->create('video-shadow-0.webm', 500, 'audio/webm'))
            ->set('shadowRecordings.2', UploadedFile::fake()->create('video-shadow-2.webm', 500, 'audio/webm'))
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('completed', true);

        $this->assertSame(3, Evidence::where('mission_run_id', $run->id)->where('phase', 'video_shadowing')->count());

        $textEvidence = Evidence::where('phase', 'video_shadowing')->where('type', Evidence::TYPE_TEXT)->first();
        $content = json_decode($textEvidence->content_ref, true);
        $this->assertTrue($content['watched_with_captions']);
        $this->assertTrue($content['watched_without_captions']);
        $this->assertSame([0, 2], $content['shadowed_line_indices']);

        $audioEvidences = Evidence::where('phase', 'video_shadowing')->where('type', Evidence::TYPE_AUDIO)->get();
        $this->assertCount(2, $audioEvidences);
        $lineIndices = $audioEvidences->map(fn ($e) => json_decode($e->content_ref, true)['line_index'])->sort()->values();
        $this->assertSame([0, 2], $lineIndices->all());

        $component->call('proceed')->assertRedirect(route('missions.show', $run->mission));
        $this->assertSame('daily_listen_3', $run->fresh()->currentStepKey());
    }

    public function test_shadowing_all_3_lines_still_works(): void
    {
        $run = $this->makeRun();

        $this->fillWatchedFlags(Livewire::test('missions.steps.video-shadowing', ['run' => $run]))
            ->set('shadowRecordings.0', UploadedFile::fake()->create('video-shadow-0.webm', 500, 'audio/webm'))
            ->set('shadowRecordings.1', UploadedFile::fake()->create('video-shadow-1.webm', 500, 'audio/webm'))
            ->set('shadowRecordings.2', UploadedFile::fake()->create('video-shadow-2.webm', 500, 'audio/webm'))
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('completed', true);

        $this->assertSame(3, Evidence::where('phase', 'video_shadowing')->where('type', Evidence::TYPE_AUDIO)->count());
    }

    public function test_every_shadow_line_renders_with_its_own_recorder(): void
    {
        $run = $this->makeRun();

        $html = Livewire::test('missions.steps.video-shadowing', ['run' => $run])->html();

        $this->assertStringContainsString('Line 1', $html);
        $this->assertStringContainsString('Line 2', $html);
        $this->assertStringContainsString('Line 3', $html);
        $this->assertStringContainsString('>feel like</strong>', $html);
        $this->assertStringContainsString('>getting together</strong>', $html);
        $this->assertStringNotContainsString('**', $html);
    }

    public function test_read_only_mode_maps_saved_answers_and_recordings_back(): void
    {
        $run = $this->makeRun();

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'video_shadowing',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode([
                'watched_with_captions' => true,
                'watched_without_captions' => true,
                'shadowed_line_indices' => [0, 1],
            ]),
        ]);
        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'video_shadowing',
            'type' => Evidence::TYPE_AUDIO,
            'content_ref' => json_encode(['line_index' => 0, 'url' => 'http://localhost/storage/missions/m01/evidence/video-shadow-0.webm']),
        ]);
        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'video_shadowing',
            'type' => Evidence::TYPE_AUDIO,
            'content_ref' => json_encode(['line_index' => 1, 'url' => 'http://localhost/storage/missions/m01/evidence/video-shadow-1.webm']),
        ]);

        $component = Livewire::test('missions.steps.video-shadowing', ['run' => $run, 'readOnly' => true])
            ->assertSet('watchedWithCaptions', true)
            ->assertSet('watchedWithoutCaptions', true)
            ->assertSet('savedShadowUrls.0', 'http://localhost/storage/missions/m01/evidence/video-shadow-0.webm')
            ->assertSet('savedShadowUrls.1', 'http://localhost/storage/missions/m01/evidence/video-shadow-1.webm');

        $component->assertSee('Not shadowed.');
        $component->assertDontSee('Quick check');
    }
}
