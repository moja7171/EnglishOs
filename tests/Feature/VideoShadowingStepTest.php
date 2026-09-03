<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use App\Services\GeminiClient;
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
                            'topic_summary' => 'An English-speaking mother shows her morning routine: '
                                .'making breakfast, getting together with her family, and getting the kids '
                                .'ready for school.',
                            'target_phrases' => [
                                ['phrase' => 'feel like (something)', 'meaning' => 'to want something at that particular moment'],
                                ['phrase' => 'get together', 'meaning' => 'to meet up and spend time with someone'],
                            ],
                            'shadow_lines' => [
                                "I don't **feel like** having **cereal** this **morning**.",
                                'What **time** are you guys **getting together**?',
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

    private function fillRequiredFields($component)
    {
        return $component
            ->set('watchedWithCaptions', true)
            ->set('watchedWithoutCaptions', true)
            ->set('noticedSentence', 'She makes her son an egg instead of cereal.')
            ->set('expressionSentence', 'I never feel like cereal in winter.')
            ->call('selectShadowLine', 0)
            ->set('shadowRecording', UploadedFile::fake()->create('video-shadow.webm', 500, 'audio/webm'));
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
            ->set('noticedSentence', 'She makes her son an egg instead of cereal.')
            ->set('expressionSentence', 'I never feel like cereal in winter.')
            ->call('selectShadowLine', 0)
            ->set('shadowRecording', UploadedFile::fake()->create('video-shadow.webm', 500, 'audio/webm'))
            ->call('save')
            ->assertHasErrors(['watched']);

        $this->assertDatabaseCount('evidences', 0);
    }

    public function test_continue_is_blocked_without_a_shadow_recording(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.video-shadowing', ['run' => $run])
            ->set('watchedWithCaptions', true)
            ->set('watchedWithoutCaptions', true)
            ->set('noticedSentence', 'She makes her son an egg instead of cereal.')
            ->set('expressionSentence', 'I never feel like cereal in winter.')
            ->call('save')
            ->assertHasErrors(['shadowRecording']);

        $this->assertDatabaseCount('evidences', 0);
    }

    public function test_continue_is_blocked_until_both_sentences_are_written(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.video-shadowing', ['run' => $run])
            ->set('watchedWithCaptions', true)
            ->set('watchedWithoutCaptions', true)
            ->set('noticedSentence', 'She makes her son an egg instead of cereal.')
            ->call('selectShadowLine', 0)
            ->set('shadowRecording', UploadedFile::fake()->create('video-shadow.webm', 500, 'audio/webm'))
            ->call('save')
            ->assertHasErrors(['sentences']);

        $this->assertDatabaseCount('evidences', 0);
    }

    public function test_saving_records_two_evidence_rows_and_advances_the_run(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->twice()->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        $component = $this->fillRequiredFields(Livewire::test('missions.steps.video-shadowing', ['run' => $run]))
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('completed', true);

        $this->assertSame(2, Evidence::where('mission_run_id', $run->id)->where('phase', 'video_shadowing')->count());

        $textEvidence = Evidence::where('phase', 'video_shadowing')->where('type', Evidence::TYPE_TEXT)->first();
        $content = json_decode($textEvidence->content_ref, true);
        $this->assertTrue($content['watched_with_captions']);
        $this->assertTrue($content['watched_without_captions']);
        $this->assertSame(0, $content['shadow_line_index']);

        $audioEvidence = Evidence::where('phase', 'video_shadowing')->where('type', Evidence::TYPE_AUDIO)->first();
        $this->assertNotNull($audioEvidence->content_ref);

        $component->call('proceed')->assertRedirect(route('missions.show', $run->mission));
        $this->assertSame('daily_listen_3', $run->fresh()->currentStepKey());
    }

    public function test_a_major_issue_on_either_sentence_blocks_continue(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->once()
                ->andReturn(json_encode(['severity' => 'none', 'hint' => '']))
                ->ordered();
            $mock->shouldReceive('chat')
                ->once()
                ->andReturn(json_encode(['severity' => 'major', 'hint' => 'This is unrelated to the video.']))
                ->ordered();
        });

        $this->fillRequiredFields(Livewire::test('missions.steps.video-shadowing', ['run' => $run]))
            ->set('expressionSentence', 'I really enjoy playing video games at night.')
            ->call('save')
            ->assertHasErrors(['sentences']);

        $this->assertDatabaseCount('evidences', 0);
    }

    public function test_selecting_a_shadow_line_clears_any_previous_recording(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.video-shadowing', ['run' => $run])
            ->assertSee('Line 1')
            ->assertSee('Line 2')
            ->call('selectShadowLine', 1)
            ->assertSet('activeShadowLine', 1)
            ->assertSet('shadowRecording', null)
            ->assertSee('getting together');
    }

    public function test_a_selected_shadow_lines_stressed_words_render_bolded(): void
    {
        $run = $this->makeRun();

        $html = Livewire::test('missions.steps.video-shadowing', ['run' => $run])
            ->call('selectShadowLine', 0)
            ->html();

        $this->assertStringContainsString('<strong', $html);
        $this->assertStringContainsString('>feel like</strong>', $html);
        $this->assertStringNotContainsString('**', $html);
    }

    public function test_read_only_mode_maps_saved_answers_back(): void
    {
        $run = $this->makeRun();

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'video_shadowing',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode([
                'noticed_sentence' => 'She makes her son an egg.',
                'expression_sentence' => 'I never feel like cereal in winter.',
                'watched_with_captions' => true,
                'watched_without_captions' => true,
                'shadow_line_index' => 1,
            ]),
        ]);
        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'video_shadowing',
            'type' => Evidence::TYPE_AUDIO,
            'content_ref' => 'http://localhost/storage/missions/m01/evidence/video-shadow.webm',
        ]);

        Livewire::test('missions.steps.video-shadowing', ['run' => $run, 'readOnly' => true])
            ->assertSet('noticedSentence', 'She makes her son an egg.')
            ->assertSet('expressionSentence', 'I never feel like cereal in winter.')
            ->assertSet('watchedWithCaptions', true)
            ->assertDontSee('Quick check');
    }
}
