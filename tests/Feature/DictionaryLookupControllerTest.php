<?php

namespace Tests\Feature;

use App\Models\DictionaryEntry;
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
            ->assertRedirect(route('login'));
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

    public function test_it_rejects_a_phrase_with_422(): void
    {
        Http::preventStrayRequests();

        $this->actingAs(User::factory()->create())
            ->getJson(route('dictionary.lookup', ['word' => 'get up']))
            ->assertStatus(422);
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
            ->assertRedirect(route('login'));
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
