<?php

namespace App\Livewire\Concerns;

use App\Services\LearnerFeedback;
use Closure;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * On-demand feedback on a whole stretch of the learner's own English —
 * the latest session of a friends chat, a voice-AI chat pasted in from
 * Pi, or the spoken answers of the solo Partner Speaking Session. The
 * card itself is never persisted — asking again always reflects the text
 * as it stands now — but its mistakes and tips go into the learner's
 * notebook (see LearnerFeedback). Shown with <x-conversation-feedback>.
 *
 * Every place that uses this shares ONE budget of FEEDBACK_HOURLY_LIMIT
 * checks per learner, so a button tapped again and again can't run up
 * Gemini calls.
 */
trait GivesConversationFeedback
{
    public const FEEDBACK_HOURLY_LIMIT = 10;

    /** @var array<string, mixed>|null A LearnerFeedback::review() result. */
    public ?array $feedback = null;

    public ?string $feedbackError = null;

    public function dismissFeedback(): void
    {
        $this->feedback = null;
        $this->feedbackError = null;
    }

    /**
     * @param  string  $conversation  The text to review, exactly as the AI should read it.
     * @param  string  $situation  One or two English sentences for the system prompt: what the text is and
     *                             which lines are the learner's own (the only ones ever judged).
     * @param  string  $source  Where it came from, for the notebook (see FeedbackNotebook::SOURCES).
     * @param  bool  $spoken  True when the learner's lines were spoken and transcribed.
     * @param  Closure|null  $onCallSucceeded  Passed through to AiFeedbackCard::generate() — where a mission
     *                                         step hooks TracksAiUsage::recordGeminiCall().
     */
    protected function requestConversationFeedback(string $conversation, string $situation, string $source, bool $spoken = false, ?Closure $onCallSucceeded = null): void
    {
        $this->feedbackError = null;

        $limiterKey = 'conversation-feedback:'.auth()->id();

        if (RateLimiter::tooManyAttempts($limiterKey, self::FEEDBACK_HOURLY_LIMIT)) {
            $this->feedback = null;
            $this->feedbackError = 'That\'s '.self::FEEDBACK_HOURLY_LIMIT.' checks this hour — try again later.';

            return;
        }

        RateLimiter::hit($limiterKey, 3600);

        try {
            $this->feedback = app(LearnerFeedback::class)->review(
                auth()->user(),
                $conversation,
                situation: $situation,
                source: $source,
                spoken: $spoken,
                onCallSucceeded: $onCallSucceeded,
            );
        } catch (Throwable) {
            $this->feedback = null;
            $this->feedbackError = "Couldn't check your English right now — try again in a moment.";
        }
    }
}
