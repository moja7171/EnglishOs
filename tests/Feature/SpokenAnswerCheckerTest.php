<?php

namespace Tests\Feature;

use App\Services\GeminiClient;
use App\Services\SpokenAnswerChecker;
use Tests\TestCase;

class SpokenAnswerCheckerTest extends TestCase
{
    public function test_a_shadowing_verdict_is_read_even_when_the_hint_breaks_the_json(): void
    {
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()
            ->andReturn('{"severity": "major", "hint": \'Please say the line "I get up" again.\'}'));

        $result = app(SpokenAnswerChecker::class)->checkShadowing('I get up at six.', 'banana', 'a B1 learner');

        $this->assertSame('major', $result['severity']);
        $this->assertSame('Please say the line "I get up" again.', $result['hint']);
    }

    public function test_it_asks_for_a_short_comment_on_a_good_shadowing_take(): void
    {
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()
            ->withArgs(fn (array $messages, ?string $systemPrompt) => str_contains($systemPrompt, 'If "none", the hint is still never empty'))
            ->andReturn(json_encode(['severity' => 'none', 'hint' => 'Good rhythm.'])));

        $result = app(SpokenAnswerChecker::class)->checkShadowing('I get up at six.', 'I get up at six', 'a B1 learner');

        $this->assertSame('Good rhythm.', $result['hint']);
    }

    public function test_a_quick_correction_with_a_quoted_word_in_the_persian_line_is_still_read(): void
    {
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()
            ->andReturn('{"status": "fix", "original": "I wakes up", "corrected": "I wake up", "why": "بعد از I فعل ساده می‌آید، "wakes" نه."}'));

        $result = app(SpokenAnswerChecker::class)->quickCorrection('Your morning?', 'I wakes up at six', 'a B1 learner');

        $this->assertSame('fix', $result['status']);
        $this->assertSame('I wake up', $result['corrected']);
    }

    public function test_text_with_no_verdict_in_it_still_fails_loudly(): void
    {
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn('Sorry, I cannot help.'));

        $this->expectException(\RuntimeException::class);

        app(SpokenAnswerChecker::class)->checkShadowing('I get up at six.', 'I get up', 'a B1 learner');
    }
}
