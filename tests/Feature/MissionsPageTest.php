<?php

namespace Tests\Feature;

use App\Models\AudioListen;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\PiChatFeedback;
use App\Models\PiPracticeLog;
use App\Models\User;
use App\Services\MissionGrid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MissionsPageTest extends TestCase
{
    use RefreshDatabase;

    private function makeMission(string $code): Mission
    {
        return Mission::create([
            'code' => $code,
            'title' => 'Mission '.$code,
            'module' => 'Me',
            'outcome' => 'An outcome.',
            'phases' => [
                ['phase' => 'foundation', 'label' => 'Foundation', 'steps' => [['key' => 'mission_brief', 'label' => 'Mission Brief']]],
                ['phase' => 'build', 'label' => 'Build', 'steps' => [['key' => 'grammar_in_context', 'label' => 'Grammar in Context']]],
            ],
        ]);
    }

    /**
     * Finished M01 yesterday and just started M02, so today is M02 · Day 1
     * (day 5 of the program).
     */
    private function learnerOnM02(): User
    {
        $learner = User::factory()->create();
        MissionRun::findOrStart($learner, $this->makeMission('M01'))->update(['status' => MissionRun::STATUS_COMPLETE, 'completed_at' => now()->subDay()]);
        MissionRun::findOrStart($learner, $this->makeMission('M02'));

        return $learner;
    }

    public function test_from_home_a_day_opens_in_its_mission_and_the_open_mission_on_the_day_bar(): void
    {
        $learner = $this->learnerOnM02();
        $this->actingAs($learner);

        $this->get(route('missions.index'))
            ->assertSee('Day 5 of 96')
            ->assertSeeHtml('aria-label="M01 · Day 1, done"')
            ->assertSeeHtml('href="'.route('missions.show', ['M01', 'grammar_in_context']).'"')
            ->assertSeeHtml('aria-label="M02 · Day 1, today"')
            ->assertSee("You're here", false)
            ->assertSeeHtml('aria-label="M02 · Day 2, not reached yet"')
            ->assertSeeHtml('aria-label="M24 · Day 4, not reached yet"');
        $this->assertSame(route('home'), $this->dayUrl($learner, 'home', 'M02', 1));
    }

    private function dayUrl(User $learner, string $context, string $code, int $day): ?string
    {
        return collect(app(MissionGrid::class)->for($learner, $context)['missions'])->firstWhere('code', $code)['days'][$day - 1]['url'];
    }

    public function test_from_listening_a_day_opens_its_listening_and_only_listens_tick_it(): void
    {
        $learner = $this->learnerOnM02();
        AudioListen::factory()->pick(2)->create(['learner_id' => $learner->id, 'mission_code' => 'M01']);
        PiPracticeLog::factory()->for($learner, 'learner')->create(['mission_code' => 'M01', 'day_number' => 3]);
        $this->actingAs($learner);

        $this->get(route('missions.index', ['for' => 'listening']))
            ->assertSeeHtml('href="'.route('listening.show', ['M01', 3]).'"')
            ->assertSeeHtml('aria-label="M01 · Day 2, done"')
            ->assertSeeHtml('aria-label="M01 · Day 3"')
            ->assertSeeHtml('aria-label="M03 · Day 1, not reached yet"')
            ->assertDontSeeHtml('href="'.route('listening.show', ['M03', 1]).'"');
        $this->assertSame(route('listening.show'), $this->dayUrl($learner, 'listening', 'M02', 1));
    }

    public function test_from_daily_talk_a_day_opens_its_practice_and_only_practice_ticks_it(): void
    {
        $learner = $this->learnerOnM02();
        PiChatFeedback::factory()->for($learner, 'learner')->create(['chat' => '', 'day_number' => 2, 'feedback' => ['practice_done' => true]]);
        AudioListen::factory()->pick(3)->create(['learner_id' => $learner->id, 'mission_code' => 'M01']);
        $this->actingAs($learner);

        $this->get(route('missions.index', ['for' => 'talk']))
            ->assertSeeHtml('href="'.route('pi.practice', ['M01', 4]).'"')
            ->assertSeeHtml('aria-label="M01 · Day 2, done"')
            ->assertSeeHtml('aria-label="M01 · Day 3"')
            ->assertDontSeeHtml('href="'.route('pi.practice', ['M03', 1]).'"');
    }

    public function test_an_unknown_for_opens_the_days_as_from_home(): void
    {
        $this->actingAs($this->learnerOnM02());

        $this->get(route('missions.index', ['for' => 'elsewhere']))
            ->assertSeeHtml('href="'.route('missions.show', ['M01', 'grammar_in_context']).'"')
            ->assertDontSeeHtml('href="'.route('listening.show', ['M01', 2]).'"');
    }

    public function test_a_guest_is_sent_to_log_in(): void
    {
        $this->get(route('missions.index'))->assertRedirect(route('login'));
    }
}
