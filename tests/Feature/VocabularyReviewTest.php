<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VocabularyWord;
use App\Services\GeminiClient;
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
            ->assertSee('Vocabulary Builder step');
    }

    public function test_a_learner_with_nothing_due_sees_the_caught_up_state(): void
    {
        $learner = User::factory()->create();
        $this->makeDueWord($learner, ['next_review_at' => now()->addWeek()]);
        $this->actingAs($learner);

        Livewire::test('vocabulary.index')
            ->assertSee('all caught up');
    }

    public function test_a_brand_new_due_word_shows_the_written_review_flow(): void
    {
        $learner = User::factory()->create();
        $this->makeDueWord($learner);
        $this->actingAs($learner);

        Livewire::test('vocabulary.index')
            ->assertSee('commute')
            ->assertSee('to travel to work')
            ->assertSee('Write a sentence using this word.')
            ->assertDontSee('Show meaning');
    }

    public function test_a_brand_new_word_offers_a_meaning_check_diagnostic_first_when_other_words_exist(): void
    {
        $learner = User::factory()->create();
        $this->makeDueWord($learner, ['word' => 'commute', 'meaning' => 'to travel to work']);
        $this->makeDueWord($learner, ['word' => 'errand', 'meaning' => 'a short trip to do a task', 'next_review_at' => now()->addWeek()]);
        $this->makeDueWord($learner, ['word' => 'chore', 'meaning' => 'a routine task', 'next_review_at' => now()->addWeek()]);
        $this->actingAs($learner);

        Livewire::test('vocabulary.index')
            ->assertSee('Quick check before you write')
            ->assertSee('quick-round-completed')
            ->assertDontSee('Write a sentence using this word.');
    }

    public function test_the_diagnostic_is_skipped_when_the_learner_has_fewer_than_three_words(): void
    {
        $learner = User::factory()->create();
        $this->makeDueWord($learner, ['word' => 'commute']);
        $this->makeDueWord($learner, ['word' => 'errand', 'next_review_at' => now()->addWeek()]);
        $this->actingAs($learner);

        Livewire::test('vocabulary.index')
            ->assertDontSee('Quick check before you write')
            ->assertSee('Write a sentence using this word.');
    }

    public function test_completing_the_diagnostic_reveals_the_written_review(): void
    {
        $learner = User::factory()->create();
        $this->makeDueWord($learner, ['word' => 'commute']);
        $this->makeDueWord($learner, ['word' => 'errand', 'next_review_at' => now()->addWeek()]);
        $this->makeDueWord($learner, ['word' => 'chore', 'next_review_at' => now()->addWeek()]);
        $this->actingAs($learner);

        Livewire::test('vocabulary.index')
            ->set('diagnosticDone', true)
            ->assertSee('Write a sentence using this word.')
            ->assertDontSee('Quick check before you write');
    }

    public function test_checking_a_good_sentence_advances_the_word_and_shows_feedback(): void
    {
        $learner = User::factory()->create();
        $word = $this->makeDueWord($learner);

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()
            ->andReturn(json_encode(['severity' => 'none', 'hint' => ''])));

        $this->actingAs($learner);

        Livewire::test('vocabulary.index')
            ->set('sentence', 'I commute to work by train every day.')
            ->call('checkSentence')
            ->assertSee('Next word');

        $this->assertSame(1, $word->fresh()->repetitions);
    }

    public function test_a_major_issue_sends_the_word_back_to_day_1(): void
    {
        $learner = User::factory()->create();
        $word = $this->makeDueWord($learner, ['repetitions' => 3, 'interval_days' => 16, 'ease_factor' => 2.8]);

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()
            ->andReturn(json_encode(['severity' => 'major', 'hint' => 'The word is missing.'])));

        $this->actingAs($learner);

        Livewire::test('vocabulary.index')
            ->set('sentence', 'Not using the word at all.')
            ->call('checkSentence');

        $fresh = $word->fresh();
        $this->assertSame(0, $fresh->repetitions);
        $this->assertSame(1, $fresh->interval_days);
    }

    public function test_an_empty_sentence_is_rejected_without_calling_the_ai(): void
    {
        $learner = User::factory()->create();
        $this->makeDueWord($learner);

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldNotReceive('chat'));

        $this->actingAs($learner);

        Livewire::test('vocabulary.index')
            ->set('sentence', '   ')
            ->call('checkSentence')
            ->assertSee('Write a sentence first.');
    }

    public function test_an_already_passed_word_shows_the_self_assessment_flow(): void
    {
        $learner = User::factory()->create();
        $this->makeDueWord($learner, ['repetitions' => 2, 'interval_days' => 6]);
        $this->actingAs($learner);

        Livewire::test('vocabulary.index')
            ->assertSee('Show meaning')
            ->assertDontSee('to travel to work'); // hidden until revealed
    }

    public function test_revealing_shows_the_meaning_and_grading_buttons(): void
    {
        $learner = User::factory()->create();
        $this->makeDueWord($learner, ['repetitions' => 2, 'interval_days' => 6]);
        $this->actingAs($learner);

        Livewire::test('vocabulary.index')
            ->call('reveal')
            ->assertSee('to travel to work')
            ->assertSee('Again')
            ->assertSee('Good')
            ->assertSee('Easy');
    }

    public function test_grading_self_reviews_the_word_and_moves_to_the_next_one(): void
    {
        $learner = User::factory()->create();
        $word = $this->makeDueWord($learner, ['word' => 'commute', 'repetitions' => 2, 'interval_days' => 6]);
        $this->makeDueWord($learner, ['word' => 'errand', 'repetitions' => 2, 'interval_days' => 6]);

        $this->actingAs($learner);

        Livewire::test('vocabulary.index')
            ->call('reveal')
            ->call('gradeSelf', 5)
            ->assertSee('errand');

        $this->assertSame(3, $word->fresh()->repetitions);
    }

    public function test_grading_self_is_a_no_op_on_a_word_that_still_needs_a_written_review(): void
    {
        $learner = User::factory()->create();
        $word = $this->makeDueWord($learner); // repetitions 0

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
            ->assertSee('Vocabulary Builder step');
    }

    public function test_the_missions_overview_shows_a_nudge_when_words_are_due(): void
    {
        $learner = User::factory()->create();
        $this->makeDueWord($learner, ['word' => 'commute']);
        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertSee('1 word ready for review');
    }

    public function test_the_missions_overview_shows_no_nudge_when_nothing_is_due(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertDontSee('ready for review');
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
