<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VocabularyWord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VocabularyWordSm2Test extends TestCase
{
    use RefreshDatabase;

    private function makeWord(array $attributes = []): VocabularyWord
    {
        return VocabularyWord::create(array_merge([
            'learner_id' => User::factory()->create()->id,
            'word' => 'commute',
            'next_review_at' => now(),
        ], $attributes));
    }

    public function test_the_first_successful_review_sets_a_1_day_interval(): void
    {
        $word = $this->makeWord();

        $word->review(4);

        $this->assertSame(1, $word->repetitions);
        $this->assertSame(1, $word->interval_days);
        $this->assertEqualsWithDelta(2.5, $word->ease_factor, 0.001);
        $this->assertEqualsWithDelta(now()->addDay()->timestamp, $word->next_review_at->timestamp, 5);
    }

    public function test_the_second_successful_review_sets_a_6_day_interval(): void
    {
        $word = $this->makeWord();

        $word->review(4);
        $word->review(4);

        $this->assertSame(2, $word->repetitions);
        $this->assertSame(6, $word->interval_days);
    }

    public function test_the_third_and_later_reviews_multiply_the_interval_by_the_ease_factor(): void
    {
        $word = $this->makeWord();

        $word->review(4); // repetitions 1, interval 1
        $word->review(4); // repetitions 2, interval 6
        $word->review(4); // repetitions 3, interval round(6 * 2.5) = 15

        $this->assertSame(3, $word->repetitions);
        $this->assertSame(15, $word->interval_days);
        $this->assertEqualsWithDelta(2.5, $word->ease_factor, 0.001);
    }

    public function test_an_easy_review_earns_a_longer_gap_than_a_good_one_at_every_step(): void
    {
        $good = $this->makeWord();
        $easy = $this->makeWord();

        $good->review(4);
        $easy->review(5);
        $this->assertSame(1, $good->interval_days);
        $this->assertSame(3, $easy->interval_days);
        $this->assertEqualsWithDelta(2.6, $easy->ease_factor, 0.001);

        $good->review(4);
        $easy->review(5);
        $this->assertSame(6, $good->interval_days);
        $this->assertSame(8, $easy->interval_days);
        $this->assertEqualsWithDelta(2.7, $easy->ease_factor, 0.001);

        $good->review(4);
        $easy->review(5);
        $this->assertSame(15, $good->interval_days);
        $this->assertSame(29, $easy->interval_days); // round(8 * 2.7) = 22, then x1.3
    }

    public function test_previewing_a_grade_does_not_change_the_word_and_matches_what_review_then_does(): void
    {
        $word = $this->makeWord(['repetitions' => 2, 'interval_days' => 6]);

        $preview = $word->scheduleAfter(5);

        $this->assertSame(2, $word->repetitions);
        $this->assertSame(6, $word->interval_days);
        $this->assertNull($word->last_reviewed_at);

        $word->review(5);

        $this->assertSame($preview['repetitions'], $word->repetitions);
        $this->assertSame($preview['interval_days'], $word->interval_days);
        $this->assertEqualsWithDelta($preview['ease_factor'], $word->ease_factor, 0.0001);
    }

    public function test_the_next_interval_label_reads_as_days_months_or_years(): void
    {
        $word = $this->makeWord(['repetitions' => 2, 'interval_days' => 6]);

        $this->assertSame('1d', $word->nextIntervalLabel(1));
        $this->assertSame('15d', $word->nextIntervalLabel(4));
        $this->assertSame('20d', $word->nextIntervalLabel(5));

        $long = $this->makeWord(['repetitions' => 5, 'interval_days' => 40, 'ease_factor' => 2.5]);
        $this->assertSame('3mo', $long->nextIntervalLabel(4)); // 100 days

        $veryLong = $this->makeWord(['repetitions' => 8, 'interval_days' => 200, 'ease_factor' => 2.5]);
        $this->assertSame('1y', $veryLong->nextIntervalLabel(4)); // 500 days
    }

    public function test_strength_climbs_with_the_interval_and_resets_when_forgotten(): void
    {
        $new = $this->makeWord();
        $this->assertSame(0, $new->strengthLevel());
        $this->assertSame('New', $new->strengthLabel());

        foreach ([[1, 'Learning', 1], [6, 'Learning', 2], [16, 'Familiar', 3], [40, 'Strong', 4], [90, 'Known', 5]] as [$days, $label, $level]) {
            $word = $this->makeWord(['repetitions' => 3, 'interval_days' => $days, 'last_reviewed_at' => now()]);
            $this->assertSame($level, $word->strengthLevel(), "interval {$days}");
            $this->assertSame($label, $word->strengthLabel(), "interval {$days}");
        }

        $word->review(1);
        $this->assertSame(0, $word->strengthLevel());
        $this->assertSame('Relearning', $word->strengthLabel());
    }

    public function test_a_failed_review_resets_repetitions_and_goes_back_to_a_1_day_interval(): void
    {
        $word = $this->makeWord(['repetitions' => 3, 'interval_days' => 16, 'ease_factor' => 2.8]);

        $word->review(1);

        $this->assertSame(0, $word->repetitions);
        $this->assertSame(1, $word->interval_days);
    }

    public function test_a_failed_review_still_lowers_the_ease_factor(): void
    {
        $word = $this->makeWord(['ease_factor' => 2.5]);

        $word->review(1);

        $this->assertEqualsWithDelta(1.96, $word->ease_factor, 0.001);
    }

    public function test_the_ease_factor_never_drops_below_1_3(): void
    {
        $word = $this->makeWord(['ease_factor' => 1.4]);

        $word->review(0);
        $word->review(0);
        $word->review(0);

        $this->assertGreaterThanOrEqual(1.3, $word->ease_factor);
    }

    public function test_a_middling_quality_review_still_passes_but_barely_grows_the_ease_factor(): void
    {
        $word = $this->makeWord(['ease_factor' => 2.5]);

        $word->review(3);

        $this->assertSame(1, $word->repetitions); // still counts as a pass
        $this->assertEqualsWithDelta(2.36, $word->ease_factor, 0.001);
    }

    public function test_is_due_reflects_next_review_at(): void
    {
        $due = $this->makeWord(['next_review_at' => now()->subHour()]);
        $notDue = $this->makeWord(['next_review_at' => now()->addDay()]);

        $this->assertTrue($due->isDue());
        $this->assertFalse($notDue->isDue());
    }

    public function test_a_brand_new_word_reads_as_fully_fresh(): void
    {
        $word = $this->makeWord();

        $this->assertSame(100, $word->freshness());
    }

    public function test_freshness_is_100_right_after_a_review(): void
    {
        $word = $this->makeWord(['repetitions' => 1, 'interval_days' => 6, 'last_reviewed_at' => now()]);

        $this->assertSame(100, $word->freshness());
    }

    public function test_freshness_is_50_halfway_through_the_interval(): void
    {
        $word = $this->makeWord(['repetitions' => 1, 'interval_days' => 10, 'last_reviewed_at' => now()->subDays(10)]);

        $this->assertSame(50, $word->freshness());
    }

    public function test_freshness_bottoms_out_at_0_once_twice_overdue(): void
    {
        $word = $this->makeWord(['repetitions' => 1, 'interval_days' => 5, 'last_reviewed_at' => now()->subDays(30)]);

        $this->assertSame(0, $word->freshness());
    }
}
