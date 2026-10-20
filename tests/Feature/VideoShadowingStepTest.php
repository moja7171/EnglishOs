<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportTesting\Testable;
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
                            'video_url' => '/storage/missions/m01/test-video.mp4',
                            'captions_url' => '/storage/missions/m01/test-video.en.vtt',
                            'source' => "Rachel's English — \"My Morning Routine\"",
                            'target_phrases' => [
                                ['phrase' => 'feel like (something)', 'meaning' => 'to want something at that particular moment'],
                                ['phrase' => 'get together', 'meaning' => 'to meet up and spend time with someone'],
                            ],
                            'shadow_lines' => [
                                "I don't **feel like** having **cereal** this **morning**.",
                                'What **time** are you guys **getting together**?',
                                'We always have a quick **snack** in the **afternoon**.',
                                'I **usually** **grab** a **banana** on my way out.',
                            ],
                            'shadow_timestamps' => [
                                ['start' => 232.28, 'end' => 234.42],
                                ['start' => 303.59, 'end' => 306.28],
                                null,
                                ['start' => 330.1, 'end' => 332.9],
                            ],
                            'video_segments' => [
                                ['text' => "It's 6:37 in the morning.", 'start' => 0.0, 'end' => 3.0],
                                ['text' => 'What time do you get up?', 'start' => 8.0, 'end' => 9.5],
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

    private function recordLines(Testable $component, int ...$indices): Testable
    {
        foreach ($indices as $index) {
            $component->set("shadowRecordings.{$index}", UploadedFile::fake()->create("video-shadow-{$index}.webm", 500, 'audio/webm'));
        }

        return $component;
    }

    public function test_does_not_show_a_pi_practice_card_inside_the_step(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.video-shadowing', ['run' => $run])
            ->assertDontSee('chat in your voice AI app');
    }

    public function test_the_video_is_embedded_with_its_captions(): void
    {
        $run = $this->makeRun();

        $html = Livewire::test('missions.steps.video-shadowing', ['run' => $run])->html();

        $this->assertStringContainsString('/storage/missions/m01/test-video.mp4', $html);
        $this->assertStringContainsString('/storage/missions/m01/test-video.en.vtt', $html);
    }

    /**
     * Real behavior: the synced text panel (real caption cues) comes from
     * <x-video-player>, fed video_segments/shadow_lines/shadow_timestamps.
     * Shadowing is not a setting: there is no toggle to turn it on or off.
     */
    public function test_the_synced_text_panel_renders_and_shadowing_is_not_a_setting(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.video-shadowing', ['run' => $run])
            ->assertSee('It\'s 6:37 in the morning.')
            ->assertSee('What time do you get up?')
            ->assertDontSee('Shadow while watching')
            ->assertDontSeeHtml('shadowModeOn');
    }

    public function test_the_player_only_pauses_for_shadowing_on_the_second_watch(): void
    {
        $run = $this->makeRun();

        $html = Livewire::test('missions.steps.video-shadowing', ['run' => $run])->html();

        $this->assertStringContainsString('activeSubstep === 1', $html);
        $this->assertStringContainsString('First watch', $html);
        $this->assertStringContainsString('Watch again from the start', $html);
    }

    public function test_the_page_is_split_into_2_sub_steps(): void
    {
        $run = $this->makeRun();

        $html = Livewire::test('missions.steps.video-shadowing', ['run' => $run])->html();

        $this->assertStringContainsString('activeSubstep === 0', $html);
        $this->assertStringContainsString('activeSubstep === 1', $html);
        $this->assertStringContainsString('Part', $html);
    }

    public function test_there_is_no_watched_checkbox_to_tick(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.video-shadowing', ['run' => $run])
            ->assertDontSee('I watched with captions on')
            ->assertDontSeeHtml('wire:model.live="watchedWithCaptions"');
    }

    public function test_continue_stays_on_screen_but_disabled_until_enough_lines_are_shadowed(): void
    {
        $run = $this->makeRun();

        $component = Livewire::test('missions.steps.video-shadowing', ['run' => $run])
            ->assertSeeHtml('x-on:click="$wire.save()"')
            ->assertSeeHtml('x-bind:disabled="! (false)"')
            ->assertSee('Record 3 shadowed lines to continue');

        $this->recordLines($component, 0, 1)->assertSeeHtml('x-bind:disabled="! (false)"');
        $this->recordLines($component, 2)->assertSeeHtml('x-bind:disabled="! (true)"');
    }

    public function test_continue_is_blocked_with_fewer_than_3_shadowed_lines_even_after_watching(): void
    {
        $run = $this->makeRun();

        $this->recordLines(Livewire::test('missions.steps.video-shadowing', ['run' => $run]), 0, 1)
            ->call('save')
            ->assertHasErrors(['shadowRecordings']);

        $this->assertDatabaseCount('evidences', 0);
    }

    public function test_saving_with_3_shadowed_lines_needs_no_watch_confirmation_and_advances_the_run(): void
    {
        $run = $this->makeRun();

        $component = $this->recordLines(Livewire::test('missions.steps.video-shadowing', ['run' => $run]), 0, 2, 3)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('completed', true);

        $this->assertSame(4, Evidence::where('mission_run_id', $run->id)->where('phase', 'video_shadowing')->count());

        $textEvidence = Evidence::where('phase', 'video_shadowing')->where('type', Evidence::TYPE_TEXT)->first();
        $content = json_decode($textEvidence->content_ref, true);
        $this->assertSame([0, 2, 3], $content['shadowed_line_indices']);

        $audioEvidences = Evidence::where('phase', 'video_shadowing')->where('type', Evidence::TYPE_AUDIO)->get();
        $this->assertCount(3, $audioEvidences);
        $lineIndices = $audioEvidences->map(fn ($e) => json_decode($e->content_ref, true)['line_index'])->sort()->values();
        $this->assertSame([0, 2, 3], $lineIndices->all());

        $component->call('proceed')->assertRedirect(route('missions.show', $run->mission));
        $this->assertSame('daily_listen_3', $run->fresh()->currentStepKey());
    }

    public function test_shadowing_all_the_lines_still_works(): void
    {
        $run = $this->makeRun();

        $this->recordLines(Livewire::test('missions.steps.video-shadowing', ['run' => $run]), 0, 1, 2, 3)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('completed', true);

        $this->assertSame(4, Evidence::where('phase', 'video_shadowing')->where('type', Evidence::TYPE_AUDIO)->count());
    }

    public function test_the_video_does_not_count_or_ask_about_watches(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.video-shadowing', ['run' => $run])
            ->assertDontSeeHtml('eosListenTracker')
            ->assertDontSeeHtml('wire:click="reportListen"')
            ->assertDontSee('I watched this');
    }

    public function test_the_stress_tip_appears_once_not_per_line(): void
    {
        $run = $this->makeRun();

        $html = Livewire::test('missions.steps.video-shadowing', ['run' => $run])->html();

        $this->assertSame(1, substr_count($html, 'Bold words are usually stressed'));
    }

    public function test_every_shadow_line_renders_with_its_own_recorder(): void
    {
        $run = $this->makeRun();

        $html = Livewire::test('missions.steps.video-shadowing', ['run' => $run])->html();

        $this->assertStringContainsString('Line 1', $html);
        $this->assertStringContainsString('Line 2', $html);
        $this->assertStringContainsString('Line 3', $html);
        $this->assertStringContainsString('Line 4', $html);
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
            ->assertSet('savedShadowUrls.0', 'http://localhost/storage/missions/m01/evidence/video-shadow-0.webm')
            ->assertSet('savedShadowUrls.1', 'http://localhost/storage/missions/m01/evidence/video-shadow-1.webm');

        $component->assertSee('Not shadowed.');
        $component->assertDontSee('Quick check');
    }
}
