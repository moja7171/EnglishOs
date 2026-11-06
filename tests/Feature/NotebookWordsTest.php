<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VocabularyWord;
use App\Models\WordSentence;
use App\Services\GeminiClient;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * The Notebook's Words tab: first tab, one simple sentence under each word
 * with the word picked out, today's few words, "Cover the words" reviews,
 * and the sentences made for or written by the learner.
 */
class NotebookWordsTest extends TestCase
{
    use RefreshDatabase;

    private function word(User $learner, string $word, array $attributes = []): VocabularyWord
    {
        return VocabularyWord::factory()->create(['learner_id' => $learner->id, 'word' => $word, ...$attributes]);
    }

    public function test_words_is_the_first_tab_and_opens_by_default_with_the_progress_card(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);
        $this->word($learner, 'reluctant', ['next_review_at' => now()->subDay()]);
        $this->word($learner, 'recipe');

        Livewire::test('notebook.index')
            ->assertSeeInOrder(['Words', 'Mistakes', 'Tips'])
            ->assertSee('Your words')
            ->assertSee('1 word ready to review')
            ->assertSeeHtml(route('review.track', 'words'));
    }

    public function test_a_word_shows_one_simple_sentence_with_the_word_picked_out(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);
        $word = $this->word($learner, 'recipe', ['example' => 'My grandmother never wrote down her secret recipe for the family cake.']);
        WordSentence::factory()->create(['vocabulary_word_id' => $word->id, 'sentence' => 'I love my mom’s recipe.']);

        $this->assertSame('I love my mom’s recipe.', $word->refresh()->displaySentence());
        $this->assertSame(['before' => 'I love my mom’s ', 'hit' => 'recipe', 'after' => '.'], $word->splitAround('I love my mom’s recipe.'));

        Livewire::test('notebook.index')->assertSeeHtml('>recipe</mark>');
    }

    public function test_the_learners_own_sentence_comes_first_when_it_is_simple_and_the_lessons_long_one_last(): void
    {
        $learner = User::factory()->create();
        $word = $this->word($learner, 'grab', ['example' => 'Let us grab a coffee before the very long meeting starts tomorrow.']);
        WordSentence::factory()->own()->create(['vocabulary_word_id' => $word->id, 'sentence' => 'I grab my keys.']);
        WordSentence::factory()->create(['vocabulary_word_id' => $word->id, 'sentence' => 'Grab a seat.']);

        $sources = collect($word->refresh()->learningSentences())->pluck('source')->all();

        $this->assertSame(['own', 'ai', 'lesson'], $sources);
        $this->assertSame('I grab my keys.', $word->displaySentence());
    }

    public function test_a_word_with_only_a_long_example_still_shows_it(): void
    {
        $word = $this->word(User::factory()->create(), 'recipe', ['example' => 'My grandmother never wrote down her secret recipe for the family cake.']);

        $this->assertFalse(VocabularyWord::isSimple($word->example));
        $this->assertSame($word->example, $word->displaySentence());
    }

    public function test_the_other_forms_of_a_word_are_picked_out_too(): void
    {
        $word = new VocabularyWord(['word' => 'grab']);

        $this->assertSame('grabbed', $word->splitAround('I grabbed my keys.')['hit']);
        $this->assertNull($word->splitAround('Nothing to see.'));
    }

    public function test_today_shows_at_most_six_due_words_the_weakest_first(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);

        foreach (range(1, 8) as $number) {
            $this->word($learner, 'word'.$number, ['next_review_at' => now()->subDays($number), 'repetitions' => 0]);
        }
        $this->word($learner, 'later', ['next_review_at' => now()->addWeek()]);

        $component = Livewire::test('notebook.index')->assertSee('Today · 6');

        $this->assertCount(6, $component->instance()->todayWords);
        $this->assertFalse($component->instance()->todayWords->contains('word', 'later'));
    }

    public function test_got_it_and_not_yet_move_the_review_and_nobody_elses_word_can_be_rated(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);
        $knew = $this->word($learner, 'knew', ['next_review_at' => now()->subDay()]);
        $missed = $this->word($learner, 'missed', ['next_review_at' => now()->subDay(), 'repetitions' => 3, 'interval_days' => 10]);
        $stranger = $this->word(User::factory()->create(), 'secret');

        $component = Livewire::test('notebook.index');
        $component->call('rateWord', $knew->id, 'got')->call('rateWord', $missed->id, 'not')->call('rateWord', $knew->id, 'bogus');

        $this->assertSame(1, $knew->refresh()->repetitions);
        $this->assertTrue($knew->next_review_at->isFuture());
        $this->assertSame(0, $missed->refresh()->repetitions);
        $this->assertSame(1, $missed->interval_days);

        $this->expectException(ModelNotFoundException::class);

        $component->call('rateWord', $stranger->id, 'got');
    }

    public function test_another_sentence_is_saved_only_when_simple_and_has_the_word_in_it(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);
        $word = $this->word($learner, 'spicy');
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->twice()->andReturn('This soup is spicy and my little sister does not like it at all today.', 'This soup is too spicy.'));

        $made = Livewire::test('notebook.index')->call('makeSentence', $word->id)->effects['returns'][0] ?? null;

        $this->assertSame('This soup is too spicy.', $made);
        $this->assertSame(['This soup is too spicy.'], $word->sentences()->pluck('sentence')->all());
    }

    public function test_another_sentence_gives_up_with_nothing_saved_when_the_ai_is_down(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);
        $word = $this->word($learner, 'spicy');
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->andThrow(new RuntimeException('down')));

        Livewire::test('notebook.index')->call('makeSentence', $word->id);

        $this->assertSame(0, $word->sentences()->count());
    }

    public function test_my_own_sentence_must_use_the_word_and_is_kept_with_the_ais_fix_when_it_has_a_mistake(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);
        $word = $this->word($learner, 'reluctant');
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->twice()->andReturn(
            '{"ok": true, "better": null, "note": "Nice and clear."}',
            '{"ok": false, "better": "She was reluctant to go.", "note": "Use was after she."}',
        ));
        $component = Livewire::test('notebook.index');

        $component->call('checkSentence', $word->id, 'I like cats a lot.');
        $this->assertSame(0, $word->sentences()->count(), 'a sentence without the word is not even sent to the AI');

        $component->call('checkSentence', $word->id, 'I was reluctant to say yes.');
        $component->call('checkSentence', $word->id, 'She were reluctant to go.');

        $own = $word->refresh()->sentences()->orderBy('id')->get();

        $this->assertCount(2, $own);
        $this->assertTrue($own[0]->isOwn());
        $this->assertSame('I was reluctant to say yes.', $own[0]->shown());
        $this->assertSame('She was reluctant to go.', $own[1]->shown());
        $this->assertTrue($word->ladder()['Used']);
    }

    public function test_a_sentence_that_cannot_be_checked_is_not_kept(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);
        $word = $this->word($learner, 'reluctant');
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->andThrow(new RuntimeException('down')));

        Livewire::test('notebook.index')->call('checkSentence', $word->id, 'I was reluctant to say yes.');

        $this->assertSame(0, $word->sentences()->count());
    }

    public function test_a_word_added_by_tapping_a_text_gets_a_simple_sentence_after_the_response(): void
    {
        $learner = User::factory()->create();
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn('I felt overwhelmed by my homework.'));

        $this->actingAs($learner)->postJson(route('vocabulary.store'), ['word' => 'overwhelmed'])->assertCreated();

        $this->assertSame(['I felt overwhelmed by my homework.'], $learner->vocabularyWords()->sole()->sentences()->pluck('sentence')->all());
    }

    public function test_a_word_added_with_its_own_example_needs_no_ai_sentence(): void
    {
        $learner = User::factory()->create();
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldNotReceive('chat'));

        $this->actingAs($learner)->postJson(route('vocabulary.store'), ['word' => 'sleep', 'example' => 'I sleep eight hours.'])->assertCreated();
    }

    public function test_the_ladder_shows_which_steps_the_word_has_reached(): void
    {
        $learner = User::factory()->create();
        $new = $this->word($learner, 'new');
        $strong = $this->word($learner, 'strong', ['repetitions' => 4, 'interval_days' => 30, 'last_reviewed_at' => now()]);

        $this->assertSame(['Seen' => true, 'Reviewed' => false, 'Strong' => false, 'Used' => false], $new->ladder());
        $this->assertSame(['Seen' => true, 'Reviewed' => true, 'Strong' => true, 'Used' => false], $strong->ladder());
    }
}
