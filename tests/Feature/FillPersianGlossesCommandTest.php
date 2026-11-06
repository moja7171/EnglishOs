<?php

namespace Tests\Feature;

use App\Models\DictionaryEntry;
use App\Models\PersianGloss;
use App\Models\PhraseForm;
use App\Models\User;
use App\Services\PersianGlossary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FillPersianGlossesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['services.gemini.key' => 'test-key', 'services.gemini.chat_models' => ['model-a']]);
    }

    private function fakeGemini(array $entries): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/v1beta/models/model-a:generateContent' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode(['entries' => $entries])]]]]],
            ]),
        ]);
    }

    public function test_it_saves_the_persian_of_a_common_word_the_dictionary_lacks(): void
    {
        $this->fakeGemini([['word' => 'you', 'senses' => [['pos' => 'pronoun', 'fa' => ['تو', 'شما']]]]]);

        $this->artisan('dictionary:fill-persian', ['--top' => 3, '--pause' => 0])->assertSuccessful();

        $gloss = PersianGloss::where('word', 'you')->sole();
        $this->assertSame('pronoun', $gloss->pos);
        $this->assertSame(['تو', 'شما'], $gloss->words);
        $this->assertSame('gemini', $gloss->source);
    }

    public function test_a_word_the_dictionary_already_has_is_not_asked_about(): void
    {
        PersianGloss::create(['word' => 'you', 'pos' => 'pronoun', 'words' => ['شما']]);
        PersianGloss::create(['word' => 'i', 'pos' => 'pronoun', 'words' => ['من']]);
        PersianGloss::create(['word' => 'the', 'pos' => 'determiner', 'words' => ['آن']]);
        $this->fakeGemini([]);

        $this->artisan('dictionary:fill-persian', ['--top' => 3, '--pause' => 0])->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_a_dry_run_saves_nothing(): void
    {
        $this->fakeGemini([['word' => 'you', 'senses' => [['pos' => 'pronoun', 'fa' => ['تو']]]]]);

        $this->artisan('dictionary:fill-persian', ['--top' => 3, '--pause' => 0, '--dry-run' => true])
            ->expectsOutputToContain('you: pronoun تو')
            ->assertSuccessful();

        $this->assertSame(0, PersianGloss::count());
    }

    public function test_a_word_gemini_declines_is_not_asked_again(): void
    {
        $this->fakeGemini([['word' => 'you', 'senses' => [['pos' => 'pronoun', 'fa' => ['تو']]]]]);
        $this->artisan('dictionary:fill-persian', ['--top' => 3, '--pause' => 0])->assertSuccessful();

        $this->fakeGemini([]);
        $this->artisan('dictionary:fill-persian', ['--top' => 3, '--pause' => 0])->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_an_answer_without_persian_letters_or_for_a_word_not_asked_is_ignored(): void
    {
        $this->fakeGemini([
            ['word' => 'you', 'senses' => [['pos' => 'pronoun', 'fa' => ['thou']]]],
            ['word' => 'stranger', 'senses' => [['pos' => 'noun', 'fa' => ['غریبه']]]],
        ]);

        $this->artisan('dictionary:fill-persian', ['--top' => 3, '--pause' => 0])->assertSuccessful();

        $this->assertSame(0, PersianGloss::count());
    }

    public function test_it_can_fill_listed_phrases_too(): void
    {
        PersianGloss::create(['word' => 'you', 'pos' => 'pronoun', 'words' => ['شما']]);
        PersianGloss::create(['word' => 'i', 'pos' => 'pronoun', 'words' => ['من']]);
        PersianGloss::create(['word' => 'the', 'pos' => 'determiner', 'words' => ['آن']]);
        PhraseForm::create(['form' => 'give up', 'phrase' => 'give up']);
        $this->fakeGemini([['word' => 'give up', 'senses' => [['pos' => 'phrase', 'fa' => ['تسلیم شدن', 'ول کردن']]]]]);

        $this->artisan('dictionary:fill-persian', ['--top' => 3, '--phrases' => true, '--pause' => 0])->assertSuccessful();

        $this->assertSame(['تسلیم شدن', 'ول کردن'], PersianGloss::where('word', 'give up')->sole()->words);
    }

    public function test_it_stops_and_fails_when_gemini_cannot_be_asked(): void
    {
        config(['services.gemini.key' => '']);

        $this->artisan('dictionary:fill-persian', ['--top' => 3, '--pause' => 0])->assertFailed();

        $this->assertSame(0, PersianGloss::count());
    }

    public function test_the_sheet_credits_gemini_not_wiktionary_for_a_filled_word(): void
    {
        PersianGloss::create(['word' => 'boost', 'pos' => 'verb', 'words' => ['تقویت کردن'], 'source' => 'gemini']);

        $this->assertSame([['pos' => 'verb', 'words' => 'تقویت کردن', 'source' => 'gemini']], app(PersianGlossary::class)->senses('boost'));

        DictionaryEntry::where('word', 'boost')->delete();
        Http::fake(['*' => Http::response([], 404)]);

        $this->actingAs(User::factory()->create())
            ->getJson(route('dictionary.lookup', ['word' => 'boost']))
            ->assertOk()
            ->assertJsonPath('data.persian_source', 'gemini');
    }
}
