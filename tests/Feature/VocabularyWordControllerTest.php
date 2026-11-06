<?php

namespace Tests\Feature;

use App\Models\DictionaryEntry;
use App\Models\User;
use App\Models\VocabularyWord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VocabularyWordControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Gemini is only asked for the Persian in context; tests that want it say so.
        config(['services.gemini.key' => '']);
    }

    private function fakeGeminiPersian(string $persian): void
    {
        config(['services.gemini.key' => 'test-key', 'services.gemini.chat_models' => ['model-a']]);
        Http::fake([
            'generativelanguage.googleapis.com/v1beta/models/model-a:generateContent' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode(['items' => [['id' => '1', 'persian' => $persian]]])]]]]],
            ]),
        ]);
    }

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $this->post(route('vocabulary.store'), ['word' => 'sleep'])
            ->assertRedirect(route('welcome'));
    }

    public function test_it_adds_the_word_with_its_persian_meaning_and_a_first_review_tomorrow(): void
    {
        $this->freezeTime();
        DictionaryEntry::factory()->create(['word' => 'sleep', 'translation_fa' => 'خواب']);
        $learner = User::factory()->create();

        $this->actingAs($learner)
            ->postJson(route('vocabulary.store'), [
                'word' => 'Sleep',
                'meaning' => 'To rest in a state of reduced consciousness.',
                'pos' => 'verb',
                'example' => 'I sleep eight hours.',
            ])
            ->assertCreated()
            ->assertJson(['saved' => true, 'already_had' => false]);

        $word = $learner->vocabularyWords()->sole();
        $this->assertSame('sleep', $word->word);
        $this->assertSame('To rest in a state of reduced consciousness.', $word->meaning);
        $this->assertSame('خواب', $word->meaning_fa);
        $this->assertSame('verb', $word->pos);
        $this->assertSame('I sleep eight hours.', $word->example);
        $this->assertSame(now()->addDay()->toDateTimeString(), $word->next_review_at->toDateTimeString());
    }

    public function test_the_persian_meaning_is_the_one_that_fits_the_chosen_meaning_and_sentence(): void
    {
        DictionaryEntry::factory()->create(['word' => 'bank', 'translation_fa' => 'بانک']);
        $this->fakeGeminiPersian('ساحل');
        $learner = User::factory()->create();

        $this->actingAs($learner)
            ->postJson(route('vocabulary.store'), [
                'word' => 'bank',
                'meaning' => 'the land beside a river',
                'pos' => 'noun',
                'example' => 'We sat on the bank of the river.',
            ])
            ->assertCreated();

        $this->assertSame('ساحل', $learner->vocabularyWords()->sole()->meaning_fa);
        Http::assertSent(fn ($request) => str_contains(json_encode($request->data(), JSON_UNESCAPED_UNICODE), 'We sat on the bank of the river.'));
    }

    public function test_the_dictionary_persian_stays_when_gemini_cannot_be_asked(): void
    {
        DictionaryEntry::factory()->create(['word' => 'bank', 'translation_fa' => 'بانک']);
        $learner = User::factory()->create();

        $this->actingAs($learner)
            ->postJson(route('vocabulary.store'), ['word' => 'bank', 'meaning' => 'a place for money', 'example' => 'I go to the bank.'])
            ->assertCreated();

        $this->assertSame('بانک', $learner->vocabularyWords()->sole()->meaning_fa);
    }

    public function test_a_word_the_learner_already_has_keeps_its_persian(): void
    {
        $this->fakeGeminiPersian('ساحل');
        $learner = User::factory()->create();
        VocabularyWord::create([
            'learner_id' => $learner->id,
            'word' => 'bank',
            'meaning' => 'a place for money',
            'meaning_fa' => 'بانک',
            'next_review_at' => now()->addDay(),
        ]);

        $this->actingAs($learner)
            ->postJson(route('vocabulary.store'), ['word' => 'bank', 'meaning' => 'the land beside a river'])
            ->assertOk();

        $this->assertSame('بانک', $learner->vocabularyWords()->sole()->meaning_fa);
        Http::assertNothingSent();
    }

    public function test_the_persian_meaning_comes_from_the_dictionary_not_from_the_request(): void
    {
        DictionaryEntry::factory()->create(['word' => 'sleep', 'translation_fa' => 'خواب']);
        $learner = User::factory()->create();

        $this->actingAs($learner)
            ->postJson(route('vocabulary.store'), ['word' => 'sleep', 'meaning_fa' => 'something forged'])
            ->assertCreated();

        $this->assertSame('خواب', $learner->vocabularyWords()->sole()->meaning_fa);
    }

    public function test_adding_a_word_again_keeps_its_review_progress(): void
    {
        $learner = User::factory()->create();
        $existing = VocabularyWord::create([
            'learner_id' => $learner->id,
            'word' => 'sleep',
            'meaning' => 'to rest',
            'repetitions' => 3,
            'interval_days' => 6,
            'next_review_at' => now()->addDays(6),
        ]);

        $this->actingAs($learner)
            ->postJson(route('vocabulary.store'), ['word' => 'sleep', 'meaning' => 'something else'])
            ->assertOk()
            ->assertJson(['saved' => true, 'already_had' => true]);

        $existing->refresh();
        $this->assertSame(3, $existing->repetitions);
        $this->assertSame('to rest', $existing->meaning);
        $this->assertSame(1, $learner->vocabularyWords()->count());
    }

    public function test_adding_a_word_again_fills_in_a_persian_meaning_it_was_missing(): void
    {
        DictionaryEntry::factory()->create(['word' => 'sleep', 'translation_fa' => 'خواب']);
        $learner = User::factory()->create();
        VocabularyWord::create(['learner_id' => $learner->id, 'word' => 'sleep', 'next_review_at' => now()]);

        $this->actingAs($learner)->postJson(route('vocabulary.store'), ['word' => 'sleep'])->assertOk();

        $this->assertSame('خواب', $learner->vocabularyWords()->sole()->meaning_fa);
    }

    public function test_it_rejects_a_phrase_the_dictionary_does_not_list(): void
    {
        DictionaryEntry::factory()->unknownToDictionary()->create(['word' => 'went to the shop', 'translation_fa' => 'به مغازه رفت']);
        $this->actingAs(User::factory()->create());

        $this->postJson(route('vocabulary.store'), ['word' => 'went to the shop'])->assertJsonValidationErrors('word');
        $this->postJson(route('vocabulary.store'), ['word' => 'never looked up'])->assertJsonValidationErrors('word');

        $this->assertDatabaseCount('vocabulary_words', 0);
    }

    public function test_it_rejects_an_example_that_is_far_too_long(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson(route('vocabulary.store'), ['word' => 'sleep', 'example' => str_repeat('a', 501)])
            ->assertJsonValidationErrors('example');
    }

    public function test_it_adds_a_phrase_with_its_persian_meaning(): void
    {
        DictionaryEntry::factory()->create(['word' => 'give up', 'translation_fa' => 'دست کشیدن']);
        $learner = User::factory()->create();

        $this->actingAs($learner)
            ->postJson(route('vocabulary.store'), ['word' => 'Give up', 'meaning' => 'To stop doing something.', 'pos' => 'verb'])
            ->assertCreated();

        $word = $learner->vocabularyWords()->sole();
        $this->assertSame('give up', $word->word);
        $this->assertSame('دست کشیدن', $word->meaning_fa);
    }
}
