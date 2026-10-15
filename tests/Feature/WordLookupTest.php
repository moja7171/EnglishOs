<?php

namespace Tests\Feature;

use App\Models\DictionaryEntry;
use App\Services\WordLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WordLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Gemini is the dictionary's second opinion; tests that want it say so.
        config(['services.gemini.key' => '']);
    }

    private function fakeGeminiAnswer(array $answer): void
    {
        config(['services.gemini.key' => 'test-key', 'services.gemini.chat_models' => ['model-a']]);
        Http::fake([
            'generativelanguage.googleapis.com/v1beta/models/model-a:generateContent' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode($answer)]]]]],
            ]),
        ]);
    }

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function wordsAsTyped(): array
    {
        return [
            'capitals and a full stop' => ['Sleep.', 'sleep'],
            'quotes around it' => ['“Morning”', 'morning'],
            'a curly apostrophe inside' => ['don’t', "don't"],
            'a hyphenated word' => ['well-known', 'well-known'],
            'a phrase' => ['get up', null],
            'a number' => ['2026', null],
            'another script' => ['خواب', null],
            'nothing but punctuation' => ['...', null],
            'far too long' => [str_repeat('a', 41), null],
        ];
    }

    #[DataProvider('wordsAsTyped')]
    public function test_it_normalizes_only_a_single_plain_english_word(string $typed, ?string $expected): void
    {
        $this->assertSame($expected, WordLookup::normalize($typed));
    }

    private function fakeBothServices(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'en.wiktionary.org/api/rest_v1/page/definition/sleep' => Http::response(['en' => [
                ['partOfSpeech' => 'Verb', 'definitions' => [['definition' => 'To rest.']]],
            ]]),
            'api.mymemory.translated.net/get*' => Http::response([
                'responseData' => ['translatedText' => 'sleep', 'match' => 1],
                'responseStatus' => 200,
                'matches' => [['segment' => 'sleep', 'translation' => 'خواب', 'match' => 0.99, 'usage-count' => 2]],
            ]),
        ]);
    }

    public function test_it_looks_a_new_word_up_in_both_services_and_keeps_the_answer(): void
    {
        $this->fakeBothServices();

        $entry = app(WordLookup::class)->lookup('Sleep');

        $this->assertSame('sleep', $entry->word);
        $this->assertSame('verb', $entry->meanings[0]['pos']);
        $this->assertSame('To rest.', $entry->meanings[0]['definitions'][0]['definition']);
        $this->assertSame('خواب', $entry->translation_fa);
        $this->assertDatabaseHas('dictionary_entries', ['word' => 'sleep', 'translation_fa' => 'خواب']);
    }

    public function test_a_word_already_kept_is_never_asked_about_again(): void
    {
        DictionaryEntry::factory()->create(['word' => 'sleep']);
        Http::preventStrayRequests();
        Http::fake();

        $entry = app(WordLookup::class)->lookup('sleep');

        $this->assertSame('sleep', $entry->word);
        Http::assertNothingSent();
    }

    public function test_a_phrase_is_not_looked_up(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $this->assertNull(app(WordLookup::class)->lookup('get up'));
        Http::assertNothingSent();
        $this->assertDatabaseCount('dictionary_entries', 0);
    }

    public function test_a_word_the_dictionary_does_not_know_is_kept_as_unknown_with_its_translation(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'en.wiktionary.org/*' => Http::response(['status' => 404, 'type' => 'Internal error'], 404),
            'api.mymemory.translated.net/get*' => Http::response([
                'responseStatus' => 200,
                'matches' => [['segment' => 'overslept', 'translation' => 'بیش از حد خوابید', 'match' => 0.9]],
            ]),
        ]);

        $entry = app(WordLookup::class)->lookup('overslept');

        $this->assertFalse($entry->isKnownToDictionary());
        $this->assertSame('بیش از حد خوابید', $entry->translation_fa);
        $this->assertDatabaseHas('dictionary_entries', ['word' => 'overslept']);
    }

    public function test_it_returns_nothing_and_keeps_nothing_when_neither_service_can_be_reached(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'en.wiktionary.org/*' => Http::response('Bad gateway', 502),
            'api.mymemory.translated.net/*' => Http::failedConnection(),
        ]);

        $this->assertNull(app(WordLookup::class)->lookup('sleep'));
        $this->assertDatabaseCount('dictionary_entries', 0);
    }

    public function test_a_404_from_a_relay_is_not_cached_as_an_unknown_word(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'en.wiktionary.org/*' => Http::response('target not allowed', 404),
            'api.mymemory.translated.net/get*' => Http::response([
                'responseStatus' => 200,
                'matches' => [['segment' => 'sleep', 'translation' => 'خواب', 'match' => 0.99]],
            ]),
        ]);

        $entry = app(WordLookup::class)->lookup('sleep');

        $this->assertFalse($entry->exists);
        $this->assertDatabaseCount('dictionary_entries', 0);
    }

    public function test_it_asks_both_services_once_for_a_new_word(): void
    {
        $this->fakeBothServices();

        app(WordLookup::class)->lookup('sleep');

        Http::assertSentCount(2);
    }

    public function test_an_unreachable_dictionary_is_not_cached_as_an_unknown_word(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'en.wiktionary.org/*' => Http::response('Bad gateway', 502),
            'api.mymemory.translated.net/get*' => Http::response([
                'responseStatus' => 200,
                'matches' => [['segment' => 'sleep', 'translation' => 'خواب', 'match' => 0.99]],
            ]),
        ]);

        $entry = app(WordLookup::class)->lookup('sleep');

        $this->assertSame('خواب', $entry->translation_fa);
        $this->assertDatabaseCount('dictionary_entries', 0);
    }

    public function test_it_keeps_the_english_half_when_the_translation_service_is_down(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'en.wiktionary.org/*' => Http::response(['en' => [['partOfSpeech' => 'Verb', 'definitions' => [['definition' => 'To rest.']]]]]),
            'api.mymemory.translated.net/*' => Http::response('Unavailable', 503),
        ]);

        $entry = app(WordLookup::class)->lookup('sleep');

        $this->assertTrue($entry->isKnownToDictionary());
        $this->assertNull($entry->translation_fa);
        $this->assertDatabaseHas('dictionary_entries', ['word' => 'sleep', 'translation_fa' => null]);
    }

    public function test_a_missing_translation_is_asked_for_again_on_a_later_lookup_but_not_straight_away(): void
    {
        DictionaryEntry::factory()->withoutTranslation()->create(['word' => 'sleep']);
        Http::preventStrayRequests();
        Http::fake([
            'api.mymemory.translated.net/get*' => Http::sequence()
                ->push(['responseStatus' => 200, 'matches' => []])
                ->push(['responseStatus' => 200, 'matches' => [['segment' => 'sleep', 'translation' => 'خواب', 'match' => 0.99]]]),
        ]);
        $lookup = app(WordLookup::class);

        $lookup->lookup('sleep');
        $lookup->lookup('sleep');
        Http::assertSentCount(1);
        $this->assertNull(DictionaryEntry::firstWhere('word', 'sleep')->translation_fa);

        $this->travel(11)->minutes();
        $entry = $lookup->lookup('sleep');

        Http::assertSentCount(2);
        $this->assertSame('خواب', $entry->translation_fa);
        $this->assertDatabaseHas('dictionary_entries', ['word' => 'sleep', 'translation_fa' => 'خواب']);
    }

    public function test_gemini_gives_the_meanings_when_the_dictionary_cannot_be_reached(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'en.wiktionary.org/*' => Http::response('Bad gateway', 502),
            'api.mymemory.translated.net/get*' => Http::response([
                'responseStatus' => 200,
                'matches' => [['segment' => 'sleep', 'translation' => 'خواب', 'match' => 0.99]],
            ]),
        ]);
        $this->fakeGeminiAnswer([
            'meanings' => [['pos' => 'verb', 'definition' => 'to rest with your eyes closed', 'example' => 'I sleep well.']],
            'translation_fa' => 'خوابیدن',
        ]);

        $entry = app(WordLookup::class)->lookup('sleep');

        $this->assertTrue($entry->exists);
        $this->assertSame('verb', $entry->meanings[0]['pos']);
        $this->assertSame('I sleep well.', $entry->meanings[0]['definitions'][0]['example']);
        $this->assertSame('خواب', $entry->translation_fa);
        $this->assertDatabaseHas('dictionary_entries', ['word' => 'sleep']);
    }

    public function test_gemini_explains_a_form_the_dictionary_does_not_list_and_supplies_the_persian_too(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'en.wiktionary.org/*' => Http::response(['status' => 404, 'type' => 'Internal error'], 404),
            'api.mymemory.translated.net/get*' => Http::response(['responseStatus' => 200, 'matches' => []]),
        ]);
        $this->fakeGeminiAnswer([
            'meanings' => [['pos' => 'verb', 'definition' => 'past tense of oversleep: to sleep longer than you meant to', 'example' => 'I overslept.']],
            'translation_fa' => 'بیش از حد خوابید',
        ]);

        $entry = app(WordLookup::class)->lookup('overslept');

        $this->assertTrue($entry->isKnownToDictionary());
        $this->assertSame('بیش از حد خوابید', $entry->translation_fa);
    }

    public function test_a_word_neither_the_dictionary_nor_gemini_knows_is_kept_as_unknown(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'en.wiktionary.org/*' => Http::response(['status' => 404, 'type' => 'Internal error'], 404),
            'api.mymemory.translated.net/get*' => Http::response(['responseStatus' => 200, 'matches' => []]),
        ]);
        $this->fakeGeminiAnswer(['meanings' => [], 'translation_fa' => null]);

        $entry = app(WordLookup::class)->lookup('qzxv');

        $this->assertTrue($entry->exists);
        $this->assertFalse($entry->isKnownToDictionary());
    }
}
