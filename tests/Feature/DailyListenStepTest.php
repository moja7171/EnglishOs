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
use RuntimeException;
use Tests\TestCase;

/**
 * Mission structure redesign, Epic C: the old "listen once, then type any
 * word you remember" recall was replaced with a small, mandatory,
 * leniently AI-graded shadowing exercise — see DailyListenStep.
 */
class DailyListenStepTest extends TestCase
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
                    'phase' => 'foundation',
                    'steps' => [
                        [
                            'key' => 'listening',
                            'source' => 'BBC Learning English — Real Easy English: Mornings',
                            'audio_url' => 'http://localhost/storage/missions/m01/mornings.mp3',
                            'transcript' => [
                                ['speaker' => 'Neil', 'text' => 'Hello and welcome.'],
                                ['speaker' => 'Georgie', 'text' => "And I'm Georgie."],
                            ],
                            'listening_segments' => [
                                ['text' => 'Hello and welcome to the show.', 'start' => 0.0, 'end' => 3.0],
                                ['text' => "And I'm Georgie.", 'start' => 3.0, 'end' => 5.0],
                            ],
                            'target_phrases' => [
                                ['phrase' => 'sleep in', 'meaning' => 'to stay in bed longer than usual'],
                            ],
                        ],
                    ],
                ],
                [
                    'phase' => 'build',
                    'steps' => [
                        [
                            'key' => 'daily_listen_2',
                            'hook' => 'Two minutes before anything else.',
                            'shadow_lines' => ['I usually **get up** early.', 'I **never** **skip breakfast**.'],
                        ],
                        ['key' => 'grammar_in_context'],
                    ],
                ],
                [
                    'phase' => 'practice',
                    'steps' => [
                        [
                            'key' => 'daily_listen_3',
                            'hook' => 'Same audio, one more time.',
                            'shadow_lines' => ["**Otherwise** I'm **grumpy**.", '**Sometimes** I **oversleep**.'],
                        ],
                        ['key' => 'ai_conversation_1'],
                    ],
                ],
            ],
        ]);

        // The real Day 1 Listening already happened.
        Evidence::create([
            'mission_run_id' => MissionRun::findOrStart($learner, $mission)->id,
            'phase' => 'listening',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => '{}',
        ]);

        $this->actingAs($learner);

        return MissionRun::findOrStart($learner, $mission);
    }

    private function mockLenientCheck(int $times = 1): void
    {
        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->times($times)->andReturn('I usually get up early.'));
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->times($times)->andReturn(json_encode(['severity' => 'none', 'hint' => ''])));
    }

    public function test_it_reuses_day_1s_audio(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.daily-listen-2', ['run' => $run])
            ->assertSee('Two minutes before anything else.')
            ->assertSeeHtml('http://localhost/storage/missions/m01/mornings.mp3');
    }

    /**
     * Real behavior redesign: shadowing moved entirely to Listen Again,
     * driven by real Whisper timing (missions:cache-shadow-timestamps)
     * instead of a plain static transcript behind a show/hide toggle —
     * that toggle is gone; the synced text panel (Day 1's own real
     * listening_segments, reused here) is always visible instead.
     */
    public function test_the_synced_text_panel_shows_day_1s_real_segments(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.daily-listen-2', ['run' => $run])
            ->assertSee('Hello and welcome to the show.')
            ->assertSee("And I'm Georgie.")
            ->assertDontSee('Show transcript');
    }

    public function test_the_whole_episode_comes_first_then_each_line_and_the_last_one_finishes(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.daily-listen-2', ['run' => $run])
            ->assertSeeInOrder(['Listen first', 'Say it · 1 of', 'Say it · 2 of'])
            ->assertSee('Listens:')
            ->assertSee('Finish');
    }

    public function test_does_not_show_a_pi_practice_card_inside_the_step(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.daily-listen-2', ['run' => $run])
            ->assertDontSee('chat in your voice AI app');
    }

    public function test_this_days_own_shadow_lines_are_shown(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.daily-listen-2', ['run' => $run])
            ->assertSee('get up')
            ->assertSee('skip breakfast');

        Livewire::test('missions.steps.daily-listen-3', ['run' => $run])
            ->assertSee('grumpy')
            ->assertSee('oversleep');
    }

    public function test_shadowing_every_line_and_saving_records_evidence_and_advances(): void
    {
        $run = $this->makeRun();
        $this->mockLenientCheck(2);

        Livewire::test('missions.steps.daily-listen-2', ['run' => $run])
            ->set('shadowRecordings.0', UploadedFile::fake()->create('shadow-0.webm', 200, 'audio/webm'))
            ->call('checkShadowLine', 0)
            ->set('shadowRecordings.1', UploadedFile::fake()->create('shadow-1.webm', 200, 'audio/webm'))
            ->call('checkShadowLine', 1)
            ->call('save')
            ->assertRedirect(route('missions.show', $run->mission));

        $this->assertDatabaseHas('evidences', ['mission_run_id' => $run->id, 'phase' => 'daily_listen_2', 'type' => Evidence::TYPE_TEXT]);
        $this->assertSame(2, Evidence::where('mission_run_id', $run->id)->where('phase', 'daily_listen_2')->where('type', Evidence::TYPE_AUDIO)->count());
        $this->assertSame('grammar_in_context', $run->fresh()->currentStepKey());
    }

    public function test_continue_never_waits_for_the_audio_to_play_only_for_the_shadowed_lines(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.daily-listen-2', ['run' => $run])
            ->assertSee('Say it · 1 of 2')
            ->assertDontSee('Listen at least once')
            ->call('save')
            ->assertHasErrors(['shadowRecordings']);

        $this->assertDatabaseMissing('evidences', ['mission_run_id' => $run->id, 'phase' => 'daily_listen_2']);
    }

    public function test_continue_is_blocked_until_every_line_is_shadowed(): void
    {
        $run = $this->makeRun();
        $this->mockLenientCheck(1);

        Livewire::test('missions.steps.daily-listen-2', ['run' => $run])
            ->set('shadowRecordings.0', UploadedFile::fake()->create('shadow-0.webm', 200, 'audio/webm'))
            ->call('checkShadowLine', 0)
            // Line 1 never shadowed.
            ->call('save')
            ->assertHasErrors(['shadowRecordings']);

        $this->assertDatabaseMissing('evidences', ['mission_run_id' => $run->id, 'phase' => 'daily_listen_2']);
    }

    public function test_a_take_counts_even_with_a_major_verdict_and_shows_its_hint(): void
    {
        $run = $this->makeRun();
        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->once()->andReturn('completely unrelated'));
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn(json_encode(['severity' => 'major', 'hint' => 'Try that line again.'])));

        $component = Livewire::test('missions.steps.daily-listen-2', ['run' => $run])
            ->set('shadowRecordings.0', UploadedFile::fake()->create('shadow-0.webm', 200, 'audio/webm'))
            ->call('checkShadowLine', 0);

        // Lesson design 4: any take moves the learner on ("Almost" included).
        $this->assertSame(1, $component->instance()->shadowedCount());
        $component->assertSee('Try that line again.')->assertSee('Almost!');
    }

    public function test_the_verdict_sheet_and_the_waiting_continue_are_keyed_apart_so_a_re_render_cannot_merge_them(): void
    {
        $run = $this->makeRun();
        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->once()->andReturn('completely unrelated'));
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn(json_encode(['severity' => 'major', 'hint' => 'Try that line again.'])));

        Livewire::test('missions.steps.daily-listen-2', ['run' => $run])
            ->assertSeeHtml('wire:key="shadow-waiting-0"')
            ->assertDontSeeHtml('wire:key="shadow-sheet-0"')
            ->set('shadowRecordings.0', UploadedFile::fake()->create('shadow-0.webm', 200, 'audio/webm'))
            ->call('checkShadowLine', 0)
            ->assertSeeHtml('wire:key="shadow-sheet-0"')
            ->assertDontSeeHtml('wire:key="shadow-waiting-0"');
    }

    public function test_an_unreachable_ai_does_not_block_a_shadowed_line_and_hides_its_error(): void
    {
        $run = $this->makeRun();
        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->twice()->andThrow(new RuntimeException('GROQ_API_KEY is not set.')));

        $component = Livewire::test('missions.steps.daily-listen-2', ['run' => $run])
            ->set('shadowRecordings.0', UploadedFile::fake()->create('shadow-0.webm', 200, 'audio/webm'))
            ->call('checkShadowLine', 0)
            ->set('shadowRecordings.1', UploadedFile::fake()->create('shadow-1.webm', 200, 'audio/webm'))
            ->call('checkShadowLine', 1)
            ->assertSee('your take is saved and it counts')
            ->assertDontSee('GROQ_API_KEY')
            ->call('save')
            ->assertRedirect(route('missions.show', $run->mission));

        $this->assertSame(2, $component->instance()->shadowedCount());
        $this->assertSame('grammar_in_context', $run->fresh()->currentStepKey());
    }

    public function test_each_day_has_its_own_distinct_shadow_lines(): void
    {
        $run = $this->makeRun();

        $day2Lines = Livewire::test('missions.steps.daily-listen-2', ['run' => $run])->instance()->shadowLines();
        $day3Lines = Livewire::test('missions.steps.daily-listen-3', ['run' => $run])->instance()->shadowLines();

        $this->assertEmpty(array_intersect($day2Lines, $day3Lines));
    }

    public function test_read_only_mode_shows_the_previously_saved_recording(): void
    {
        $run = $this->makeRun();

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'daily_listen_2',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode(['shadowed_lines' => 2]),
        ]);
        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'daily_listen_2',
            'type' => Evidence::TYPE_AUDIO,
            'content_ref' => json_encode(['line_index' => 0, 'url' => 'http://localhost/storage/shadow-0.webm']),
        ]);

        Livewire::test('missions.steps.daily-listen-2', ['run' => $run, 'readOnly' => true])
            ->assertSeeHtml('http://localhost/storage/shadow-0.webm')
            ->assertDontSee('Continue');
    }

    public function test_each_day_needs_its_own_fresh_listen_not_satisfied_by_another_day(): void
    {
        $run = $this->makeRun();
        $this->mockLenientCheck(2);

        Livewire::test('missions.steps.daily-listen-2', ['run' => $run])
            ->set('shadowRecordings.0', UploadedFile::fake()->create('shadow-0.webm', 200, 'audio/webm'))
            ->call('checkShadowLine', 0)
            ->set('shadowRecordings.1', UploadedFile::fake()->create('shadow-1.webm', 200, 'audio/webm'))
            ->call('checkShadowLine', 1)
            ->call('save');

        // ...but Day 3's gate is a completely separate step key, still open.
        $this->assertSame('grammar_in_context', $run->fresh()->currentStepKey());
        $this->assertDatabaseMissing('evidences', ['mission_run_id' => $run->id, 'phase' => 'daily_listen_3']);
    }

    public function test_a_cover_image_shows_when_the_day_has_its_own_image_query(): void
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [[
                'phase' => 'build',
                'steps' => [['key' => 'daily_listen_2', 'image_query' => 'sunrise bedroom window']],
            ]],
        ]);
        $run = MissionRun::findOrStart($learner, $mission);
        $this->actingAs($learner);

        $this->mock(PexelsClient::class, function ($mock) {
            $mock->shouldReceive('imageUrlFor')
                ->with('M01-daily_listen_2', 'sunrise bedroom window')
                ->once()
                ->andReturn('http://localhost/storage/vocabulary-images/m01-daily_listen_2.jpg');
        });

        Livewire::test('missions.steps.daily-listen-2', ['run' => $run])
            ->assertSeeHtml('http://localhost/storage/vocabulary-images/m01-daily_listen_2.jpg');
    }

    public function test_no_cover_image_without_a_day_image_query(): void
    {
        $run = $this->makeRun();

        $this->mock(PexelsClient::class, fn ($mock) => $mock->shouldNotReceive('imageUrlFor'));

        Livewire::test('missions.steps.daily-listen-2', ['run' => $run])->assertDontSeeHtml('<img');
    }

    public function test_a_checked_line_comes_back_with_its_verdict_after_leaving_the_step(): void
    {
        Storage::fake('public');
        $run = $this->makeRun();
        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->once()->andReturn('I usually get up.'));
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn(json_encode(['severity' => 'minor', 'hint' => 'Say "early" too.'])));

        Livewire::test('missions.steps.daily-listen-2', ['run' => $run])
            ->set('shadowRecordings.0', UploadedFile::fake()->create('shadow-0.webm', 200, 'audio/webm'))
            ->call('checkShadowLine', 0);

        // The learner left; the step opens fresh, with no new AI call.
        Livewire::test('missions.steps.daily-listen-2', ['run' => $run])
            ->assertSee('Say "early" too.')
            ->assertSet('draftShadowUrls', fn (array $urls) => array_keys($urls) === [0]);
    }
}
