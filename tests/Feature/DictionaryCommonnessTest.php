<?php

namespace Tests\Feature;

use App\Models\DictionaryEntry;
use App\Models\User;
use App\Services\WordFrequency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DictionaryCommonnessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.gemini.key' => '']);
    }

    private function fakeGeminiCommonness(mixed $level): void
    {
        config(['services.gemini.key' => 'test-key', 'services.gemini.chat_models' => ['model-a']]);
        Http::fake([
            'generativelanguage.googleapis.com/v1beta/models/model-a:generateContent' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode(['commonness' => $level])]]]]],
            ]),
        ]);
    }

    public function test_a_word_is_rated_from_the_frequency_list(): void
    {
        $frequency = new WordFrequency;

        $this->assertSame(WordFrequency::VERY_COMMON, $frequency->levelOf('run'));
        $this->assertSame(WordFrequency::COMMON, $frequency->levelOf('boost'));
        $this->assertSame(WordFrequency::RARE, $frequency->levelOf('perspicacious'));
        $this->assertNull($frequency->levelOf('give up'));
    }

    public function test_a_form_of_a_word_is_rated_like_the_word(): void
    {
        $this->assertSame(WordFrequency::VERY_COMMON, (new WordFrequency)->levelOf('running'));
    }

    public function test_the_lookup_says_how_common_a_single_word_is(): void
    {
        DictionaryEntry::factory()->create(['word' => 'run']);

        $this->actingAs(User::factory()->create())
            ->getJson(route('dictionary.lookup', ['word' => 'run']))
            ->assertOk()
            ->assertJsonPath('data.commonness', 3);
    }

    public function test_a_phrase_has_no_rating_until_it_is_asked_for(): void
    {
        DictionaryEntry::factory()->create(['word' => 'give up']);

        $this->actingAs(User::factory()->create())
            ->getJson(route('dictionary.lookup', ['word' => 'give up']))
            ->assertOk()
            ->assertJsonPath('data.commonness', null);
    }

    public function test_a_phrase_is_rated_by_gemini_once_and_kept(): void
    {
        Cache::flush();
        $this->fakeGeminiCommonness(3);
        $entry = DictionaryEntry::factory()->create(['word' => 'give up', 'persian_senses' => [['pos' => 'verb', 'words' => 'تسلیم شدن']]]);
        $learner = User::factory()->create();

        $this->actingAs($learner)
            ->getJson(route('dictionary.persian', ['word' => 'give up']))
            ->assertOk()
            ->assertJsonPath('data.commonness', 3);

        $this->assertSame(3, $entry->fresh()->commonness);

        $this->actingAs($learner)->getJson(route('dictionary.lookup', ['word' => 'give up']))
            ->assertJsonPath('data.commonness', 3);
        Http::assertSentCount(1);
    }

    public function test_a_phrase_stays_unrated_when_gemini_cannot_say(): void
    {
        Cache::flush();
        $entry = DictionaryEntry::factory()->create(['word' => 'give up']);

        $this->actingAs(User::factory()->create())
            ->getJson(route('dictionary.persian', ['word' => 'give up']))
            ->assertStatus(503);

        $this->assertNull($entry->fresh()->commonness);
    }

    public function test_a_phrase_the_dictionary_does_not_list_gets_no_rating(): void
    {
        Cache::flush();
        $this->fakeGeminiCommonness(3);
        DictionaryEntry::factory()->unknownToDictionary()->create(['word' => 'went to the shop']);

        $this->actingAs(User::factory()->create())
            ->getJson(route('dictionary.lookup', ['word' => 'went to the shop']))
            ->assertOk()
            ->assertJsonPath('data.commonness', null);

        Http::assertNothingSent();
    }
}
