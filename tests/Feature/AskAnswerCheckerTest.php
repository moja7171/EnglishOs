<?php

namespace Tests\Feature;

use App\Services\AskAnswerChecker;
use App\Services\GeminiClient;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class AskAnswerCheckerTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function level(): array
    {
        return [
            'fa' => 'بپرس آخر هفته کار می‌کند',
            'fa_extra' => 'بپرس شنبه',
            'en' => 'Are you working this weekend?',
            'en_extra' => 'On Saturday?',
            'alt' => ['Are you working at the weekend?'],
            'wrong' => [['text' => 'Do you working this weekend?', 'why' => 'با «-ing» فعل کمکی «Are» می‌آید.']],
        ];
    }

    private function checker(?callable $ai = null): AskAnswerChecker
    {
        $gemini = $this->mock(GeminiClient::class, function (MockInterface $mock) use ($ai) {
            if ($ai === null) {
                $mock->shouldNotReceive('chat');
            } else {
                $ai($mock);
            }
        });

        return new AskAnswerChecker($gemini);
    }

    public function test_the_target_sentence_is_right_without_asking_the_ai(): void
    {
        $result = $this->checker()->check($this->level(), 'are you working this weekend');

        $this->assertSame('ok', $result['status']);
        $this->assertSame('exact', $result['by']);
        $this->assertFalse($result['extra']);
    }

    public function test_an_accepted_alternative_is_right(): void
    {
        $this->assertSame('ok', $this->checker()->check($this->level(), 'Are you working at the weekend?')['status']);
    }

    public function test_contractions_and_digits_are_the_same_as_the_long_form(): void
    {
        $level = ['fa' => 'x', 'en' => "I'm meeting 3 friends at 5.", 'alt' => [], 'wrong' => []];

        $this->assertSame('ok', $this->checker()->check($level, 'I am meeting three friends at five')['status']);
    }

    public function test_a_leading_um_does_not_matter(): void
    {
        $this->assertSame('ok', $this->checker()->check($this->level(), 'Um, are you working this weekend?')['status']);
    }

    public function test_saying_the_extra_line_as_well_is_right_and_counted(): void
    {
        $result = $this->checker()->check($this->level(), 'Are you working this weekend? On Saturday?');

        $this->assertSame('ok', $result['status']);
        $this->assertTrue($result['extra']);
    }

    public function test_a_known_wrong_version_is_caught_with_its_reason_and_no_ai_call(): void
    {
        $result = $this->checker()->check($this->level(), 'Do you working this weekend?');

        $this->assertSame('wrong', $result['status']);
        $this->assertSame('known', $result['by']);
        $this->assertSame('Are you working this weekend?', $result['correct']);
        $this->assertStringContainsString('Are', $result['why']);
    }

    public function test_a_different_but_correct_sentence_is_accepted_by_the_ai(): void
    {
        $checker = $this->checker(fn (MockInterface $mock) => $mock->shouldReceive('chat')->once()->andReturn('{"ok": true, "correct": "x", "why": null, "extra": false}'));

        $result = $checker->check($this->level(), 'Do you work on Saturday and Sunday?');

        $this->assertSame('ok', $result['status']);
        $this->assertSame('ai', $result['by']);
    }

    public function test_the_ai_can_say_what_to_say_instead(): void
    {
        $checker = $this->checker(fn (MockInterface $mock) => $mock->shouldReceive('chat')->once()->andReturn('{"ok": false, "correct": "Are you working this weekend?", "why": "فعل کمکی اشتباه است.", "extra": false}'));

        $result = $checker->check($this->level(), 'You working weekend?');

        $this->assertSame('wrong', $result['status']);
        $this->assertSame('Are you working this weekend?', $result['correct']);
        $this->assertSame('فعل کمکی اشتباه است.', $result['why']);
    }

    public function test_a_dead_ai_leaves_the_sentence_unchecked_not_wrong(): void
    {
        $checker = $this->checker(fn (MockInterface $mock) => $mock->shouldReceive('chat')->once()->andThrow(new RuntimeException('down')));

        $result = $checker->check($this->level(), 'Is it Saturday you work?');

        $this->assertSame('unchecked', $result['status']);
    }

    public function test_nothing_heard_asks_to_try_again_without_the_ai(): void
    {
        $result = $this->checker()->check($this->level(), '  ');

        $this->assertSame('wrong', $result['status']);
        $this->assertSame('none', $result['by']);
    }

    public function test_extra_trailing_words_that_are_not_the_extra_line_go_to_the_ai(): void
    {
        $checker = $this->checker(fn (MockInterface $mock) => $mock->shouldReceive('chat')->once()->andReturn('{"ok": true, "correct": "x", "why": null, "extra": false}'));

        $result = $checker->check($this->level(), 'Are you working this weekend because I want to visit you');

        $this->assertSame('ok', $result['status']);
        $this->assertSame('ai', $result['by']);
    }
}
