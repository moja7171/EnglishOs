<?php

namespace Tests\Feature;

use App\Services\GeminiWordDefiner;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class GeminiWordDefinerTest extends TestCase
{
    private function fakeGemini(string $text): void
    {
        config(['services.gemini.key' => 'test-key', 'services.gemini.chat_models' => ['model-a']]);
        Http::preventStrayRequests();
        Http::fake([
            'generativelanguage.googleapis.com/v1beta/models/model-a:generateContent' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => $text]]]]],
            ]),
        ]);
    }

    public function test_it_groups_the_meanings_by_part_of_speech_and_reads_a_fenced_answer(): void
    {
        $this->fakeGemini("```json\n".json_encode([
            'meanings' => [
                ['pos' => 'Verb', 'definition' => 'to rest with your eyes closed', 'example' => 'I sleep well.'],
                ['pos' => 'noun', 'definition' => 'a period of rest', 'example' => ''],
                ['pos' => 'verb', 'definition' => 'to have beds for people', 'example' => 'The room sleeps four.'],
            ],
            'translation_fa' => 'خواب، خوابیدن',
        ])."\n```");

        $result = app(GeminiWordDefiner::class)->define('sleep');

        $this->assertSame([
            [
                'pos' => 'verb',
                'definitions' => [
                    ['definition' => 'to rest with your eyes closed', 'example' => 'I sleep well.'],
                    ['definition' => 'to have beds for people', 'example' => 'The room sleeps four.'],
                ],
            ],
            ['pos' => 'noun', 'definitions' => [['definition' => 'a period of rest', 'example' => null]]],
        ], $result['meanings']);
        $this->assertSame('خواب، خوابیدن', $result['translation_fa']);
    }

    public function test_it_returns_no_meanings_when_gemini_says_it_is_not_a_word(): void
    {
        $this->fakeGemini('{"meanings": [], "translation_fa": null}');

        $result = app(GeminiWordDefiner::class)->define('qzxv');

        $this->assertSame([], $result['meanings']);
        $this->assertNull($result['translation_fa']);
    }

    public function test_it_throws_when_the_answer_is_not_the_agreed_json(): void
    {
        $this->fakeGemini('Sure! Here is the meaning of sleep.');

        $this->expectException(RuntimeException::class);

        app(GeminiWordDefiner::class)->define('sleep');
    }

    public function test_it_returns_the_persian_meanings_for_each_part_of_speech(): void
    {
        $this->fakeGemini(json_encode(['persian' => [
            ['pos' => 'Verb', 'words' => 'خوابیدن، استراحت کردن'],
            ['pos' => 'noun', 'words' => 'خواب'],
            ['pos' => 'noun', 'words' => 'sleep only, no Persian'],
        ]]));

        $result = app(GeminiWordDefiner::class)->persianMeanings('sleep', [
            ['pos' => 'verb', 'definitions' => [['definition' => 'to rest', 'example' => null]]],
            ['pos' => 'noun', 'definitions' => [['definition' => 'a state of rest', 'example' => null]]],
        ]);

        $this->assertSame([
            ['pos' => 'verb', 'words' => 'خوابیدن، استراحت کردن'],
            ['pos' => 'noun', 'words' => 'خواب'],
        ], $result);
        Http::assertSent(fn ($request) => str_contains($request['contents'][0]['parts'][0]['text'], 'verb: to rest'));
    }

    public function test_the_persian_meanings_throw_when_the_answer_is_not_the_agreed_json(): void
    {
        $this->fakeGemini('Here you go: خواب');

        $this->expectException(RuntimeException::class);

        app(GeminiWordDefiner::class)->persianMeanings('sleep', []);
    }
}
