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

    public function test_a_due_word_asks_if_you_remember_it_without_giving_the_answer_away(): void
    {
        $learner = User::factory()->create();
        $this->makeDueWord($learner); // brand new: same flow as any other word
        $this->actingAs($learner);

        Livewire::test('vocabulary.index')
            ->assertSee('commute')
            ->assertSee('I remember')
            ->assertSee('Not sure — show me')
            ->assertDontSee('to travel to work') // hidden until the card opens
            ->assertDontSee('Write a sentence using this word.')
            ->assertDontSee('Quick check before you write');
    }

    public function test_the_word_has_a_visible_speaker_button_instead_of_a_hidden_double_tap(): void
    {
        $learner = User::factory()->create();
        $this->makeDueWord($learner, ['example' => 'I commute by train.']);
        $this->actingAs($learner);

        $component = Livewire::test('vocabulary.index');

        $component->assertSeeHtml('data-text="commute"')
            ->assertDontSee('Double-tap to hear it')
            ->assertDontSeeHtml('dblclick');

        // The example sentence gets its own speaker once the card is open.
        $component->call('revealWord', true)->assertSeeHtml('data-text="I commute by train."');
    }

    public function test_saying_i_remember_opens_the_card_with_every_detail_and_the_three_grades(): void
    {
        $learner = User::factory()->create();
        $this->makeDueWord($learner, [
            'pos' => 'verb',
            'example' => 'I commute by train.',
            'user_sentence' => 'I commute to the office every day.',
        ]);
        $this->actingAs($learner);

        Livewire::test('vocabulary.index')
            ->call('revealWord', true)
            ->assertSee('to travel to work')
            ->assertSee('verb')
            ->assertSee('I commute by train.')
            ->assertSee('I commute to the office every day.')
            ->assertSee('Forgot it')
            ->assertSee('Remembered it')
            ->assertSee('Knew it instantly');
    }

    public function test_asking_to_be_shown_the_word_opens_the_card_with_a_single_next_button(): void
    {
        $learner = User::factory()->create();
        $this->makeDueWord($learner);
        $this->actingAs($learner);

        Livewire::test('vocabulary.index')
            ->call('revealWord', false)
            ->assertSee('to travel to work')
            ->assertSee('see it again soon')
            ->assertDontSee('Knew it instantly');
    }

    public function test_a_word_with_no_extra_details_just_shows_its_meaning(): void
    {
        $learner = User::factory()->create();
        $this->makeDueWord($learner);
        $this->actingAs($learner);

        Livewire::test('vocabulary.index')
            ->call('revealWord', true)
            ->assertSee('to travel to work')
            ->assertDontSee('Your sentence')
            ->assertDontSee('Example');
    }

    public function test_grading_self_reviews_the_word_and_moves_to_the_next_one(): void
    {
        $learner = User::factory()->create();
        $word = $this->makeDueWord($learner, ['word' => 'commute', 'repetitions' => 2, 'interval_days' => 6]);
        $this->makeDueWord($learner, ['word' => 'errand', 'repetitions' => 2, 'interval_days' => 6]);

        $this->actingAs($learner);

        Livewire::test('vocabulary.index')
            ->call('revealWord', true)
            ->call('gradeSelf', 5)
            ->assertSee('errand')
            ->assertSet('revealed', false)
            ->assertSet('recalled', false);

        $this->assertSame(3, $word->fresh()->repetitions);
    }

    public function test_a_brand_new_word_is_graded_like_any_other(): void
    {
        $learner = User::factory()->create();
        $word = $this->makeDueWord($learner); // repetitions 0

        $this->actingAs($learner);

        Livewire::test('vocabulary.index')
            ->call('revealWord', true)
            ->call('gradeSelf', 4);

        $fresh = $word->fresh();
        $this->assertSame(1, $fresh->repetitions);
        $this->assertSame(1, $fresh->interval_days);
    }

    public function test_a_word_the_learner_was_not_sure_about_can_only_be_graded_as_forgotten(): void
    {
        $learner = User::factory()->create();
        $word = $this->makeDueWord($learner, ['repetitions' => 3, 'interval_days' => 16, 'ease_factor' => 2.8]);

        $this->actingAs($learner);

        // A crafted gradeSelf(5) after "show me" must not count as knowing it.
        Livewire::test('vocabulary.index')
            ->call('revealWord', false)
            ->call('gradeSelf', 5);

        $fresh = $word->fresh();
        $this->assertSame(0, $fresh->repetitions);
        $this->assertSame(1, $fresh->interval_days);
    }

    public function test_grading_self_is_a_no_op_until_the_card_has_been_opened(): void
    {
        $learner = User::factory()->create();
        $word = $this->makeDueWord($learner);

        $this->actingAs($learner);

        Livewire::test('vocabulary.index')->call('gradeSelf', 5);

        $this->assertSame(0, $word->fresh()->repetitions);
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
