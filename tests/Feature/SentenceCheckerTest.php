<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\GeminiClient;
use App\Services\SentenceChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SentenceCheckerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_the_parsed_severity_and_hint(): void
    {
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode([
                'severity' => 'minor',
                'hint' => 'Try "I commute to work."',
            ]));
        });

        $result = app(SentenceChecker::class)->check(
            judgment: 'Judge the sentence.',
            majorCriteria: 'it makes no sense',
            context: 'a test context',
            text: 'I commute work.',
        );

        $this->assertSame('minor', $result['severity']);
        $this->assertSame('Try "I commute to work."', $result['hint']);
    }

    public function test_grading_a_sentence_walks_the_judge_chain_not_the_chat_chain(): void
    {
        config([
            'services.gemini.key' => 'test-key',
            'services.gemini.chat_models' => ['chat-model'],
            'services.gemini.judge_models' => ['judge-model'],
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'generativelanguage.googleapis.com/v1beta/models/judge-model:generateContent' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => '{"severity": "none", "hint": ""}']]]]],
            ]),
        ]);

        $result = app(SentenceChecker::class)->check(
            judgment: 'Judge the sentence.',
            majorCriteria: 'it makes no sense',
            context: 'a test context',
            text: 'I commute to work.',
        );

        $this->assertSame('none', $result['severity']);
        Http::assertSentCount(1);
    }

    public function test_it_sends_the_learners_text_and_context_to_gemini(): void
    {
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(function (array $messages, ?string $systemPrompt) {
                    return str_contains($messages[0]['text'], 'Context: a test context')
                        && str_contains($messages[0]['text'], 'Learner wrote: "I commute work."')
                        && str_contains($systemPrompt, 'Judge the sentence.')
                        && str_contains($systemPrompt, 'it makes no sense')
                        && str_contains($systemPrompt, 'some extra rule');
                })
                ->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        app(SentenceChecker::class)->check(
            judgment: 'Judge the sentence.',
            majorCriteria: 'it makes no sense',
            context: 'a test context',
            text: 'I commute work.',
            extraGuidance: 'some extra rule',
        );
    }

    public function test_it_tells_the_ai_a_sentence_that_makes_no_sense_is_major(): void
    {
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(fn (array $messages, ?string $systemPrompt) => str_contains($systemPrompt, 'or it makes no sense (words that do not say anything meaningful'))
                ->andReturn(json_encode(['severity' => 'major', 'hint' => 'Write a real sentence.']));
        });

        $result = app(SentenceChecker::class)->check(
            judgment: 'Judge the sentence.',
            majorCriteria: 'the word is missing',
            context: 'a test context',
            text: 'The morning person eats the sky.',
        );

        $this->assertSame('major', $result['severity']);
    }

    public function test_it_asks_for_a_short_comment_even_when_the_sentence_is_good(): void
    {
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(fn (array $messages, ?string $systemPrompt) => str_contains($systemPrompt, 'When the severity is "none", the hint is still never empty'))
                ->andReturn(json_encode(['severity' => 'none', 'hint' => 'Clear and natural.']));
        });

        $result = app(SentenceChecker::class)->check(
            judgment: 'Judge the sentence.',
            majorCriteria: 'it makes no sense',
            context: 'a test context',
            text: 'I get up at six every day.',
        );

        $this->assertSame('Clear and natural.', $result['hint']);
    }

    public function test_it_still_reads_a_verdict_whose_hint_is_wrapped_in_single_quotes_around_a_quoted_word(): void
    {
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()
            ->andReturn('{"severity": "minor", "hint": \'"day" should be plural.\'}'));

        $result = app(SentenceChecker::class)->check('Judge.', 'major thing', 'a context', 'I stay friends through hard day.');

        $this->assertSame('minor', $result['severity']);
        $this->assertSame('"day" should be plural.', $result['hint']);
    }

    public function test_it_still_reads_a_verdict_with_an_unescaped_quote_or_code_fences(): void
    {
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->twice()
            ->andReturn(
                '{"severity": "minor", "hint": "Write "work" here."}',
                "```json\n{\"severity\": \"none\", \"hint\": \"Clear and natural.\"}\n```",
            ));

        $first = app(SentenceChecker::class)->check('Judge.', 'major thing', 'a context', 'I wrok.');
        $second = app(SentenceChecker::class)->check('Judge.', 'major thing', 'a context', 'I work.');

        $this->assertSame('minor', $first['severity']);
        $this->assertSame('Write "work" here.', $first['hint']);
        $this->assertSame('none', $second['severity']);
        $this->assertSame('Clear and natural.', $second['hint']);
    }

    public function test_it_throws_on_malformed_ai_output(): void
    {
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('not valid json at all');
        });

        $this->expectException(\RuntimeException::class);

        app(SentenceChecker::class)->check(
            judgment: 'Judge the sentence.',
            majorCriteria: 'it makes no sense',
            context: 'a test context',
            text: 'I commute work.',
        );
    }

    public function test_the_shared_mechanical_rules_are_always_present(): void
    {
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(function (array $messages, ?string $systemPrompt) {
                    return str_contains($systemPrompt, 'spelling')
                        && str_contains($systemPrompt, 'capital letter')
                        && str_contains($systemPrompt, 'end punctuation')
                        && str_contains($systemPrompt, 'never write the corrected sentence for them')
                        && str_contains($systemPrompt, 'no more than 12 words');
                })
                ->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        app(SentenceChecker::class)->check(
            judgment: 'Judge the sentence.',
            majorCriteria: 'it makes no sense',
            context: 'a test context',
            text: 'I commute work.',
        );
    }

    public function test_it_defaults_to_b1_when_no_learner_is_authenticated(): void
    {
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(fn (array $messages, ?string $systemPrompt) => str_contains($systemPrompt, 'a B1 (intermediate) English learner'))
                ->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        app(SentenceChecker::class)->check(
            judgment: 'Judge the sentence.',
            majorCriteria: 'it makes no sense',
            context: 'a test context',
            text: 'I commute to work.',
        );
    }

    public function test_feedback_depth_is_appended_to_the_prompt_when_given(): void
    {
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(fn (array $messages, ?string $systemPrompt) => str_contains($systemPrompt, 'be generous about what counts as'))
                ->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        app(SentenceChecker::class)->check(
            judgment: 'Judge the sentence.',
            majorCriteria: 'it makes no sense',
            context: 'a test context',
            text: 'I commute to work.',
            feedbackDepth: 'be generous about what counts as "none"…',
        );
    }

    public function test_no_feedback_depth_leaves_the_prompt_unchanged(): void
    {
        // The middle third of the roadmap (Mission::FEEDBACK_STANDARD)
        // passes an empty string — must not leave a stray trailing space
        // or blank instruction in the prompt.
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(fn (array $messages, ?string $systemPrompt) => ! str_contains($systemPrompt, 'be generous')
                    && ! str_contains($systemPrompt, 'hold the'))
                ->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        app(SentenceChecker::class)->check(
            judgment: 'Judge the sentence.',
            majorCriteria: 'it makes no sense',
            context: 'a test context',
            text: 'I commute to work.',
            feedbackDepth: '',
        );
    }

    public function test_it_calibrates_to_the_authenticated_learners_own_level(): void
    {
        $this->actingAs(User::factory()->create(['cefr_level' => 'A2']));

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(fn (array $messages, ?string $systemPrompt) => str_contains($systemPrompt, 'an elementary (A2) English learner'))
                ->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        app(SentenceChecker::class)->check(
            judgment: 'Judge the sentence.',
            majorCriteria: 'it makes no sense',
            context: 'a test context',
            text: 'I commute to work.',
        );
    }
}
