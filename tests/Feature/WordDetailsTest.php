<?php

namespace Tests\Feature;

use App\Models\DictionaryEntry;
use App\Models\User;
use App\Models\VocabularyWord;
use App\Services\GeminiClient;
use App\Services\WordDetails;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Words that miss their type, meaning, Persian or a sentence get them from
 * the dictionary (and one simple sentence from the AI), and never repeat
 * a question for a word that has everything.
 */
class WordDetailsTest extends TestCase
{
    use RefreshDatabase;

    private function word(User $learner, array $attributes = []): VocabularyWord
    {
        return VocabularyWord::factory()->create(['learner_id' => $learner->id, ...$attributes]);
    }

    public function test_a_word_with_nothing_but_its_spelling_gets_type_meaning_persian_and_a_sentence(): void
    {
        $learner = User::factory()->create();
        DictionaryEntry::factory()->create(['word' => 'commute', 'translation_fa' => 'رفت‌وآمد']);
        $word = $this->word($learner, ['word' => 'commute', 'pos' => null, 'meaning' => null, 'meaning_fa' => null]);
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn('I commute by bus.'));

        $this->assertTrue(app(WordDetails::class)->fill($word));

        $word->refresh();
        $this->assertSame('noun', $word->pos);
        $this->assertSame('a single unit of language', $word->meaning);
        $this->assertSame('رفت‌وآمد', $word->meaning_fa);
        $this->assertSame('I commute by bus.', $word->displaySentence());
    }

    public function test_a_word_that_has_everything_asks_nobody(): void
    {
        $learner = User::factory()->create();
        $word = $this->word($learner, ['example' => 'I sleep eight hours.']);
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldNotReceive('chat'));

        $this->assertFalse(app(WordDetails::class)->fill($word));
    }

    public function test_the_command_fills_what_is_missing_and_a_dry_run_only_lists_it(): void
    {
        $learner = User::factory()->create();
        DictionaryEntry::factory()->create(['word' => 'commute']);
        $word = $this->word($learner, ['word' => 'commute', 'meaning_fa' => null, 'example' => 'I commute daily.']);

        $this->artisan('vocabulary:fill-details', ['--dry-run' => true, '--pause' => 0])
            ->expectsOutputToContain('commute: meaning_fa missing')
            ->assertSuccessful();
        $this->assertNull($word->refresh()->meaning_fa);

        $this->artisan('vocabulary:fill-details', ['--pause' => 0])->assertSuccessful();
        $this->assertSame('کلمه', $word->refresh()->meaning_fa);
    }

    public function test_the_notebook_fills_the_missing_details_after_the_page_loads_and_waits_before_asking_again(): void
    {
        Cache::flush();
        $learner = User::factory()->create();
        DictionaryEntry::factory()->create(['word' => 'commute']);
        $word = $this->word($learner, ['word' => 'commute', 'meaning_fa' => null, 'example' => 'I commute daily.']);
        $this->actingAs($learner);

        Livewire::test('notebook.index');
        $this->assertSame('کلمه', $word->refresh()->meaning_fa);

        $this->assertTrue(Cache::has('word-details-asked:'.$learner->id), 'the learner is not asked about again for a while');
    }

    public function test_a_review_batch_gets_its_missing_details_filled_after_the_page_loads(): void
    {
        Cache::flush();
        $learner = User::factory()->create();
        DictionaryEntry::factory()->create(['word' => 'commute']);
        $word = $this->word($learner, ['word' => 'commute', 'meaning_fa' => null, 'example' => 'I commute daily.', 'next_review_at' => now()->subMinute()]);
        $this->actingAs($learner);

        Livewire::test('review.index', ['track' => 'words']);

        $this->assertSame('کلمه', $word->refresh()->meaning_fa);
    }

    public function test_a_word_whose_sheet_is_not_on_the_page_is_fetched_when_tapped_and_never_another_learners(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);
        $mine = $this->word($learner, ['word' => 'recipe', 'example' => 'I love this recipe.']);
        $stranger = $this->word(User::factory()->create(), ['word' => 'secret', 'example' => 'It is a secret.']);

        $component = Livewire::test('notebook.index')->call('wordSheet', $mine->id);

        $this->assertSame('recipe', $component->effects['returns'][0]['word'] ?? null);
        $this->assertSame($mine->id, $component->effects['returns'][0]['id'] ?? null);
        $this->expectException(ModelNotFoundException::class);
        $component->call('wordSheet', $stranger->id);
    }

    public function test_only_the_most_likely_words_ride_on_the_page(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);

        foreach (range(1, 36) as $number) {
            $this->word($learner, ['word' => 'word'.$number, 'example' => 'I know word'.$number.'.']);
        }

        $this->assertCount(30, Livewire::test('notebook.index')->instance()->wordSheets);
    }
}
