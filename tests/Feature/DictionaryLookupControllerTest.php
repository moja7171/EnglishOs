<?php

namespace Tests\Feature;

use App\Models\DictionaryEntry;
use App\Models\PhraseForm;
use App\Models\User;
use App\Models\VocabularyWord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DictionaryLookupControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.gemini.key' => '']);
    }

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $this->get(route('dictionary.lookup', ['word' => 'sleep']))
            ->assertRedirect(route('welcome'));
    }

    public function test_it_returns_the_entry_of_a_word_the_learner_does_not_have_yet(): void
    {
        DictionaryEntry::factory()->create(['word' => 'sleep', 'translation_fa' => 'خواب', 'phonetic' => '/sliːp/']);

        $this->actingAs(User::factory()->create())
            ->getJson(route('dictionary.lookup', ['word' => 'Sleep.']))
            ->assertOk()
            ->assertJsonPath('data.word', 'sleep')
            ->assertJsonPath('data.phonetic', '/sliːp/')
            ->assertJsonPath('data.translation_fa', 'خواب')
            ->assertJsonPath('data.meanings.0.pos', 'noun')
            ->assertJsonPath('saved', false);
    }

    public function test_it_tells_the_sheet_whether_the_english_meaning_was_found_unknown_or_unavailable(): void
    {
        DictionaryEntry::factory()->create(['word' => 'sleep']);
        DictionaryEntry::factory()->unknownToDictionary()->create(['word' => 'overslept']);
        Http::preventStrayRequests();
        Http::fake([
            'en.wiktionary.org/*' => Http::response('Bad gateway', 502),
            'api.mymemory.translated.net/get*' => Http::response([
                'responseStatus' => 200,
                'matches' => [['segment' => 'wake', 'translation' => 'بیدار شدن', 'match' => 0.99]],
            ]),
        ]);
        $this->actingAs(User::factory()->create());

        $this->getJson(route('dictionary.lookup', ['word' => 'sleep']))->assertJsonPath('data.english_status', 'ok');
        $this->getJson(route('dictionary.lookup', ['word' => 'overslept']))->assertJsonPath('data.english_status', 'unknown');
        $this->getJson(route('dictionary.lookup', ['word' => 'wake']))->assertJsonPath('data.english_status', 'unavailable');
    }

    public function test_it_says_when_the_word_is_already_in_my_words(): void
    {
        DictionaryEntry::factory()->create(['word' => 'sleep']);
        $learner = User::factory()->create();
        VocabularyWord::create(['learner_id' => $learner->id, 'word' => 'sleep', 'next_review_at' => now()]);

        $this->actingAs($learner)
            ->getJson(route('dictionary.lookup', ['word' => 'sleep']))
            ->assertOk()
            ->assertJsonPath('saved', true);
    }

    public function test_another_learners_word_does_not_count_as_saved(): void
    {
        DictionaryEntry::factory()->create(['word' => 'sleep']);
        VocabularyWord::create(['learner_id' => User::factory()->create()->id, 'word' => 'sleep', 'next_review_at' => now()]);

        $this->actingAs(User::factory()->create())
            ->getJson(route('dictionary.lookup', ['word' => 'sleep']))
            ->assertOk()
            ->assertJsonPath('saved', false);
    }

    public function test_it_rejects_what_is_not_a_word_or_a_short_phrase_with_422(): void
    {
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create());

        $this->getJson(route('dictionary.lookup', ['word' => '2026']))->assertStatus(422);
        $this->getJson(route('dictionary.lookup', ['word' => 'one two three four five six seven']))->assertStatus(422);
    }

    public function test_it_offers_the_phrase_the_tapped_word_is_part_of(): void
    {
        DictionaryEntry::factory()->create(['word' => 'gave']);
        DictionaryEntry::factory()->create(['word' => 'give up', 'translation_fa' => 'دست کشیدن']);
        PhraseForm::create(['form' => 'gave up', 'phrase' => 'give up']);

        $this->actingAs(User::factory()->create())
            ->getJson(route('dictionary.lookup', ['word' => 'gave', 'before' => 'She', 'after' => 'up smoking last']))
            ->assertOk()
            ->assertJsonPath('data.word', 'gave')
            ->assertJsonPath('phrase', ['phrase' => 'give up', 'from' => 0, 'to' => 1, 'translation_fa' => 'دست کشیدن']);
    }

    public function test_a_phrase_is_answered_under_its_base_form_and_says_whether_it_is_saved(): void
    {
        DictionaryEntry::factory()->create(['word' => 'give up']);
        PhraseForm::create(['form' => 'gave up', 'phrase' => 'give up']);
        $learner = User::factory()->create();
        VocabularyWord::create(['learner_id' => $learner->id, 'word' => 'give up', 'next_review_at' => now()]);

        $this->actingAs($learner)
            ->getJson(route('dictionary.lookup', ['word' => 'gave up']))
            ->assertOk()
            ->assertJsonPath('data.word', 'give up')
            ->assertJsonPath('data.translation_only', false)
            ->assertJsonPath('saved', true)
            ->assertJsonPath('phrase', null);
    }

    public function test_a_phrase_the_dictionary_does_not_list_is_marked_translation_only(): void
    {
        DictionaryEntry::factory()->unknownToDictionary()->create(['word' => 'went to the shop', 'translation_fa' => 'به مغازه رفت']);

        $this->actingAs(User::factory()->create())
            ->getJson(route('dictionary.lookup', ['word' => 'went to the shop']))
            ->assertOk()
            ->assertJsonPath('data.translation_only', true)
            ->assertJsonPath('data.translation_fa', 'به مغازه رفت');
    }

    public function test_it_returns_503_when_neither_service_can_be_reached(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'en.wiktionary.org/*' => Http::failedConnection(),
            'api.mymemory.translated.net/*' => Http::failedConnection(),
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson(route('dictionary.lookup', ['word' => 'sleep']))
            ->assertStatus(503);
    }

    public function test_a_guest_cannot_ask_for_better_persian_meanings(): void
    {
        $this->get(route('dictionary.persian', ['word' => 'sleep']))
            ->assertRedirect(route('welcome'));
    }

    public function test_it_returns_the_better_persian_meanings_of_a_word_already_looked_up(): void
    {
        DictionaryEntry::factory()->create(['word' => 'sleep']);
        config(['services.gemini.key' => 'test-key', 'services.gemini.chat_models' => ['model-a']]);
        Http::preventStrayRequests();
        Http::fake([
            'generativelanguage.googleapis.com/v1beta/models/model-a:generateContent' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode(['persian' => [['pos' => 'noun', 'words' => 'خواب']]])]]]]],
            ]),
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson(route('dictionary.persian', ['word' => 'Sleep']))
            ->assertOk()
            ->assertJsonPath('data.persian_senses.0.words', 'خواب');
    }

    public function test_it_refuses_a_word_that_was_never_looked_up(): void
    {
        Http::preventStrayRequests();

        $this->actingAs(User::factory()->create())
            ->getJson(route('dictionary.persian', ['word' => 'sleep']))
            ->assertNotFound();
    }

    public function test_it_returns_503_when_gemini_cannot_give_better_meanings(): void
    {
        DictionaryEntry::factory()->create(['word' => 'sleep']);

        $this->actingAs(User::factory()->create())
            ->getJson(route('dictionary.persian', ['word' => 'sleep']))
            ->assertStatus(503);
    }
}
