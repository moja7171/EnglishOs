<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

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
                        ],
                    ],
                ],
                [
                    'phase' => 'build',
                    'steps' => [
                        ['key' => 'daily_listen_2', 'hook' => 'Two minutes before anything else.'],
                        ['key' => 'grammar_in_context'],
                    ],
                ],
                [
                    'phase' => 'practice',
                    'steps' => [
                        ['key' => 'daily_listen_3', 'hook' => 'Same audio, one more time.'],
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

    public function test_it_reuses_day_1s_audio_and_transcript(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.daily-listen-2', ['run' => $run])
            ->assertSee('Two minutes before anything else.')
            ->assertSeeHtml('http://localhost/storage/missions/m01/mornings.mp3')
            ->assertSee('Hello and welcome.')
            ->assertSee("And I'm Georgie.");
    }

    public function test_continue_is_blocked_until_the_audio_has_played_once(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.daily-listen-2', ['run' => $run])
            ->call('save');

        $this->assertDatabaseMissing('evidences', ['mission_run_id' => $run->id, 'phase' => 'daily_listen_2']);
        $this->assertSame('daily_listen_2', $run->fresh()->currentStepKey());
    }

    public function test_marking_listened_then_saving_records_evidence_and_advances(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.daily-listen-2', ['run' => $run])
            ->call('markListened')
            ->assertSet('listened', true)
            ->call('save')
            ->assertRedirect(route('missions.show', $run->mission));

        $this->assertDatabaseHas('evidences', ['mission_run_id' => $run->id, 'phase' => 'daily_listen_2']);
        $this->assertSame('grammar_in_context', $run->fresh()->currentStepKey());
    }

    public function test_each_day_needs_its_own_fresh_listen_not_satisfied_by_another_day(): void
    {
        $run = $this->makeRun();

        // Day 2's gate is done...
        Livewire::test('missions.steps.daily-listen-2', ['run' => $run])
            ->call('markListened')
            ->call('save');

        // ...but Day 3's gate is a completely separate step key, still open.
        $this->assertSame('grammar_in_context', $run->fresh()->currentStepKey());
        $this->assertDatabaseMissing('evidences', ['mission_run_id' => $run->id, 'phase' => 'daily_listen_3']);
    }

    public function test_day_3s_gate_uses_its_own_distinct_phase_key(): void
    {
        $run = $this->makeRun();

        // Day 2's gate already passed.
        Livewire::test('missions.steps.daily-listen-2', ['run' => $run])
            ->call('markListened')
            ->call('save');

        Livewire::test('missions.steps.daily-listen-3', ['run' => $run])
            ->assertSee('Same audio, one more time.')
            ->call('markListened')
            ->call('save');

        $this->assertDatabaseHas('evidences', ['mission_run_id' => $run->id, 'phase' => 'daily_listen_3']);
        // grammar_in_context (Day 2's second step) was never done — still
        // the real current step, confirming daily_listen_3 didn't skip
        // anything or get satisfied by daily_listen_2's Evidence.
        $this->assertSame('grammar_in_context', $run->fresh()->currentStepKey());
    }

    public function test_read_only_mode_never_requires_listening_again(): void
    {
        $run = $this->makeRun();

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'daily_listen_2',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => '1',
        ]);

        Livewire::test('missions.steps.daily-listen-2', ['run' => $run, 'readOnly' => true])
            ->assertSet('listened', true)
            ->assertDontSee('Continue');
    }
}
