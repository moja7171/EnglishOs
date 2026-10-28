<?php

namespace Tests\Feature;

use App\Models\AudioListen;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\PiChatFeedback;
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

    /**
     * @return array<string, mixed>
     */
    private function day(User $learner, string $code, int $number): array
    {
        return collect(app(MissionGrid::class)->for($learner)['missions'])->firstWhere('code', $code)['days'][$number - 1];
    }

    public function test_a_guest_is_sent_to_log_in(): void
    {
        $this->get(route('missions.index'))->assertRedirect(route('welcome'));
    }

    public function test_the_page_shows_every_mission_with_where_the_learner_is(): void
    {
        $this->actingAs($this->learnerOnM02());

        $this->get(route('missions.index'))
            ->assertSee('Day 5 of 96')
            ->assertSee("You're here", false)
            ->assertSeeHtml('aria-label="M01 · Day 1, 1 of 3 done"')
            ->assertSeeHtml('aria-label="M02 · Day 1, today"')
            ->assertSeeHtml('aria-label="M02 · Day 2"')
            ->assertSeeHtml('aria-label="M24 · Day 4, not built yet"');
    }

    public function test_each_day_of_a_built_mission_opens_its_missions_page_on_that_day(): void
    {
        $learner = $this->learnerOnM02();

        $this->assertSame(route('missions.show', ['M01', 'overview', 'day' => 2]), $this->day($learner, 'M01', 2)['url']);
        $this->assertSame(route('missions.show', ['M02', 'overview', 'day' => 1]), $this->day($learner, 'M02', 1)['url']);
        $this->assertSame(route('missions.show', ['M02', 'overview', 'day' => 2]), $this->day($learner, 'M02', 2)['url']);
        $this->assertNull($this->day($learner, 'M24', 1)['url']);

        $this->actingAs($learner);
        $this->get(route('missions.index'))
            ->assertSeeHtml('href="'.route('missions.show', ['M02', 'overview']).'"')
            ->assertDontSeeHtml('x-init="show(');
    }

    public function test_a_built_mission_not_reached_yet_looks_open_and_its_page_holds_the_lock(): void
    {
        $learner = $this->learnerOnM02();
        $this->makeMission('M03');
        $m03 = collect(app(MissionGrid::class)->for($learner)['missions'])->firstWhere('code', 'M03');

        $this->assertFalse($m03['reached']);
        $this->assertTrue($m03['built']);
        $this->assertSame(route('missions.show', ['M03', 'overview']), $m03['url']);

        $this->actingAs($learner)->get($m03['url'])->assertSee("Preview only: this mission isn't unlocked yet", false)
            ->assertSee('you finish M02.');
    }

    public function test_each_part_of_a_day_is_ticked_by_its_own_practice(): void
    {
        $learner = $this->learnerOnM02();
        AudioListen::factory()->pick(2)->create(['learner_id' => $learner->id, 'mission_code' => 'M01']);
        PiChatFeedback::factory()->for($learner, 'learner')->create(['chat' => '', 'day_number' => 2, 'feedback' => ['practice_done' => true]]);
        PiChatFeedback::factory()->for($learner, 'learner')->create(['chat' => '', 'day_number' => 3, 'feedback' => ['practice_done' => true]]);

        $dayTwo = $this->day($learner, 'M01', 2);
        $dayThree = $this->day($learner, 'M01', 3);

        $this->assertSame([true, true, true], [$dayTwo['lesson'], $dayTwo['listened'], $dayTwo['talked']]);
        $this->assertSame([true, false, true], [$dayThree['lesson'], $dayThree['listened'], $dayThree['talked']]);

        $this->actingAs($learner);
        $this->get(route('missions.index'))
            ->assertSeeHtml('aria-label="M01 · Day 2, done"')
            ->assertSeeHtml('aria-label="M01 · Day 3, 2 of 3 done"');
    }

    public function test_an_old_day_sheet_link_opens_the_missions_page_on_that_day(): void
    {
        $this->actingAs($this->learnerOnM02());

        $this->get(route('missions.index', ['day' => 'M01-3']))
            ->assertRedirect(route('missions.show', ['M01', 'overview', 'day' => 3]));
        $this->get(route('missions.index', ['day' => 'M09-1']))
            ->assertRedirect(route('missions.index'));
        $this->get(route('missions.index', ['day' => 'nonsense']))->assertOk();
    }

    public function test_a_built_mission_not_unlocked_yet_shows_a_lock_but_still_opens(): void
    {
        $learner = $this->learnerOnM02();
        $this->makeMission('M03');

        $this->actingAs($learner)->get(route('missions.index'))
            ->assertSeeHtml('aria-label="Locked"')
            ->assertSeeHtml('href="'.route('missions.show', ['M03', 'overview']).'"');
    }
}
