<?php

namespace Tests\Feature;

use App\Services\WebVttParser;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class TranscriptTranslationTest extends TestCase
{
    /**
     * @return list<array{text: string, start: float, end: float, fa?: string}>
     */
    private function segments(bool $withTranslation = true): array
    {
        $segments = [
            ['text' => 'Hello and welcome to the show,', 'start' => 0.0, 'end' => 3.0, 'fa' => 'سلام و به برنامه خوش اومدید.'],
            ['text' => 'I am Neil.', 'start' => 3.0, 'end' => 5.0],
            ['text' => 'And I am Georgie.', 'start' => 5.0, 'end' => 8.0, 'fa' => 'و من جورجی هستم.'],
        ];

        return $withTranslation ? $segments : array_map(fn (array $segment) => array_diff_key($segment, ['fa' => 1]), $segments);
    }

    public function test_it_knows_whether_a_text_has_persian_lines(): void
    {
        $this->assertTrue(WebVttParser::hasTranslation($this->segments()));
        $this->assertFalse(WebVttParser::hasTranslation($this->segments(false)));
    }

    public function test_a_sentence_takes_the_persian_line_from_its_first_chunk(): void
    {
        $lines = collect($this->segments())->map(fn ($segment, $index) => ['index' => $index, 'segment' => $segment])->all();
        $sentences = WebVttParser::sentences($lines, fn ($line) => $line['segment']['text']);

        $this->assertSame('سلام و به برنامه خوش اومدید.', WebVttParser::translation($sentences[0]));
        $this->assertSame('و من جورجی هستم.', WebVttParser::translation($sentences[1]));
    }

    public function test_a_shadow_line_gets_the_persian_of_the_sentences_it_touches(): void
    {
        $this->assertSame('سلام و به برنامه خوش اومدید.', WebVttParser::translationWithin($this->segments(), 3.5, 4.5));
        $this->assertSame('سلام و به برنامه خوش اومدید. و من جورجی هستم.', WebVttParser::translationWithin($this->segments(), 2.0, 7.0));
        $this->assertNull(WebVttParser::translationWithin($this->segments(false), 0.0, 8.0));
    }

    public function test_the_player_offers_the_translation_only_when_there_is_one(): void
    {
        $with = Blade::render('<x-audio-player podcast url="/a.mp3" :segments="$segments" />', ['segments' => $this->segments()]);
        $without = Blade::render('<x-audio-player podcast url="/a.mp3" :segments="$segments" />', ['segments' => $this->segments(false)]);

        $this->assertStringContainsString('ترجمه فارسی', $with);
        $this->assertStringContainsString('و من جورجی هستم.', $with);
        $this->assertStringNotContainsString('ترجمه فارسی', $without);
    }

    public function test_the_video_player_shows_the_persian_line_under_its_sentence(): void
    {
        $html = Blade::render('<x-video-player url="/v.mp4" :segments="$segments" />', ['segments' => $this->segments()]);

        $this->assertStringContainsString('ترجمه فارسی', $html);
        $this->assertStringContainsString('سلام و به برنامه خوش اومدید.', $html);
    }

    public function test_a_shadow_line_screen_shows_its_persian_under_the_line(): void
    {
        $html = Blade::render('<x-shadow-line-screen when="true" :index="0" line="I work in a café." kind="Say it" hear="x" file-name="a.webm" next="x" fa="من در یک کافه کار می‌کنم." />');

        $this->assertStringContainsString('من در یک کافه کار می‌کنم.', $html);
        $this->assertStringContainsString('ترجمه فارسی', $html);
    }
}
