<?php

namespace Tests\Feature;

use App\Models\GrammarPoint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Grammar in Context equivalent of MistakesYouNoLongerMakeTest — see
 * User::masteredGrammarPoints()/learningGrammarPoints() and the Progress
 * page's "Grammar points" section. Before this, a GrammarPoint only ever
 * surfaced inside Daily Review once due; a learner had no way to see how
 * many they had, which they'd mastered, or when the next one was due.
 */
class GrammarPointsProgressTest extends TestCase
{
    use RefreshDatabase;

    private function makeGrammarPoint(User $learner, string $focus, int $repetitions, ?string $nextReviewAt = '+3 days'): GrammarPoint
    {
        return GrammarPoint::create([
            'learner_id' => $learner->id,
            'mission_code' => 'M01',
            'focus' => $focus,
            'example_sentence' => 'I usually wake up at 7.',
            'rule_reminder' => 'The adverb goes before the main verb.',
            'repetitions' => $repetitions,
            'interval_days' => 6,
            'next_review_at' => now()->parse($nextReviewAt),
        ]);
    }

    public function test_a_grammar_point_passed_enough_times_counts_as_mastered(): void
    {
        $learner = User::factory()->create();
        $this->makeGrammarPoint($learner, 'Present Simple + Adverbs', User::MASTERED_GRAMMAR_REPETITIONS);

        $this->assertSame(
            ['Present Simple + Adverbs'],
            $learner->masteredGrammarPoints()->pluck('focus')->all(),
        );
    }

    public function test_a_grammar_point_below_the_repetition_threshold_is_not_mastered(): void
    {
        $learner = User::factory()->create();
        $this->makeGrammarPoint($learner, 'Past Continuous', User::MASTERED_GRAMMAR_REPETITIONS - 1);

        $this->assertTrue($learner->masteredGrammarPoints()->isEmpty());
    }

    public function test_a_grammar_point_below_the_threshold_counts_as_still_learning(): void
    {
        $learner = User::factory()->create();
        $this->makeGrammarPoint($learner, 'Past Continuous', User::MASTERED_GRAMMAR_REPETITIONS - 1);

        $this->assertSame(
            ['Past Continuous'],
            $learner->learningGrammarPoints()->pluck('focus')->all(),
        );
    }

    public function test_a_brand_new_grammar_point_counts_as_still_learning(): void
    {
        $learner = User::factory()->create();
        $this->makeGrammarPoint($learner, 'Third Conditional', 0);

        $this->assertSame(
            ['Third Conditional'],
            $learner->learningGrammarPoints()->pluck('focus')->all(),
        );
    }

    public function test_a_mastered_grammar_point_is_not_also_listed_as_still_learning(): void
    {
        $learner = User::factory()->create();
        $this->makeGrammarPoint($learner, 'Present Simple + Adverbs', User::MASTERED_GRAMMAR_REPETITIONS);

        $this->assertSame(['Present Simple + Adverbs'], $learner->masteredGrammarPoints()->pluck('focus')->all());
        $this->assertTrue($learner->learningGrammarPoints()->isEmpty());
    }

    public function test_still_learning_grammar_points_are_ordered_soonest_due_first(): void
    {
        $learner = User::factory()->create();
        $this->makeGrammarPoint($learner, 'Later one', 1, '+10 days');
        $this->makeGrammarPoint($learner, 'Sooner one', 1, '+1 day');

        $this->assertSame(
            ['Sooner one', 'Later one'],
            $learner->learningGrammarPoints()->pluck('focus')->all(),
        );
    }
}
