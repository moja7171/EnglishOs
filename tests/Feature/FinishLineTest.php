<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use App\Notifications\FinishLineUpdate;
use App\Services\FinishLine;
use App\Services\Reminders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class FinishLineTest extends TestCase
{
    use RefreshDatabase;

    /** 11:30 on 22 October 2026 (۳۰ مهر) in Tehran. */
    private const TEHRAN_MORNING = '2026-10-22 08:00:00';

    /** 13:05 the same day in Tehran, when the shift check runs. */
    private const TEHRAN_LUNCH = '2026-10-22 09:35:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(self::TEHRAN_MORNING);
    }

    /** A mission of four one-step days. */
    private function makeMission(string $code = 'M01'): Mission
    {
        return Mission::create([
            'code' => $code,
            'title' => 'Mission '.$code,
            'module' => 'Me',
            'outcome' => 'An outcome.',
            'phases' => collect(['foundation', 'build', 'practice', 'challenge'])
                ->map(fn (string $phase) => ['phase' => $phase, 'label' => ucfirst($phase), 'steps' => [['key' => "{$phase}_step", 'label' => ucfirst($phase), 'duration_minutes' => 5]]])
                ->all(),
        ]);
    }

    private function learner(): User
    {
        return User::factory()->create(['timezone' => 'Asia/Tehran']);
    }

    /**
     * Finishes days of $mission, each answer dated $at (now by default).
     *
     * @param  list<string>  $phases
     */
    private function finishDays(User $learner, Mission $mission, array $phases, ?string $at = null): MissionRun
    {
        $run = MissionRun::findOrStart($learner, $mission);

        foreach ($phases as $phase) {
            $evidence = Evidence::create(['mission_run_id' => $run->id, 'phase' => "{$phase}_step", 'type' => Evidence::TYPE_TEXT, 'content_ref' => 'x']);

            if ($at !== null) {
                $evidence->forceFill(['created_at' => now()->parse($at)])->saveQuietly();
            }
        }

        return $run;
    }

    /** $count earlier missions closed long ago (4 days each), for a learner who started 60 days back. */
    private function learnerWithClosedMissions(int $count): User
    {
        $learner = $this->learner();

        foreach (range(1, $count) as $number) {
            $run = MissionRun::findOrStart($learner, $this->makeMission(sprintf('M%02d', $number)));
            $run->update(['status' => MissionRun::STATUS_COMPLETE, 'completed_at' => now()->subDays(30)]);
        }

        $learner->forceFill(['program_started_at' => now()->subDays(60)])->save();

        return $learner;
    }

    private function assertToldOnce(User $learner, string $text): void
    {
        Notification::assertSentTo($learner, FinishLineUpdate::class, function (FinishLineUpdate $notification) use ($learner, $text) {
            return str_contains($notification->toArray($learner)['title'], $text);
        });
    }

    public function test_one_day_a_day_from_tomorrow_after_finishing_day_1_today(): void
    {
        $learner = $this->learner();
        $this->finishDays($learner, $this->makeMission(), ['foundation']);

        $forecast = app(FinishLine::class)->forecast($learner->fresh());

        $this->assertSame(1, $forecast['daysDone']);
        $this->assertSame(95, $forecast['daysLeft']);
        $this->assertSame('2027-01-25', $forecast['steadyDate']->toDateString());
        $this->assertSame('2027-01-25', $forecast['paceDate']->toDateString());
        $this->assertSame('خط پایان: ۵ بهمن ۱۴۰۵', app(FinishLine::class)->datesLine($forecast));
    }

    public function test_a_slow_pace_gives_a_later_date_next_to_the_steady_one(): void
    {
        $learner = $this->learner();
        $this->finishDays($learner, $this->makeMission(), ['foundation', 'build'], now()->subDays(2)->toDateTimeString());
        $learner->forceFill(['program_started_at' => now()->subDays(9)])->save();

        $forecast = app(FinishLine::class)->forecast($learner->fresh());

        // 2 days in 10 → a fifth of a day a day; 94 left → 470 days.
        $this->assertSame(94, $forecast['daysLeft']);
        $this->assertSame('2027-01-23', $forecast['steadyDate']->toDateString());
        $this->assertSame(now()->addDays(469)->toDateString(), $forecast['paceDate']->toDateString());
        $this->assertSame(
            'خط پایان: ۳ بهمن ۱۴۰۵ (اگه هر روز بیای) · '.app(FinishLine::class)->persianDate($forecast['paceDate']).' (با سرعت الان)',
            app(FinishLine::class)->datesLine($forecast),
        );
    }

    public function test_no_pace_date_without_a_day_finished_in_the_last_two_weeks(): void
    {
        $learner = $this->learner();
        $this->finishDays($learner, $this->makeMission(), ['foundation'], now()->subDays(20)->toDateTimeString());
        $learner->forceFill(['program_started_at' => now()->subDays(20)])->save();

        $forecast = app(FinishLine::class)->forecast($learner->fresh());

        $this->assertNull($forecast['paceDate']);
        $this->assertSame(now()->addDays(94)->toDateString(), $forecast['steadyDate']->toDateString());
    }

    public function test_a_finish_date_shortly_before_nowruz_or_yalda_says_so(): void
    {
        $finishLine = app(FinishLine::class);
        $on = fn (string $date) => ['steadyDate' => now('Asia/Tehran')->parse($date, 'Asia/Tehran'), 'paceDate' => null];

        // 1 Farvardin 1406 is 21 March 2027; 30 Azar 1405 is 21 December 2026.
        $this->assertSame('خط پایان: ۱۹ اسفند ۱۴۰۵ — قبل از عید 🌱', $finishLine->datesLine($on('2027-03-10')));
        $this->assertSame('خط پایان: ۲۴ آذر ۱۴۰۵ — قبل از یلدا 🍉', $finishLine->datesLine($on('2026-12-15')));
        $this->assertSame('خط پایان: ۵ بهمن ۱۴۰۵', $finishLine->datesLine($on('2027-01-25')));
    }

    public function test_no_forecast_before_the_program_has_started(): void
    {
        $this->assertNull(app(FinishLine::class)->forecast($this->learner()));
    }

    public function test_finishing_day_1_tells_the_date_and_what_it_means(): void
    {
        Notification::fake();
        $learner = $this->learner();

        $this->finishDays($learner, $this->makeMission(), ['foundation']);

        $this->assertToldOnce($learner, 'اگه هر روز بیای، ۵ بهمن ۱۴۰۵ به خط پایان می‌رسی — '.FinishLine::OUTCOME);
        $this->assertSame('2027-01-25', $learner->fresh()->finish_line_told_on->toDateString());
    }

    public function test_a_day_that_barely_moves_the_date_says_nothing_and_finishing_the_mission_does(): void
    {
        $learner = $this->learner();
        $mission = $this->makeMission();
        $this->finishDays($learner, $mission, ['foundation', 'build', 'practice']);
        Notification::fake();

        $this->finishDays($learner, $mission, []);
        Notification::assertNothingSent();

        $this->finishDays($learner, $mission, ['challenge']);

        $this->assertToldOnce($learner, 'ماموریت M01 تموم شد ✓ ۲۳ ماموریت مونده · خط پایان: ');
    }

    public function test_a_quarter_of_the_road_is_celebrated_with_the_dates(): void
    {
        $learner = $this->learnerWithClosedMissions(5);
        Notification::fake();

        $this->finishDays($learner, $this->makeMission('M06'), ['foundation', 'build', 'practice', 'challenge']);

        $this->assertToldOnce($learner, 'یک‌چهارم راه رو اومدی 🎉');
    }

    public function test_the_last_ten_days_count_down(): void
    {
        $learner = $this->learnerWithClosedMissions(22);
        Notification::fake();

        $this->finishDays($learner, $this->makeMission('M23'), ['foundation']);

        $this->assertToldOnce($learner, 'فقط ۷ روز تا خط پایان مونده 🏁');
    }

    public function test_finishing_day_96_says_the_finish_line_is_reached(): void
    {
        $learner = $this->learnerWithClosedMissions(23);
        Notification::fake();

        $this->finishDays($learner, $this->makeMission('M24'), ['foundation', 'build', 'practice', 'challenge']);

        $this->assertToldOnce($learner, 'خط پایان! هر ۹۶ روز رو تموم کردی');
    }

    public function test_the_daily_check_tells_a_learner_whose_date_slipped_three_days(): void
    {
        $learner = $this->learner();
        $this->finishDays($learner, $this->makeMission(), ['foundation'], now()->subDays(3)->toDateTimeString());
        $learner->forceFill(['program_started_at' => now()->subDays(3), 'finish_line_told_on' => '2027-01-22'])->save();
        Notification::fake();
        $this->travelTo(self::TEHRAN_LUNCH);
        $sentBefore = app(Reminders::class)->sentToday($learner);

        $this->artisan('finish-line:send-shifts')->assertSuccessful();

        // 1 day in 4 → 95 left takes 380 days from today.
        $this->assertToldOnce($learner, 'خط پایان رفت ');
        $this->assertSame(now()->addDays(379)->toDateString(), $learner->fresh()->finish_line_told_on->toDateString());
        $this->assertSame($sentBefore + 1, app(Reminders::class)->sentToday($learner));
    }

    public function test_the_daily_check_tells_the_good_news_when_the_date_came_three_days_earlier(): void
    {
        $learner = $this->learner();
        $this->finishDays($learner, $this->makeMission(), ['foundation'], now()->subDay()->toDateTimeString());
        // One day in two days → 95 left takes 190 days; last told 300.
        $learner->forceFill(['program_started_at' => now()->subDay(), 'finish_line_told_on' => now()->addDays(300)->toDateString()])->save();
        Notification::fake();
        $this->travelTo(self::TEHRAN_LUNCH);

        $this->artisan('finish-line:send-shifts')->assertSuccessful();

        $this->assertToldOnce($learner, 'آفرین! ۱۱۱ روز جلو افتادی 🚀');
    }

    public function test_the_daily_check_stays_quiet_when_the_date_moved_less_than_three_days(): void
    {
        $learner = $this->learner();
        $this->finishDays($learner, $this->makeMission(), ['foundation'], now()->subDay()->toDateTimeString());
        // One day in two days → 95 left takes 190 days, so tell it as that ±2.
        $learner->forceFill(['program_started_at' => now()->subDay(), 'finish_line_told_on' => now()->addDays(187)->toDateString()])->save();
        Notification::fake();
        $this->travelTo(self::TEHRAN_LUNCH);

        $this->artisan('finish-line:send-shifts')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_the_daily_check_skips_a_learner_with_no_reminders_left_today(): void
    {
        $learner = $this->learner();
        $this->finishDays($learner, $this->makeMission(), ['foundation'], now()->subDays(3)->toDateTimeString());
        $learner->forceFill(['program_started_at' => now()->subDays(3), 'finish_line_told_on' => '2027-01-22'])->save();
        Notification::fake();
        $this->travelTo(self::TEHRAN_LUNCH);
        app(Reminders::class)->countSent($learner);
        app(Reminders::class)->countSent($learner);

        $this->artisan('finish-line:send-shifts')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_the_progress_page_shows_the_finish_line(): void
    {
        $learner = $this->learner();
        $this->finishDays($learner, $this->makeMission(), ['foundation']);
        $this->actingAs($learner);

        Livewire::test('progress.index')->assertSee('🏁 خط پایان: ۵ بهمن ۱۴۰۵');
    }
}
