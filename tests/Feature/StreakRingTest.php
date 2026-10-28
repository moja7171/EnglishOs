<?php

namespace Tests\Feature;

use App\Models\DayActivity;
use App\Models\Evidence;
use App\Models\ListeningLog;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\PairPracticeSession;
use App\Models\User;
use App\Services\StreakRing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The daily streak ring: slices per activity, 2 keep the day, 3 fill the
 * ring, freezes cover missed days. (LearningStreakTest covers the old rule
 * that still holds before a learner's ring started.)
 */
class StreakRingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A learner whose ring started $daysAgo days ago.
     */
    private function learner(int $daysAgo = 30): User
    {
        return User::factory()->create(['streak_ring_since' => now()->subDays($daysAgo)->toDateString()]);
    }

    /**
     * $slices activities on the date $daysAgo days back.
     */
    private function slices(User $learner, int $daysAgo, int $slices): void
    {
        foreach (range(1, $slices) as $slice) {
            DayActivity::factory()->create([
                'learner_id' => $learner->id,
                'on_date' => now()->subDays($daysAgo)->toDateString(),
                'activity' => "test:{$slice}",
            ]);
        }
    }

    private function makeMission(): Mission
    {
        return Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [
                ['phase' => 'foundation', 'label' => 'Day 1', 'steps' => [['key' => 'mission_brief'], ['key' => 'listening_1']]],
            ],
        ]);
    }

    public function test_one_activity_is_not_enough_two_keep_the_day_and_three_fill_the_ring(): void
    {
        $learner = $this->learner();

        $this->slices($learner, 0, 1);
        $this->assertSame(0, $learner->currentStreak());
        $this->assertFalse($learner->wasActiveToday());
        $this->assertSame(0, $learner->streakAtRiskLength());

        $this->slices($learner, 1, 2);
        $this->assertSame(1, app(StreakRing::class)->state($learner->fresh())['current']);

        $learner = $learner->fresh();
        DayActivity::factory()->create(['learner_id' => $learner->id, 'activity' => 'test:2']);
        DayActivity::factory()->create(['learner_id' => $learner->id, 'activity' => 'test:3']);
        $ring = $learner->streakRing();

        $this->assertSame(2, $ring['current']);
        $this->assertTrue($ring['keptToday']);
        $this->assertSame(3, $ring['todaySlices']);
    }

    public function test_each_activity_counts_once_a_day_and_each_mission_step_on_its_own(): void
    {
        $learner = $this->learner();
        $run = MissionRun::findOrStart($learner, $this->makeMission());

        foreach (['mission_brief', 'mission_brief', 'listening_1'] as $phase) {
            Evidence::create(['mission_run_id' => $run->id, 'phase' => $phase, 'type' => Evidence::TYPE_SCORE, 'content_ref' => '3']);
        }

        $learner->recordListeningToday('M01', 1);
        $learner->recordListeningToday('M01', 1);

        $this->assertSame(3, app(StreakRing::class)->slicesToday($learner));
    }

    public function test_todays_review_is_a_slice_once_it_is_all_done(): void
    {
        $learner = $this->learner();
        $words = collect(['commute', 'oversleep'])->map(fn (string $word) => $learner->vocabularyWords()->create(['word' => $word, 'next_review_at' => now()->subDay()]));

        $words[0]->review(4);
        $this->assertSame(0, app(StreakRing::class)->slicesToday($learner));

        $words[1]->review(4);
        $this->assertSame(1, app(StreakRing::class)->slicesToday($learner));
    }

    public function test_a_counted_pair_practice_round_is_a_slice_for_both_players(): void
    {
        $session = PairPracticeSession::factory()->create();
        [$first, $second] = $session->players();
        $session->results = array_fill(0, PairPracticeSession::MIN_COUNTED_CARDS, ['stage' => 'vocabulary', 'card' => 0, 'speaker_id' => $first->id, 'points' => 1, 'correct' => true, 'heard' => null, 'at' => now()->getTimestamp()]);
        $session->save();

        $session->finish();

        $this->assertSame(1, app(StreakRing::class)->slicesToday($first));
        $this->assertSame(1, app(StreakRing::class)->slicesToday($second));
    }

    public function test_a_missed_day_uses_a_freeze_and_the_learner_can_see_it(): void
    {
        $learner = $this->learner(5);
        $this->slices($learner, 3, 2);
        $this->slices($learner, 2, 2);
        // Yesterday missed.
        $ring = $learner->streakRing();

        $this->assertSame(2, $ring['current']);
        $this->assertSame(0, $ring['freezes']);
        $this->assertSame([now()->subDay()->toDateString()], $ring['frozenDates']);
        $this->assertTrue($learner->justBenefitedFromGrace());
    }

    public function test_a_missed_day_with_no_freeze_left_ends_the_streak(): void
    {
        $learner = $this->learner(6);
        $this->slices($learner, 4, 2);
        // Two days missed: the first uses the only freeze, the second ends it.

        $this->assertSame(0, $learner->currentStreak());
        $this->assertSame(1, $learner->longestStreak());
        $this->assertTrue($learner->justLostStreak());
    }

    public function test_seven_full_days_earn_a_freeze_up_to_two(): void
    {
        $learner = $this->learner(15);

        foreach (range(15, 1) as $daysAgo) {
            $this->slices($learner, $daysAgo, StreakRing::SLICES_FOR_FULL);
        }

        $ring = $learner->streakRing();
        $this->assertSame(15, $ring['current']);
        $this->assertSame(StreakRing::MAX_FREEZES, $ring['freezes']);
    }

    public function test_a_streak_from_before_the_ring_carries_over(): void
    {
        $learner = $this->learner(1);
        ListeningLog::factory()->create(['learner_id' => $learner->id, 'listened_on' => now()->subDays(3)->toDateString()]);
        ListeningLog::factory()->create(['learner_id' => $learner->id, 'listened_on' => now()->subDays(2)->toDateString()]);
        $this->slices($learner, 1, 2);

        $this->assertSame(3, $learner->currentStreak());
    }

    public function test_the_header_ring_shows_the_day_being_built_and_how_much_is_left(): void
    {
        $learner = $this->learner();
        $this->slices($learner, 1, 2);
        DayActivity::factory()->create([
            'learner_id' => $learner->id,
            'on_date' => now()->toDateString(),
            'activity' => 'review',
        ]);
        $this->actingAs($learner);

        Livewire::test('streak.ring')
            ->assertSee('Day 2')
            ->assertSee('1/3')
            ->assertSee('1 more activity keeps your streak')
            ->assertSee('Review')
            ->assertSee('Step, review, listening or Daily talk')
            ->assertSee('1 freeze')
            ->assertSee(route('progress.index'));
    }

    public function test_filling_the_ring_celebrates_once_a_day(): void
    {
        $learner = $this->learner();
        $this->slices($learner, 0, 3);
        $this->actingAs($learner);

        Livewire::test('streak.ring')->assertSee('ترکوندی')->assertSee('Ring full');
        Livewire::test('streak.ring')->assertDontSee('ترکوندی');
    }
}
