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
                            'recall_prompt' => 'Write one word or phrase you remember hearing.',
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
                            'recall_prompt' => 'Write a different word or phrase this time.',
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

    public function test_it_reuses_day_1s_audio_and_transcript(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.daily-listen-2', ['run' => $run])
            ->assertSee('Two minutes before anything else.')
            ->assertSeeHtml('http://localhost/storage/missions/m01/mornings.mp3')
            ->assertSee('Hello and welcome.')
            ->assertSee("And I'm Georgie.");
    }

    public function test_the_transcript_is_wired_behind_a_show_hide_toggle(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.daily-listen-2', ['run' => $run])
            ->assertSee('Show transcript')
            ->assertSeeHtml('showTranscript = !showTranscript')
            ->assertSeeHtml('x-show="showTranscript"');
    }

    public function test_continue_is_blocked_until_the_audio_has_played_once(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.daily-listen-2', ['run' => $run])
            ->set('recall', 'sleep in')
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
            ->set('recall', 'sleep in')
            ->call('save')
            ->assertRedirect(route('missions.show', $run->mission));

        $this->assertDatabaseHas('evidences', ['mission_run_id' => $run->id, 'phase' => 'daily_listen_2']);
        $this->assertSame('grammar_in_context', $run->fresh()->currentStepKey());
    }

    public function test_continue_is_blocked_until_a_recall_answer_is_written(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.daily-listen-2', ['run' => $run])
            ->call('markListened')
            ->call('save')
            ->assertHasErrors(['recall']);

        $this->assertDatabaseMissing('evidences', ['mission_run_id' => $run->id, 'phase' => 'daily_listen_2']);
    }

    public function test_the_recall_answer_is_saved_whatever_it_is_never_graded(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.daily-listen-2', ['run' => $run])
            ->call('markListened')
            ->set('recall', 'something totally unrelated')
            ->call('save');

        $evidence = Evidence::where('mission_run_id', $run->id)->where('phase', 'daily_listen_2')->firstOrFail();
        $content = json_decode($evidence->content_ref, true);
        $this->assertSame('something totally unrelated', $content['recall']);
    }

    public function test_each_day_has_its_own_recall_prompt(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.daily-listen-2', ['run' => $run])
            ->assertSee('Write one word or phrase you remember hearing.');

        Livewire::test('missions.steps.daily-listen-3', ['run' => $run])
            ->assertSee('Write a different word or phrase this time.');
    }

    public function test_matching_a_real_target_phrase_is_passed_to_the_page(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.daily-listen-2', ['run' => $run])
            ->assertSeeHtml('sleep in');
    }

    public function test_read_only_mode_shows_the_previously_saved_recall_answer(): void
    {
        $run = $this->makeRun();

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'daily_listen_2',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode(['listened' => true, 'recall' => 'oversleep']),
        ]);

        Livewire::test('missions.steps.daily-listen-2', ['run' => $run, 'readOnly' => true])
            ->assertSet('recall', 'oversleep');
    }

    public function test_each_day_needs_its_own_fresh_listen_not_satisfied_by_another_day(): void
    {
        $run = $this->makeRun();

        // Day 2's gate is done...
        Livewire::test('missions.steps.daily-listen-2', ['run' => $run])
            ->call('markListened')
            ->set('recall', 'sleep in')
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
            ->set('recall', 'sleep in')
            ->call('save');

        Livewire::test('missions.steps.daily-listen-3', ['run' => $run])
            ->assertSee('Same audio, one more time.')
            ->call('markListened')
            ->set('recall', 'oversleep')
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
            'content_ref' => json_encode(['listened' => true, 'recall' => 'sleep in']),
        ]);

        Livewire::test('missions.steps.daily-listen-2', ['run' => $run, 'readOnly' => true])
            ->assertSet('listened', true)
            ->assertDontSee('Continue');
    }
}
