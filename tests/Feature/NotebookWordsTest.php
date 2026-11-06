<?php

namespace Tests\Feature;

use App\Models\DictionaryEntry;
use App\Models\User;
use App\Models\VocabularyWord;
use App\Models\WordSentence;
use App\Services\GeminiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * The Notebook's Words tab: first tab, one simple sentence under each word
 * with the word picked out, today's few words,
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
            ->assertDontSee('Cover the words')
            ->assertSeeInOrder(['Words', 'Mistakes', 'Tips'])
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

    public function test_a_word_added_from_a_long_line_of_text_still_gets_a_simple_sentence(): void
    {
        $learner = User::factory()->create();
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->andReturn('I sat on the bank.'));

        $this->actingAs($learner)->postJson(route('vocabulary.store'), [
            'word' => 'bank',
            'example' => 'We walked for hours until we finally sat down on the bank of the quiet river.',
        ])->assertCreated();

        $this->assertSame(['I sat on the bank.'], $learner->vocabularyWords()->sole()->sentences()->pluck('sentence')->all());
    }

    public function test_a_word_added_with_its_own_example_needs_no_ai_sentence(): void
    {
        $learner = User::factory()->create();
        // Gemini is asked for the Persian of the word in its sentence, but not for a sentence.
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn(json_encode(['items' => [['id' => '1', 'persian' => 'خوابیدن']]])));

        $this->actingAs($learner)->postJson(route('vocabulary.store'), ['word' => 'sleep', 'example' => 'I sleep eight hours.'])->assertCreated();

        $this->assertSame(0, $learner->vocabularyWords()->sole()->sentences()->count());
    }

    public function test_a_long_line_is_cut_to_the_few_words_around_the_word_in_the_list_but_whole_in_the_sheet(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);
        $word = $this->word($learner, 'bank', ['example' => 'We walked for hours until we finally sat down on the bank of the quiet river near the old mill.']);

        $this->assertSame('… down on the bank of the quiet river …', $word->rowSentence());

        $component = Livewire::test('notebook.index')
            ->assertSeeHtml('>bank</mark>')
            ->assertSee('… down on the');

        $this->assertSame('We walked for hours until we finally sat down on the bank of the quiet river near the old mill.', $component->instance()->wordSheets[$word->id]['sentences'][0]['text']);
    }

    public function test_the_list_and_the_sheet_say_how_common_each_word_is(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);
        $run = $this->word($learner, 'run', ['example' => 'I run every day.']);
        $rare = $this->word($learner, 'perspicacious', ['example' => 'A perspicacious reader.']);
        $phrase = $this->word($learner, 'give up', ['example' => 'Never give up.']);
        $unrated = $this->word($learner, 'look forward to', ['example' => 'I look forward to it.']);
        $hyphen = $this->word($learner, 'well-known', ['example' => 'A well-known song.']);
        DictionaryEntry::factory()->create(['word' => 'give up', 'commonness' => 3]);

        $this->assertSame(3, $run->commonness());
        $this->assertSame(1, $rare->commonness());
        $this->assertSame(3, $phrase->commonness());
        $this->assertNull($unrated->commonness());
        $this->assertNull($hyphen->commonness());

        $component = Livewire::test('notebook.index')
            ->assertSeeHtml('aria-label="Very common"')
            ->assertSeeHtml('aria-label="Less common"');

        $this->assertEquals([$run->id => 3, $rare->id => 1, $phrase->id => 3], $component->instance()->wordCommonness);
        $this->assertSame(3, $component->instance()->wordSheets[$run->id]['commonness']);
        $this->assertNull($component->instance()->wordSheets[$unrated->id]['commonness']);
    }

    public function test_one_shared_sheet_carries_every_words_data_so_a_tap_needs_no_server_call(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);
        $first = $this->word($learner, 'recipe', ['example' => 'I love this recipe.', 'meaning_fa' => 'دستور پخت']);
        $second = $this->word($learner, 'grab', ['example' => 'Grab your coat.']);
        WordSentence::factory()->create(['vocabulary_word_id' => $first->id, 'sentence' => 'I cook a recipe.']);

        $component = Livewire::test('notebook.index');
        $sheets = $component->instance()->wordSheets;

        $this->assertSame([$first->id, $second->id], collect($sheets)->pluck('id')->sort()->values()->all());
        $this->assertSame('دستور پخت', $sheets[$first->id]['fa']);
        $this->assertSame(['I cook a recipe.', 'I love this recipe.'], collect($sheets[$first->id]['sentences'])->pluck('text')->all());
        $this->assertSame(['before' => 'I cook a ', 'hit' => 'recipe', 'after' => '.'], collect($sheets[$first->id]['sentences'][0])->only(['before', 'hit', 'after'])->all());
        $this->assertSame(1, substr_count($component->html(), 'x-data="notebookWordSheet"'));
    }

    public function test_another_learners_word_is_not_in_the_sheet_data(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);
        $mine = $this->word($learner, 'recipe', ['example' => 'I love this recipe.']);
        $stranger = $this->word(User::factory()->create(), 'secret', ['example' => 'It is a secret.']);

        $this->assertSame([$mine->id], array_keys(Livewire::test('notebook.index')->instance()->wordSheets));
        $this->assertNotContains($stranger->id, array_keys(Livewire::test('notebook.index')->instance()->wordSheets));
    }

    public function test_rows_past_the_fourth_of_a_group_are_only_built_on_demand(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);

        foreach (range(1, 6) as $number) {
            $this->word($learner, 'word'.$number, ['example' => 'I know word'.$number.'.']);
        }

        $html = Livewire::test('notebook.index')->html();

        $this->assertSame(2, substr_count($html, '<template x-if="expanded['));
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
