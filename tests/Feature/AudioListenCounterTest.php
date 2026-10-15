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

    /**
     * @param  array<string, mixed>  $extra  dayNumber, pickKey, selfReportable
     */
    private function counter(User $learner, string $mission = 'M01', string $source = AudioListen::SOURCE_LISTENING, array $extra = [])
    {
        $this->actingAs($learner);

        return Livewire::test('listen-counter', ['missionCode' => $mission, 'source' => $source] + $extra);
    }

    /**
     * @return array<string, mixed>
     */
    private function pickProps(string $pickKey = '2-0', bool $selfReportable = false): array
    {
        return ['dayNumber' => 2, 'pickKey' => $pickKey, 'selfReportable' => $selfReportable];
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

    public function test_a_genuine_listen_at_top_speed_right_after_the_last_one_still_counts(): void
    {
        $learner = User::factory()->create();

        $component = $this->counter($learner)
            ->dispatch('listen-completed', missionCode: 'M01', source: 'listening', duration: 120.0);

        // 90% of 120s at 1.25x is 86s of real time — less than the old 0.85 x 120 = 102s gap.
        $this->travel(90)->seconds();

        $component->dispatch('listen-completed', missionCode: 'M01', source: 'listening', duration: 120.0);

        $this->assertSame(2, $learner->audioListens()->count());
    }

    public function test_a_listen_is_filed_under_the_mission_day_it_happened_on(): void
    {
        $learner = User::factory()->create();

        $this->counter($learner, extra: ['dayNumber' => 3])
            ->dispatch('listen-completed', missionCode: 'M01', source: 'listening', duration: 120.0);

        $this->assertDatabaseHas('audio_listens', ['learner_id' => $learner->id, 'day_number' => 3, 'pick_key' => null, 'self_reported' => false]);
    }

    public function test_every_pick_keeps_its_own_count(): void
    {
        $learner = User::factory()->create();
        AudioListen::factory()->pick(2, 1)->create(['learner_id' => $learner->id]);

        $first = $this->counter($learner, source: AudioListen::SOURCE_LISTENING_PICK, extra: $this->pickProps('2-0'))
            ->assertSeeHtml('data-testid="listen-count">0<')
            ->dispatch('listen-completed', missionCode: 'M01', source: 'listening_pick', pickKey: '2-1', duration: 120.0)
            ->assertSeeHtml('data-testid="listen-count">0<')
            ->dispatch('listen-completed', missionCode: 'M01', source: 'listening_pick', pickKey: '2-0', duration: 120.0)
            ->assertSeeHtml('data-testid="listen-count">1<');

        $this->assertDatabaseHas('audio_listens', ['learner_id' => $learner->id, 'source' => 'listening_pick', 'pick_key' => '2-0', 'day_number' => 2]);
        $this->assertSame(1, $learner->audioListenCount('M01', AudioListen::SOURCE_LISTENING_PICK, '2-0'));
        $this->assertSame(1, $learner->audioListenCount('M01', AudioListen::SOURCE_LISTENING_PICK, '2-1'));
        $this->assertSame(0, $learner->audioListenCount('M01', AudioListen::SOURCE_LISTENING));
    }

    public function test_a_pick_that_plays_outside_the_app_is_counted_only_when_the_learner_says_so(): void
    {
        $learner = User::factory()->create();

        $this->counter($learner, source: AudioListen::SOURCE_LISTENING_PICK, extra: $this->pickProps(selfReportable: true))
            ->dispatch('listen-completed', missionCode: 'M01', source: 'listening_pick', pickKey: '2-0', duration: 120.0)
            ->assertSeeHtml('data-testid="listen-count">0<')
            ->call('reportListen')
            ->assertSeeHtml('data-testid="listen-count">1<')
            ->assertSee('self-reported')
            ->assertDispatched('listen-self-reported');

        $this->assertDatabaseHas('audio_listens', ['learner_id' => $learner->id, 'pick_key' => '2-0', 'self_reported' => true]);
    }

    public function test_a_double_tap_on_i_listened_to_this_one_is_a_single_listen(): void
    {
        $learner = User::factory()->create();

        $component = $this->counter($learner, source: AudioListen::SOURCE_LISTENING_PICK, extra: $this->pickProps(selfReportable: true))
            ->call('reportListen')
            ->call('reportListen');

        $this->assertSame(1, $learner->audioListens()->count());

        $this->travel(61)->seconds();
        $component->call('reportListen');

        $this->assertSame(2, $learner->audioListens()->count());
    }

    public function test_a_recording_played_in_the_app_cannot_be_self_reported(): void
    {
        $learner = User::factory()->create();

        $this->counter($learner)
            ->call('reportListen')
            ->assertNotDispatched('listen-self-reported');

        $this->assertSame(0, $learner->audioListens()->count());
    }

    public function test_the_badge_shows_progress_toward_the_target_until_it_is_reached(): void
    {
        $learner = User::factory()->create();
        AudioListen::factory()->count(AudioListen::TARGET - 1)->create(['learner_id' => $learner->id]);

        $this->counter($learner)
            ->assertSee('of '.AudioListen::TARGET)
            ->assertSee('Today '.(AudioListen::TARGET - 1).'×');

        AudioListen::factory()->create(['learner_id' => $learner->id]);

        $this->counter($learner)
            ->assertSeeHtml('data-testid="listen-count">'.AudioListen::TARGET.'<')
            ->assertDontSee('of '.AudioListen::TARGET);
    }

    public function test_a_daily_pick_is_done_after_two_listens(): void
    {
        $learner = User::factory()->create();
        AudioListen::factory()->pick(2, 0)->create(['learner_id' => $learner->id]);

        $this->counter($learner, source: AudioListen::SOURCE_LISTENING_PICK, extra: $this->pickProps('2-0'))
            ->assertSee('of '.AudioListen::SHORT_TARGET);

        AudioListen::factory()->pick(2, 0)->create(['learner_id' => $learner->id]);

        $this->counter($learner, source: AudioListen::SOURCE_LISTENING_PICK, extra: $this->pickProps('2-0'))
            ->assertSeeHtml('data-testid="listen-count">'.AudioListen::SHORT_TARGET.'<')
            ->assertDontSee('of '.AudioListen::SHORT_TARGET);
    }

    public function test_a_shadowing_video_is_done_after_two_watches(): void
    {
        $learner = User::factory()->create();
        AudioListen::factory()->create(['learner_id' => $learner->id, 'source' => AudioListen::SOURCE_VIDEO_SHADOWING]);

        $this->counter($learner, source: AudioListen::SOURCE_VIDEO_SHADOWING)->assertSee('of '.AudioListen::SHORT_TARGET);

        AudioListen::factory()->create(['learner_id' => $learner->id, 'source' => AudioListen::SOURCE_VIDEO_SHADOWING]);

        $this->counter($learner, source: AudioListen::SOURCE_VIDEO_SHADOWING)->assertDontSee('of '.AudioListen::SHORT_TARGET);
    }

    public function test_the_summary_splits_listens_by_day_and_tells_when_the_last_one_was(): void
    {
        $learner = User::factory()->create();
        AudioListen::factory()->create(['learner_id' => $learner->id, 'day_number' => 1, 'created_at' => now()->subDays(3)]);
        AudioListen::factory()->create(['learner_id' => $learner->id, 'day_number' => 2, 'created_at' => now()->subDay()]);
        AudioListen::factory()->create(['learner_id' => $learner->id, 'day_number' => 2, 'created_at' => now()->subDay()->subHour()]);
        AudioListen::factory()->create(['learner_id' => $learner->id]);

        $summary = $learner->audioListenSummary('M01', AudioListen::SOURCE_LISTENING);

        $this->assertSame(4, $summary['total']);
        $this->assertSame(1, $summary['today']);
        $this->assertTrue($summary['last']->isToday());
        $this->assertSame([1 => 1, 2 => 2], $summary['byDay']);

        $this->counter($learner)->assertSeeHtml('data-testid="listen-by-day"')->assertSee('Day 2 ×2');
    }

    public function test_only_listening_recordings_count_toward_listens_today(): void
    {
        $learner = User::factory()->create();
        AudioListen::factory()->create(['learner_id' => $learner->id]);
        AudioListen::factory()->pick()->selfReported()->create(['learner_id' => $learner->id]);
        AudioListen::factory()->create(['learner_id' => $learner->id, 'source' => AudioListen::SOURCE_VIDEO_SHADOWING]);
        AudioListen::factory()->create(['learner_id' => $learner->id, 'created_at' => now()->subDay()]);

        $this->assertSame(2, $learner->listensToday());
    }

    public function test_the_players_show_the_chip_only_when_asked_to_count(): void
    {
        $this->actingAs(User::factory()->create());

        $this->assertStringContainsString('eosListenTracker', $this->renderPlayer('audio-player', ['listen' => ['mission_code' => 'M01', 'source' => 'listening']]));
        $this->assertStringNotContainsString('eosListenTracker', $this->renderPlayer('audio-player', []));
        $this->assertStringContainsString('eosListenTracker', $this->renderPlayer('video-player', ['listen' => ['mission_code' => 'M01', 'source' => 'video_shadowing']]));
        $this->assertStringNotContainsString('eosListenTracker', $this->renderPlayer('video-player', []));
    }

    public function test_the_players_show_how_far_the_current_listen_has_got(): void
    {
        $this->actingAs(User::factory()->create());
        $listen = ['mission_code' => 'M01', 'source' => 'listening'];

        $this->assertStringContainsString('listen-progress', $this->renderPlayer('audio-player', ['listen' => $listen]));
        $this->assertStringContainsString('listen-progress', $this->renderPlayer('video-player', ['listen' => $listen]));
        $this->assertStringNotContainsString('listen-progress', $this->renderPlayer('audio-player', []));
    }

    public function test_a_page_that_draws_its_own_badge_can_switch_the_players_off(): void
    {
        $this->actingAs(User::factory()->create());
        $listen = ['mission_code' => 'M01', 'source' => 'listening_pick', 'pick_key' => '1-0'];

        $this->assertStringContainsString('data-testid="listen-count"', $this->renderPlayer('audio-player', ['listen' => $listen]));
        $this->assertStringNotContainsString('data-testid="listen-count"', $this->renderPlayer('audio-player', ['listen' => $listen + ['chip' => false]]));
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
