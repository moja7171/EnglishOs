<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VocabularyWord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class VocabularyReviewTest extends TestCase
{
    use RefreshDatabase;

    private function makeDueWord(User $learner, array $attributes = []): VocabularyWord
    {
        return VocabularyWord::create(array_merge([
            'learner_id' => $learner->id,
            'word' => 'commute',
            'meaning' => 'to travel to work',
            'next_review_at' => now()->subMinute(),
        ], $attributes));
    }

    public function test_a_learner_with_no_tracked_words_sees_an_empty_state(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);

        Livewire::test('vocabulary.index')
            ->assertSee('New Words step');
    }

    public function test_a_learner_with_nothing_due_sees_the_caught_up_state(): void
    {
        $learner = User::factory()->create();
        $this->makeDueWord($learner, ['next_review_at' => now()->addWeek()]);
        $this->actingAs($learner);

        Livewire::test('vocabulary.index')
            ->assertSee('all caught up');
    }

    public function test_a_due_word_shows_the_front_of_a_recall_card(): void
    {
        $learner = User::factory()->create();
        $this->makeDueWord($learner); // brand new: same flow as any other word
        $this->actingAs($learner);

        Livewire::test('vocabulary.index')
            ->assertSee('commute')
            ->assertSee('1 due')
            ->assertSee('Do you remember it?')
            ->assertSee('I remember')
            ->assertSee('Not sure')
            ->assertSeeHtml('x-data="recallCard"')
            ->assertDontSee('Write a sentence using this word.')
            ->assertDontSee('Quick check before you write');
    }

    public function test_the_word_has_a_visible_speaker_button_instead_of_a_hidden_double_tap(): void
    {
        $learner = User::factory()->create();
        $this->makeDueWord($learner, ['example' => 'I commute by train.']);
        $this->actingAs($learner);

        Livewire::test('vocabulary.index')
            ->assertSeeHtml('data-text="commute"')
            ->assertSeeHtml('data-text="I commute by train."')
            ->assertDontSee('Double-tap to hear it')
            ->assertDontSeeHtml('dblclick');
    }

    public function test_the_back_of_the_card_carries_every_detail_with_the_target_word_in_bold(): void
    {
        $learner = User::factory()->create();
        $this->makeDueWord($learner, [
            'pos' => 'verb',
            'example' => 'I commute by train.',
            'user_sentence' => 'He commutes to the office every day.',
        ]);
        $this->actingAs($learner);

        $bold = '<strong class="font-bold text-accent-ink dark:text-accent-ink-dark">';

        Livewire::test('vocabulary.index')
            ->assertSee('to travel to work')
            ->assertSee('verb')
            ->assertSee('You wrote')
            ->assertSeeHtml($bold.'commute</strong> by train.')
            ->assertSeeHtml($bold.'commutes</strong> to the office every day.');
    }

    public function test_a_word_with_no_extra_details_just_shows_its_meaning(): void
    {
        $learner = User::factory()->create();
        $this->makeDueWord($learner);
        $this->actingAs($learner);

        Livewire::test('vocabulary.index')
            ->assertSee('to travel to work')
            ->assertDontSee('You wrote')
            ->assertDontSee('Example');
    }

    public function test_the_grade_buttons_print_the_gap_each_grade_would_schedule(): void
    {
        $learner = User::factory()->create();
        $this->makeDueWord($learner, ['repetitions' => 2, 'interval_days' => 6]);
        $this->actingAs($learner);

        Livewire::test('vocabulary.index')
            ->assertSeeInOrder(['Again', '1d', 'Good', '15d', 'Easy', '20d']);
    }

    public function test_the_strength_meter_reflects_how_well_the_word_is_known(): void
    {
        $learner = User::factory()->create();
        $this->makeDueWord($learner);
        $this->actingAs($learner);

        Livewire::test('vocabulary.index')->assertSee('New');

        $learner->vocabularyWords()->update(['repetitions' => 3, 'interval_days' => 16, 'last_reviewed_at' => now()->subDays(16)]);

        Livewire::test('vocabulary.index')->assertSee('Familiar');
    }

    public function test_grading_reviews_the_word_and_moves_to_the_next_one(): void
    {
        $learner = User::factory()->create();
        $word = $this->makeDueWord($learner, ['word' => 'commute', 'repetitions' => 2, 'interval_days' => 6]);
        $this->makeDueWord($learner, ['word' => 'errand', 'repetitions' => 2, 'interval_days' => 6]);

        $this->actingAs($learner);

        Livewire::test('vocabulary.index')
            ->call('gradeWord', 4, true)
            ->assertSee('errand');

        $fresh = $word->fresh();
        $this->assertSame(3, $fresh->repetitions);
        $this->assertSame(15, $fresh->interval_days);
    }

    public function test_an_easy_grade_schedules_further_out_than_a_good_one(): void
    {
        $learner = User::factory()->create();
        $word = $this->makeDueWord($learner, ['repetitions' => 2, 'interval_days' => 6]);
        $this->actingAs($learner);

        Livewire::test('vocabulary.index')->call('gradeWord', 5, true);

        $this->assertSame(20, $word->fresh()->interval_days);
    }

    public function test_a_brand_new_word_is_graded_like_any_other(): void
    {
        $learner = User::factory()->create();
        $word = $this->makeDueWord($learner); // repetitions 0

        $this->actingAs($learner);

        Livewire::test('vocabulary.index')->call('gradeWord', 4, true);

        $fresh = $word->fresh();
        $this->assertSame(1, $fresh->repetitions);
        $this->assertSame(1, $fresh->interval_days);
    }

    public function test_a_word_the_learner_was_not_sure_about_can_only_be_graded_as_forgotten(): void
    {
        $learner = User::factory()->create();
        $word = $this->makeDueWord($learner, ['repetitions' => 3, 'interval_days' => 16, 'ease_factor' => 2.8]);

        $this->actingAs($learner);

        // A crafted gradeWord(5, ...) after "Not sure" must not count as knowing it.
        Livewire::test('vocabulary.index')->call('gradeWord', 5, false);

        $fresh = $word->fresh();
        $this->assertSame(0, $fresh->repetitions);
        $this->assertSame(1, $fresh->interval_days);
    }

    public function test_grading_with_nothing_due_does_nothing(): void
    {
        $learner = User::factory()->create();
        $word = $this->makeDueWord($learner, ['next_review_at' => now()->addWeek()]);

        $this->actingAs($learner);

        Livewire::test('vocabulary.index')->call('gradeWord', 5, true);

        $this->assertNull($word->fresh()->last_reviewed_at);
    }

    public function test_only_the_learners_own_words_are_shown(): void
    {
        $learner = User::factory()->create();
        $other = User::factory()->create();
        $this->makeDueWord($other, ['word' => 'someone-elses-word']);

        $this->actingAs($learner);

        Livewire::test('vocabulary.index')
            ->assertDontSee('someone-elses-word')
            ->assertSee('New Words step');
    }

    public function test_the_missions_overview_shows_a_nudge_when_words_are_due(): void
    {
        $learner = User::factory()->create();
        $this->makeDueWord($learner, ['word' => 'commute']);
        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertSee('1 item ready for Daily Review');
    }

    public function test_the_missions_overview_shows_no_nudge_when_nothing_is_due(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertDontSee('ready for Daily Review');
    }

    public function test_the_browsable_list_shows_every_tracked_word(): void
    {
        $learner = User::factory()->create();
        $this->makeDueWord($learner, ['word' => 'commute']);
        $this->makeDueWord($learner, ['word' => 'errand', 'next_review_at' => now()->addDays(3)]);

        $this->actingAs($learner);

        Livewire::test('vocabulary.index')
            ->assertSeeHtml('All my words (2)')
            ->assertSee('Due now')
            ->assertSeeHtml('errand');
    }
}
