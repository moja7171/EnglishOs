<?php

namespace Tests\Feature;

use App\Services\MyMemoryClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MyMemoryClientTest extends TestCase
{
    /**
     * Modelled on the live response for "sleep": the headline translation
     * is the English word handed back, the Persian one is only in matches.
     */
    public function test_it_picks_the_persian_match_for_the_word_instead_of_the_english_echo(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.mymemory.translated.net/get*' => Http::response([
                'responseData' => ['translatedText' => 'sleep', 'match' => 1],
                'quotaFinished' => false,
                'responseStatus' => 200,
                'matches' => [
                    ['segment' => 'sleep', 'translation' => 'sleep', 'match' => 1, 'usage-count' => 2],
                    ['segment' => 'sleep.', 'translation' => 'بخواب.', 'match' => 0.99, 'usage-count' => 12],
                    ['segment' => 'sleep', 'translation' => 'خواب', 'match' => 0.99, 'usage-count' => 2],
                    ['segment' => 'sleeping bag', 'translation' => 'کیسه خواب', 'match' => 0.98, 'usage-count' => 40],
                ],
            ]),
        ]);

        $this->assertSame('خواب', (new MyMemoryClient)->translateToPersian('sleep'));
    }

    public function test_it_falls_back_to_a_confident_persian_headline_translation(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.mymemory.translated.net/get*' => Http::response([
                'responseData' => ['translatedText' => 'خوشحال', 'match' => 0.85],
                'responseStatus' => 200,
                'matches' => [],
            ]),
        ]);

        $this->assertSame('خوشحال', (new MyMemoryClient)->translateToPersian('happy'));
    }

    public function test_it_returns_null_when_nothing_in_the_answer_is_persian(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.mymemory.translated.net/get*' => Http::response([
                'responseData' => ['translatedText' => 'oversleep', 'match' => 1],
                'responseStatus' => 200,
                'matches' => [['segment' => 'oversleep', 'translation' => 'oversleep', 'match' => 1]],
            ]),
        ]);

        $this->assertNull((new MyMemoryClient)->translateToPersian('oversleep'));
    }

    public function test_it_returns_null_when_the_daily_quota_is_used_up(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.mymemory.translated.net/get*' => Http::response([
                'responseData' => ['translatedText' => 'MYMEMORY WARNING: YOU USED ALL AVAILABLE FREE TRANSLATIONS FOR TODAY.', 'match' => 0],
                'quotaFinished' => true,
                'responseStatus' => 429,
                'matches' => '',
            ]),
        ]);

        $this->assertNull((new MyMemoryClient)->translateToPersian('sleep'));
    }

    public function test_it_sends_the_configured_email_to_raise_the_daily_cap(): void
    {
        config(['services.mymemory.email' => 'teacher@example.test']);
        Http::preventStrayRequests();
        Http::fake([
            'api.mymemory.translated.net/get*' => Http::response(['responseStatus' => 200, 'matches' => []]),
        ]);

        (new MyMemoryClient)->translateToPersian('sleep');

        Http::assertSent(fn ($request) => $request['q'] === 'sleep'
            && $request['langpair'] === 'en|fa'
            && $request['de'] === 'teacher@example.test');
    }
}
