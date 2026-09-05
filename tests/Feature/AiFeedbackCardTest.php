<?php

namespace Tests\Feature;

use App\Services\AiFeedbackCard;
use App\Services\GeminiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class AiFeedbackCardTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_valid_flat_response_parses_correctly(): void
    {
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode([
                'strength' => 'Clear, simple sentences.',
                'expression' => 'have breakfast',
                'correction' => 'Try "wake up early" instead of "wake early".',
            ]));
        });

        $data = app(AiFeedbackCard::class)->generate(
            [['role' => 'user', 'text' => 'some text']],
            systemPrompt: 'some prompt',
            requiredKeys: ['strength', 'expression', 'correction'],
        );

        $this->assertSame('Clear, simple sentences.', $data['strength']);
        $this->assertSame('have breakfast', $data['expression']);
        $this->assertSame('Try "wake up early" instead of "wake early".', $data['correction']);
    }

    public function test_a_valid_nested_response_parses_correctly(): void
    {
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode([
                'strength' => 'قوت',
                'expression' => 'بیان',
                'correction' => [
                    'original' => 'I wake up at seven.',
                    'corrected' => 'I usually wake up at seven.',
                    'why' => 'دلیل',
                    'suggestion' => 'پیشنهاد',
                ],
                'severity' => 'minor',
            ]));
        });

        $data = app(AiFeedbackCard::class)->generate(
            [['role' => 'user', 'text' => 'some text']],
            systemPrompt: 'some prompt',
            requiredKeys: ['strength', 'expression', 'severity', 'correction.original', 'correction.corrected', 'correction.why', 'correction.suggestion'],
        );

        $this->assertSame('I wake up at seven.', $data['correction']['original']);
        $this->assertSame('I usually wake up at seven.', $data['correction']['corrected']);
        $this->assertSame('minor', $data['severity']);
    }

    public function test_non_json_output_throws(): void
    {
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('not valid json at all');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unexpected AI response format.');

        app(AiFeedbackCard::class)->generate(
            [['role' => 'user', 'text' => 'some text']],
            systemPrompt: 'some prompt',
            requiredKeys: ['strength', 'expression', 'correction'],
        );
    }

    public function test_a_missing_top_level_key_throws(): void
    {
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode([
                'strength' => 'Clear, simple sentences.',
                'expression' => 'have breakfast',
                // 'correction' missing entirely
            ]));
        });

        $this->expectException(RuntimeException::class);

        app(AiFeedbackCard::class)->generate(
            [['role' => 'user', 'text' => 'some text']],
            systemPrompt: 'some prompt',
            requiredKeys: ['strength', 'expression', 'correction'],
        );
    }

    public function test_a_null_top_level_value_counts_as_missing_just_like_isset(): void
    {
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode([
                'strength' => 'Clear, simple sentences.',
                'expression' => 'have breakfast',
                'correction' => null,
            ]));
        });

        $this->expectException(RuntimeException::class);

        app(AiFeedbackCard::class)->generate(
            [['role' => 'user', 'text' => 'some text']],
            systemPrompt: 'some prompt',
            requiredKeys: ['strength', 'expression', 'correction'],
        );
    }

    /**
     * The nested-key validation must actually check the nested structure —
     * a response whose top-level 'correction' key is present but not an
     * array (or missing the specific sub-field asked for) must still be
     * rejected, not pass just because 'correction' itself is set.
     */
    public function test_a_present_but_incomplete_nested_correction_throws(): void
    {
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode([
                'strength' => 'قوت',
                'expression' => 'بیان',
                'severity' => 'minor',
                'correction' => [
                    'original' => 'I wake up at seven.',
                    'corrected' => 'I usually wake up at seven.',
                    // 'why' and 'suggestion' missing
                ],
            ]));
        });

        $this->expectException(RuntimeException::class);

        app(AiFeedbackCard::class)->generate(
            [['role' => 'user', 'text' => 'some text']],
            systemPrompt: 'some prompt',
            requiredKeys: ['strength', 'expression', 'severity', 'correction.original', 'correction.corrected', 'correction.why', 'correction.suggestion'],
        );
    }

    /**
     * A flat-string 'correction' (the OLD AI Feedback #1 shape, before it
     * became a nested object) must not satisfy a nested-key requirement —
     * regression coverage for exactly the bug class this validation exists
     * to prevent.
     */
    public function test_a_flat_string_correction_does_not_satisfy_a_nested_key_requirement(): void
    {
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode([
                'strength' => 'قوت',
                'expression' => 'بیان',
                'severity' => 'minor',
                'correction' => 'a flat string, not an object',
            ]));
        });

        $this->expectException(RuntimeException::class);

        app(AiFeedbackCard::class)->generate(
            [['role' => 'user', 'text' => 'some text']],
            systemPrompt: 'some prompt',
            requiredKeys: ['strength', 'expression', 'severity', 'correction.original'],
        );
    }

    public function test_on_call_succeeded_fires_after_a_successful_call_even_when_the_response_is_malformed(): void
    {
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('not valid json at all');
        });

        $fired = false;

        try {
            app(AiFeedbackCard::class)->generate(
                [['role' => 'user', 'text' => 'some text']],
                systemPrompt: 'some prompt',
                requiredKeys: ['strength'],
                onCallSucceeded: function () use (&$fired) {
                    $fired = true;
                },
            );
        } catch (RuntimeException) {
            // expected — assertion is on $fired below
        }

        $this->assertTrue($fired);
    }

    public function test_on_call_succeeded_does_not_fire_when_the_underlying_call_itself_throws(): void
    {
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andThrow(new RuntimeException('AI unavailable'));
        });

        $fired = false;

        try {
            app(AiFeedbackCard::class)->generate(
                [['role' => 'user', 'text' => 'some text']],
                systemPrompt: 'some prompt',
                requiredKeys: ['strength'],
                onCallSucceeded: function () use (&$fired) {
                    $fired = true;
                },
            );
        } catch (RuntimeException) {
            // expected — assertion is on $fired below
        }

        $this->assertFalse($fired);
    }

    public function test_vocabulary_context_mentions_each_word_when_words_are_given(): void
    {
        $context = app(AiFeedbackCard::class)->vocabularyContext(['wake up', 'have a shower']);

        $this->assertStringContainsString('"wake up"', $context);
        $this->assertStringContainsString('"have a shower"', $context);
    }

    public function test_vocabulary_context_is_empty_when_no_words_are_given(): void
    {
        $this->assertSame('', app(AiFeedbackCard::class)->vocabularyContext([]));
    }
}
