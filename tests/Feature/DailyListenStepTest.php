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
use Livewire\Livewire;
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
            ->call('markListened')
            ->assertSet('listened', true)
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

    public function test_continue_is_blocked_until_the_audio_has_played_once(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.daily-listen-2', ['run' => $run])->call('save');

        $this->assertDatabaseMissing('evidences', ['mission_run_id' => $run->id, 'phase' => 'daily_listen_2']);
        $this->assertSame('daily_listen_2', $run->fresh()->currentStepKey());
    }

    public function test_continue_is_blocked_until_every_line_is_shadowed(): void
    {
        $run = $this->makeRun();
        $this->mockLenientCheck(1);

        Livewire::test('missions.steps.daily-listen-2', ['run' => $run])
            ->call('markListened')
            ->set('shadowRecordings.0', UploadedFile::fake()->create('shadow-0.webm', 200, 'audio/webm'))
            ->call('checkShadowLine', 0)
            // Line 1 never shadowed.
            ->call('save')
            ->assertHasErrors(['shadowRecordings']);

        $this->assertDatabaseMissing('evidences', ['mission_run_id' => $run->id, 'phase' => 'daily_listen_2']);
    }

    public function test_a_major_verdict_does_not_count_toward_the_requirement(): void
    {
        $run = $this->makeRun();
        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->once()->andReturn('completely unrelated'));
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn(json_encode(['severity' => 'major', 'hint' => 'Try that line again.'])));

        $component = Livewire::test('missions.steps.daily-listen-2', ['run' => $run])
            ->call('markListened')
            ->set('shadowRecordings.0', UploadedFile::fake()->create('shadow-0.webm', 200, 'audio/webm'))
            ->call('checkShadowLine', 0);

        $this->assertSame(0, $component->instance()->shadowedCount());
        $component->assertSee('Try that line again.');
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
            'content_ref' => json_encode(['listened' => true, 'shadowed_lines' => 2]),
        ]);
        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'daily_listen_2',
            'type' => Evidence::TYPE_AUDIO,
            'content_ref' => json_encode(['line_index' => 0, 'url' => 'http://localhost/storage/shadow-0.webm']),
        ]);

        Livewire::test('missions.steps.daily-listen-2', ['run' => $run, 'readOnly' => true])
            ->assertSet('listened', true)
            ->assertSeeHtml('http://localhost/storage/shadow-0.webm')
            ->assertDontSee('Continue');
    }

    public function test_each_day_needs_its_own_fresh_listen_not_satisfied_by_another_day(): void
    {
        $run = $this->makeRun();
        $this->mockLenientCheck(2);

        Livewire::test('missions.steps.daily-listen-2', ['run' => $run])
            ->call('markListened')
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
}
