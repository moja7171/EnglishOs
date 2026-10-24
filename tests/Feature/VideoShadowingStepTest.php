<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\ShadowDraft;
use App\Models\User;
use App\Services\GeminiClient;
use App\Services\GroqClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
        $this->assertStringContainsString('It pauses after each line', $html);
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

    public function test_the_watch_counter_offers_an_i_watched_this_button(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.video-shadowing', ['run' => $run])
            ->assertSeeHtml('eosListenTracker')
            ->assertSeeHtml('wire:click="reportListen"')
            ->assertSee('I watched this');
    }

    public function test_the_stress_tip_appears_once_not_per_line(): void
    {
        $run = $this->makeRun();

        $html = Livewire::test('missions.steps.video-shadowing', ['run' => $run])->html();

        $this->assertSame(1, substr_count($html, 'Bold = stressed'));
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

    public function test_recorded_lines_come_back_after_leaving_the_step_and_count_when_saved(): void
    {
        Storage::fake('public');
        $run = $this->makeRun();

        $this->recordLines(Livewire::test('missions.steps.video-shadowing', ['run' => $run]), 0, 1);
        $kept = ShadowDraft::where('mission_run_id', $run->id)->orderBy('line_index')->get();

        // The learner left; the step opens fresh.
        $component = Livewire::test('missions.steps.video-shadowing', ['run' => $run])
            ->assertSeeHtml(Storage::disk('public')->url($kept[0]->path))
            ->assertSeeHtml(Storage::disk('public')->url($kept[1]->path));
        $this->assertSame(2, $component->instance()->shadowedCount());

        $this->recordLines($component, 2)->call('save')->assertHasNoErrors();

        $this->assertSame(3, Evidence::where('mission_run_id', $run->id)->where('phase', 'video_shadowing')->where('type', Evidence::TYPE_AUDIO)->count());
        $this->assertDatabaseCount('shadow_drafts', 0);
        Storage::disk('public')->assertExists($kept[0]->path);
    }

    public function test_a_new_take_replaces_the_lines_kept_recording(): void
    {
        Storage::fake('public');
        $run = $this->makeRun();
        $component = $this->recordLines(Livewire::test('missions.steps.video-shadowing', ['run' => $run]), 0);
        $firstPath = ShadowDraft::sole()->path;

        $this->recordLines($component, 0);

        $this->assertDatabaseCount('shadow_drafts', 1);
        $this->assertNotSame($firstPath, ShadowDraft::sole()->path);
        Storage::disk('public')->assertMissing($firstPath);
    }

    public function test_a_recorded_line_is_checked_by_the_ai_and_its_verdict_comes_back_after_leaving(): void
    {
        Storage::fake('public');
        $run = $this->makeRun();
        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->once()->andReturn("I don't feel like having cereal."));
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn(json_encode(['severity' => 'none', 'hint' => ''])));

        $this->recordLines(Livewire::test('missions.steps.video-shadowing', ['run' => $run]), 0)
            ->call('checkShadowLine', 0)
            ->assertSee('Nice — that counts.');

        // The learner left; the step opens fresh, with no new AI call.
        Livewire::test('missions.steps.video-shadowing', ['run' => $run])
            ->assertSee('Nice — that counts.');
    }

    public function test_a_line_the_ai_heard_as_a_different_sentence_does_not_count_until_retried(): void
    {
        Storage::fake('public');
        $run = $this->makeRun();
        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->once()->andReturn('completely unrelated'));
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn(json_encode(['severity' => 'major', 'hint' => 'Try that line again.'])));

        $component = $this->recordLines(Livewire::test('missions.steps.video-shadowing', ['run' => $run]), 0, 1, 2)
            ->call('checkShadowLine', 0)
            ->assertSee('Try that line again.');

        $this->assertSame(2, $component->instance()->shadowedCount());
        $component->call('save')->assertHasErrors(['shadowRecordings']);
        $this->assertDatabaseCount('evidences', 0);
    }

    public function test_an_unreachable_ai_still_counts_the_recorded_line(): void
    {
        Storage::fake('public');
        $run = $this->makeRun();
        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->once()->andThrow(new \RuntimeException('Whisper is down.')));

        $component = $this->recordLines(Livewire::test('missions.steps.video-shadowing', ['run' => $run]), 0)
            ->call('checkShadowLine', 0)
            ->assertSee("Couldn't check this one just now", false);

        $this->assertSame(1, $component->instance()->shadowedCount());
    }
}
