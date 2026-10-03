<?php

namespace Tests\Feature;

use App\Models\AudioListen;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The "Listens: N" counter beside the in-app audio and video players. The
 * 90%-played-through rule itself runs in the browser (eosListenTracker);
 * what is covered here is what the server does with a completed listen.
 */
class AudioListenCounterTest extends TestCase
{
    use RefreshDatabase;

    private function counter(User $learner, string $mission = 'M01', string $source = AudioListen::SOURCE_LISTENING)
    {
        $this->actingAs($learner);

        return Livewire::test('listen-counter', ['missionCode' => $mission, 'source' => $source]);
    }

    public function test_it_starts_from_the_learners_existing_listens_only(): void
    {
        $learner = User::factory()->create();
        AudioListen::factory()->count(2)->create(['learner_id' => $learner->id]);
        AudioListen::factory()->create(['learner_id' => $learner->id, 'mission_code' => 'M02']);
        AudioListen::factory()->create(['learner_id' => $learner->id, 'source' => AudioListen::SOURCE_VIDEO_SHADOWING]);
        AudioListen::factory()->create();

        $this->counter($learner)->assertSeeHtml('data-testid="listen-count">2<');
    }

    public function test_a_completed_listen_is_stored_and_counted(): void
    {
        $learner = User::factory()->create();

        $this->counter($learner)
            ->dispatch('listen-completed', missionCode: 'M01', source: 'listening', duration: 120.0)
            ->assertSeeHtml('data-testid="listen-count">1<');

        $this->assertSame(1, $learner->audioListenCount('M01', AudioListen::SOURCE_LISTENING));
    }

    public function test_a_listen_meant_for_another_source_is_ignored(): void
    {
        $learner = User::factory()->create();

        $this->counter($learner)
            ->dispatch('listen-completed', missionCode: 'M01', source: 'video_shadowing', duration: 120.0)
            ->dispatch('listen-completed', missionCode: 'M02', source: 'listening', duration: 120.0);

        $this->assertSame(0, $learner->audioListens()->count());
    }

    public function test_a_second_listen_arriving_faster_than_the_recording_lasts_is_not_counted(): void
    {
        $learner = User::factory()->create();

        $component = $this->counter($learner)
            ->dispatch('listen-completed', missionCode: 'M01', source: 'listening', duration: 120.0)
            ->dispatch('listen-completed', missionCode: 'M01', source: 'listening', duration: 120.0);

        $this->assertSame(1, $learner->audioListens()->count());

        $this->travel(2)->minutes();

        $component->dispatch('listen-completed', missionCode: 'M01', source: 'listening', duration: 120.0);

        $this->assertSame(2, $learner->audioListens()->count());
    }

    public function test_the_players_show_the_chip_only_when_asked_to_count(): void
    {
        $this->actingAs(User::factory()->create());

        $this->assertStringContainsString('eosListenTracker', $this->renderPlayer('audio-player', ['listen' => ['mission_code' => 'M01', 'source' => 'listening']]));
        $this->assertStringNotContainsString('eosListenTracker', $this->renderPlayer('audio-player', []));
        $this->assertStringContainsString('eosListenTracker', $this->renderPlayer('video-player', ['listen' => ['mission_code' => 'M01', 'source' => 'video_shadowing']]));
        $this->assertStringNotContainsString('eosListenTracker', $this->renderPlayer('video-player', []));
    }

    /**
     * @param  array<string, mixed>  $props
     */
    private function renderPlayer(string $component, array $props): string
    {
        return Blade::render(
            '<x-dynamic-component :component="$component" url="http://localhost/media" :listen="$listen" />',
            ['component' => $component, 'listen' => $props['listen'] ?? null],
        );
    }

    public function test_the_listening_recap_reports_how_many_times_the_episode_was_heard(): void
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [[
                'phase' => 'foundation',
                'steps' => [[
                    'key' => 'listening',
                    'source' => 'BBC',
                    'audio_url' => 'http://localhost/storage/missions/m01/mornings.mp3',
                    'target_phrases' => [
                        ['phrase' => 'sleep in', 'meaning' => 'to stay in bed', 'gap_before' => 'I like to ', 'gap_after' => ' at weekends.'],
                    ],
                ]],
            ]],
        ]);
        $this->actingAs($learner);
        $run = MissionRun::findOrStart($learner, $mission);
        AudioListen::factory()->count(3)->create(['learner_id' => $learner->id]);

        Livewire::test('missions.steps.listening', ['run' => $run])
            ->set('gapFillSelections.0', 'sleep in')
            ->call('save')
            ->assertSee('listened to this episode 3 times');
    }
}
