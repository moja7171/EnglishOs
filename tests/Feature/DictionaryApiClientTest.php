<?php

namespace Tests\Feature;

use App\Services\DictionaryApiClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DictionaryApiClientTest extends TestCase
{
    public function test_it_returns_the_phonetic_an_american_recording_and_meanings_by_part_of_speech(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.dictionaryapi.dev/api/v2/entries/en/sleep' => Http::response([[
                'word' => 'sleep',
                'phonetic' => '/sliːp/',
                'phonetics' => [
                    ['text' => '/sliːp/', 'audio' => ''],
                    ['text' => '/slip/', 'audio' => 'https://api.dictionaryapi.dev/media/pronunciations/en/sleep-uk.mp3'],
                    ['text' => '/slip/', 'audio' => 'https://api.dictionaryapi.dev/media/pronunciations/en/sleep-us.mp3'],
                ],
                'meanings' => [
                    [
                        'partOfSpeech' => 'verb',
                        'definitions' => [
                            ['definition' => 'To rest in a state of reduced consciousness.', 'example' => 'You should sleep 8 hours a day.'],
                            ['definition' => 'To accommodate in beds.'],
                            ['definition' => 'To be dead.'],
                            ['definition' => 'A fourth sense that is not kept.'],
                        ],
                    ],
                    [
                        'partOfSpeech' => 'noun',
                        'definitions' => [['definition' => 'A state of rest.']],
                    ],
                ],
            ]]),
        ]);

        $result = (new DictionaryApiClient)->define('sleep');

        $this->assertSame('/sliːp/', $result['phonetic']);
        $this->assertSame('https://api.dictionaryapi.dev/media/pronunciations/en/sleep-us.mp3', $result['audio_url']);
        $this->assertSame([
            [
                'pos' => 'verb',
                'definitions' => [
                    ['definition' => 'To rest in a state of reduced consciousness.', 'example' => 'You should sleep 8 hours a day.'],
                    ['definition' => 'To accommodate in beds.', 'example' => null],
                    ['definition' => 'To be dead.', 'example' => null],
                ],
            ],
            [
                'pos' => 'noun',
                'definitions' => [['definition' => 'A state of rest.', 'example' => null]],
            ],
        ], $result['meanings']);
    }

    public function test_it_returns_null_when_the_dictionary_does_not_know_the_word(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.dictionaryapi.dev/*' => Http::response(['title' => 'No Definitions Found'], 404),
        ]);

        $this->assertNull((new DictionaryApiClient)->define('xqzvbn'));
    }

    public function test_it_throws_when_the_dictionary_cannot_be_asked(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.dictionaryapi.dev/*' => Http::response('Bad gateway', 502),
        ]);

        $this->expectException(RequestException::class);

        (new DictionaryApiClient)->define('sleep');
    }

    public function test_it_sends_the_request_through_the_relay_when_one_is_configured(): void
    {
        config([
            'services.ai_proxy.url' => 'https://relay.example.test',
            'services.ai_proxy.secret' => 'relay-secret',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'relay.example.test' => Http::response([['word' => 'sleep', 'meanings' => []]]),
        ]);

        (new DictionaryApiClient)->define('sleep');

        Http::assertSent(fn ($request) => $request->url() === 'https://relay.example.test'
            && $request->header('X-Relay-Url') === ['https://api.dictionaryapi.dev/api/v2/entries/en/sleep']
            && $request->header('X-Relay-Auth') === ['relay-secret']);
    }
}
