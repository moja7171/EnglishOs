<?php

namespace Tests\Feature;

use App\Services\WiktionaryClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WiktionaryClientTest extends TestCase
{
    public function test_it_returns_plain_text_meanings_by_part_of_speech(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'en.wiktionary.org/api/rest_v1/page/definition/sleep' => Http::response(['en' => [
                [
                    'partOfSpeech' => 'Verb',
                    'language' => 'English',
                    'definitions' => [
                        [
                            'definition' => 'To rest in a <a rel="mw:WikiLink" href="/wiki/state">state</a> of reduced &amp; slow activity.',
                            'examples' => ['You should <b>sleep</b> eight hours a day.', 'A second example that is not kept.'],
                        ],
                        ['definition' => '<span class="usage-label-sense"></span> To accommodate in beds.'],
                        ['definition' => '   '],
                        ['definition' => 'To be dead.'],
                        ['definition' => 'A fourth sense that is not kept.'],
                    ],
                ],
                [
                    'partOfSpeech' => 'Noun',
                    'definitions' => [['definition' => 'A state of rest.', 'examples' => ['I need some <b>sleep</b>.']]],
                ],
            ]]),
        ]);

        $result = (new WiktionaryClient)->define('sleep');

        $this->assertSame([
            [
                'pos' => 'verb',
                'definitions' => [
                    ['definition' => 'To rest in a state of reduced & slow activity.', 'example' => 'You should sleep eight hours a day.'],
                    ['definition' => 'To accommodate in beds.', 'example' => null],
                    ['definition' => 'To be dead.', 'example' => null],
                ],
            ],
            ['pos' => 'noun', 'definitions' => [['definition' => 'A state of rest.', 'example' => 'I need some sleep.']]],
        ], $result['meanings']);
    }

    public function test_it_shortens_a_very_long_definition(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'en.wiktionary.org/*' => Http::response(['en' => [
                ['partOfSpeech' => 'Adjective', 'definitions' => [['definition' => str_repeat('word ', 100)]]],
            ]]),
        ]);

        $definition = (new WiktionaryClient)->define('happy')['meanings'][0]['definitions'][0]['definition'];

        $this->assertLessThanOrEqual(223, mb_strlen($definition));
        $this->assertStringEndsWith('...', $definition);
    }

    public function test_it_returns_null_when_wiktionary_has_no_entry_for_the_word(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'en.wiktionary.org/*' => Http::response(['status' => 404, 'type' => 'Internal error'], 404),
        ]);

        $this->assertNull((new WiktionaryClient)->define('qzxvbnm'));
    }

    public function test_a_page_with_no_english_section_is_an_unknown_word(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'en.wiktionary.org/*' => Http::response(['fr' => [['partOfSpeech' => 'Noun', 'definitions' => [['definition' => 'un mot']]]]]),
        ]);

        $this->assertNull((new WiktionaryClient)->define('mot'));
    }

    public function test_a_404_that_is_not_wiktionarys_own_answer_is_a_failure_not_an_unknown_word(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'en.wiktionary.org/*' => Http::response('<html>Not Found</html>', 404),
        ]);

        $this->expectException(RequestException::class);

        (new WiktionaryClient)->define('sleep');
    }

    public function test_it_throws_when_wiktionary_cannot_be_asked(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'en.wiktionary.org/*' => Http::response('Bad gateway', 502),
        ]);

        $this->expectException(RequestException::class);

        (new WiktionaryClient)->define('sleep');
    }

    public function test_it_says_who_is_asking_as_wikimedia_requires(): void
    {
        Http::preventStrayRequests();
        Http::fake(['en.wiktionary.org/*' => Http::response(['en' => []])]);

        (new WiktionaryClient)->define('sleep');

        Http::assertSent(fn ($request) => str_starts_with($request->header('User-Agent')[0], 'EnglishOS/'));
    }

    public function test_it_sends_the_request_through_the_relay_when_one_is_configured(): void
    {
        config([
            'services.ai_proxy.url' => 'https://relay.example.test',
            'services.ai_proxy.secret' => 'relay-secret',
        ]);
        Http::preventStrayRequests();
        Http::fake(['relay.example.test' => Http::response(['en' => []])]);

        (new WiktionaryClient)->define('sleep');

        Http::assertSent(fn ($request) => $request->url() === 'https://relay.example.test'
            && $request->header('X-Relay-Url') === ['https://en.wiktionary.org/api/rest_v1/page/definition/sleep']
            && $request->header('X-Relay-Auth') === ['relay-secret']);
    }
}
