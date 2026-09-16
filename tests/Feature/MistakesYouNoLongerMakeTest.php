<?php

namespace Tests\Feature;

use App\Models\ErrorLogItem;
use App\Models\ErrorPatternReview;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * S1 of the "growth without discouragement" epic — the learner has to be
 * able to see they are improving without sitting anything that feels
 * like a test. See User::masteredErrorPatterns() and
 * <x-mistakes-you-fixed>.
 */
class MistakesYouNoLongerMakeTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompletedRun(User $learner, string $code): MissionRun
    {
        $mission = Mission::create([
            'code' => $code,
            'title' => 'Test Mission',
            'module' => 'Me',
            'outcome' => 'Outcome.',
            'phases' => [],
        ]);

        $run = MissionRun::findOrStart($learner, $mission);
        $run->update(['status' => MissionRun::STATUS_COMPLETE, 'completed_at' => now()]);

        return $run;
    }

    private function trackedPattern(User $learner, string $category, int $repetitions, ?string $lastReviewedAt = '-10 days'): ErrorPatternReview
    {
        return ErrorPatternReview::create([
            'learner_id' => $learner->id,
            'category' => $category,
            'last_error' => 'She go to work.',
            'last_correction' => 'She goes to work.',
            'repetitions' => $repetitions,
            'interval_days' => 6,
            'next_review_at' => now()->addDays(6),
            'last_reviewed_at' => $lastReviewedAt ? now()->parse($lastReviewedAt) : null,
        ]);
    }

    public function test_a_category_passed_enough_times_and_not_repeated_since_counts_as_mastered(): void
    {
        $learner = User::factory()->create();
        $this->trackedPattern($learner, 'third-person-s', User::MASTERED_ERROR_REPETITIONS);

        $this->assertSame(['third-person-s'], $learner->masteredErrorPatterns()->pluck('category')->all());
    }

    public function test_a_category_still_below_the_repetition_threshold_is_not_mastered(): void
    {
        $learner = User::factory()->create();
        $this->trackedPattern($learner, 'article-usage', User::MASTERED_ERROR_REPETITIONS - 1);

        $this->assertTrue($learner->masteredErrorPatterns()->isEmpty());
    }

    public function test_a_category_that_came_back_after_its_last_review_is_not_mastered(): void
    {
        $learner = User::factory()->create();
        $this->trackedPattern($learner, 'third-person-s', User::MASTERED_ERROR_REPETITIONS);

        // Passed the drill three times, then made the same mistake again
        // in their own writing — exactly the case the "no score, just
        // evidence" claim on screen must not be allowed to overstate.
        $run = $this->makeCompletedRun($learner, 'M02');
        ErrorLogItem::create([
            'mission_run_id' => $run->id,
            'error' => 'He walk fast.',
            'correction' => 'He walks fast.',
            'category' => 'third-person-s',
        ]);

        $this->assertTrue($learner->masteredErrorPatterns()->isEmpty());
    }

    public function test_a_tracked_category_absent_from_recent_missions_counts_as_fading(): void
    {
        $learner = User::factory()->create();
        $this->trackedPattern($learner, 'article-usage', 1);

        $this->makeCompletedRun($learner, 'M01');
        $run2 = $this->makeCompletedRun($learner, 'M02');
        ErrorLogItem::create([
            'mission_run_id' => $run2->id,
            'error' => 'I am agree.',
            'correction' => 'I agree.',
            'category' => 'agree-verb',
        ]);

        $this->assertSame(['article-usage'], $learner->fadingErrorPatterns()->pluck('category')->all());
    }

    public function test_a_category_still_appearing_recently_is_not_fading(): void
    {
        $learner = User::factory()->create();
        $this->trackedPattern($learner, 'article-usage', 1);

        $this->makeCompletedRun($learner, 'M01');
        $run2 = $this->makeCompletedRun($learner, 'M02');
        ErrorLogItem::create([
            'mission_run_id' => $run2->id,
            'error' => 'I went to the home.',
            'correction' => 'I went home.',
            'category' => 'article-usage',
        ]);

        $this->assertTrue($learner->fadingErrorPatterns()->isEmpty());
    }

    public function test_nothing_fades_on_a_learners_very_first_mission(): void
    {
        $learner = User::factory()->create();
        $this->trackedPattern($learner, 'article-usage', 1);
        $this->makeCompletedRun($learner, 'M01');

        // With one mission of history, "absent from recent missions"
        // would be accidentally true of every category.
        $this->assertTrue($learner->fadingErrorPatterns()->isEmpty());
    }

    public function test_a_mastered_category_is_not_also_listed_as_fading(): void
    {
        $learner = User::factory()->create();
        $this->trackedPattern($learner, 'third-person-s', User::MASTERED_ERROR_REPETITIONS);
        $this->makeCompletedRun($learner, 'M01');
        $this->makeCompletedRun($learner, 'M02');

        $this->assertSame(['third-person-s'], $learner->masteredErrorPatterns()->pluck('category')->all());
        $this->assertTrue($learner->fadingErrorPatterns()->isEmpty());
    }

    public function test_the_progress_page_names_a_mastered_mistake(): void
    {
        $learner = User::factory()->create();
        $this->trackedPattern($learner, 'third-person-s', User::MASTERED_ERROR_REPETITIONS);

        Livewire::actingAs($learner)
            ->test('progress.index')
            ->assertSee('Mistakes you no longer make')
            ->assertSee('Third person s')
            ->assertSee('She goes to work.');
    }

    public function test_the_progress_page_shows_a_promising_empty_state_not_a_zero(): void
    {
        $learner = User::factory()->create();

        Livewire::actingAs($learner)
            ->test('progress.index')
            ->assertSee('Mistakes you no longer make')
            ->assertSee('Nothing here yet');
    }

    public function test_the_list_never_shows_a_score_or_a_count(): void
    {
        $learner = User::factory()->create();
        $this->trackedPattern($learner, 'third-person-s', User::MASTERED_ERROR_REPETITIONS);

        $html = Livewire::actingAs($learner)->test('progress.index')->html();
        $section = Str::between($html, 'Mistakes you no longer make', 'More stats');

        // The whole point of this surface is that it is the one place in
        // the app with no measurement in it. A "3 fixed / 11 to go" line
        // would turn it straight back into a scoreboard.
        foreach (['fixed so far', 'out of', 'score', '%'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $section);
        }
    }
}
