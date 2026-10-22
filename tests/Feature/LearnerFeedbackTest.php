<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\GeminiClient;
use App\Services\LearnerFeedback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The shared feedback every free-production task gets: what the AI is
 * told, and how its reply (or older saved feedback) is cleaned up.
 */
class LearnerFeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_ai_is_told_the_technique_list_and_never_to_ask_back(): void
    {
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()
            ->withArgs(fn ($messages, $systemPrompt) => str_contains($systemPrompt, '"buying_time"')
                && str_contains($systemPrompt, 'never ask the learner a question')
                && str_contains($systemPrompt, 'never judge punctuation'))
            ->andReturn($this->learnerFeedbackReply()));

        $feedback = app(LearnerFeedback::class)->review(User::factory()->create(), 'I am work in a hospital.', 'a spoken answer.', 'pi', spoken: true);

        $this->assertSame('I work in a hospital.', $feedback['sentences'][0]['corrected']);
        $this->assertSame(['mistakes' => 1, 'tips' => 1], $feedback['saved']);
    }

    public function test_a_reply_without_the_required_parts_throws_so_the_caller_can_fail_softly(): void
    {
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn(json_encode(['summary' => 'خوب بود.'])));

        $this->expectException(\RuntimeException::class);

        app(LearnerFeedback::class)->review(User::factory()->create(), 'Hello.', 'a chat.', 'friends_chat');
    }

    public function test_unknown_techniques_and_empty_corrections_are_dropped_and_lists_are_capped(): void
    {
        $feedback = LearnerFeedback::normalize([
            'summary' => 'خوب.',
            'sentences' => [
                ['original' => 'I goed home.', 'status' => 'fix', 'corrected' => 'I went home.', 'why' => 'گذشته‌ی بی‌قاعده.', 'pattern' => 'irregular past'],
                ['original' => 'It was nice.', 'status' => 'fix', 'corrected' => 'It was nice.', 'why' => 'x', 'pattern' => 'x'],
                ['original' => '', 'status' => 'ok'],
            ],
            'positives' => ['a', 'b', 'c', 'd'],
            'negatives' => [],
            'techniques' => [
                ['key' => 'linking', 'tip' => 'وصل کن.', 'example' => ''],
                ['key' => 'make_up_your_own', 'tip' => 'x', 'example' => 'x'],
            ],
        ]);

        $this->assertSame(['fix', 'ok'], array_column($feedback['sentences'], 'status'));
        $this->assertCount(LearnerFeedback::MAX_POINTS, $feedback['positives']);
        $this->assertSame([['key' => 'linking', 'tip' => 'وصل کن.', 'example' => LearnerFeedback::TECHNIQUES['linking']['example']]], $feedback['techniques']);
        $this->assertSame('minor', $feedback['severity']);
    }

    public function test_feedback_saved_in_the_old_shape_still_reads(): void
    {
        $feedback = LearnerFeedback::normalize([
            'strength' => 'واضح جواب دادی.',
            'expression' => 'wake up',
            'correction' => ['original' => 'I wake at seven.', 'corrected' => 'I wake up at seven.', 'why' => 'فعل دوکلمه‌ای.', 'suggestion' => 'تمرین کن.'],
            'severity' => 'major',
        ]);

        $this->assertSame(['واضح جواب دادی.', 'wake up'], $feedback['positives']);
        $this->assertSame('I wake up at seven.', $feedback['sentences'][0]['corrected']);
        $this->assertSame(['تمرین کن.'], $feedback['negatives']);
        $this->assertSame('major', $feedback['severity']);
    }
}
