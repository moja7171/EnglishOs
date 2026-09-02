<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\GeminiClient;
use App\Services\SentenceChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
