<?php

namespace App\Livewire\Concerns;

use App\Models\AiUsageLog;
use App\Services\AiLimitReached;
use App\Services\AiUsage;
use App\Services\GeminiClient;
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
 * Gemini calls on the app's key. A learner with their own Gemini key is
 * not capped while that key answers: only a check the app's key answered
 * counts, and once the budget is used up the app's key is held back
 * (AiUsage::ownKeyOnly()) — Google's own quota on their key is the limit.
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
     * @param  string  $extraGuidance  Task-specific judging notes (see LearnerFeedback::review()).
     * @param  Closure|null  $onCallSucceeded  Passed through to AiFeedbackCard::generate() — where a mission
     *                                         step hooks TracksAiUsage::recordGeminiCall().
     * @param  list<array{question: ?string, text: string}>  $answers  Separate answers, in place of $conversation
     *                                                                 (see LearnerFeedback::review()).
     * @param  string  $practiceTask  Asks the AI whether this was a real practice of the task too
     *                                (see LearnerFeedback::review()).
     */
    protected function requestConversationFeedback(string $conversation, string $situation, string $source, bool $spoken = false, ?Closure $onCallSucceeded = null, array $answers = [], string $extraGuidance = '', string $practiceTask = ''): void
    {
        $this->feedbackError = null;

        $learner = auth()->user();
        $usage = app(AiUsage::class);
        $limiterKey = 'conversation-feedback:'.$learner->id;
        $hasOwnKey = filled($learner->gemini_api_key);
        $appKeyUsedUp = RateLimiter::tooManyAttempts($limiterKey, self::FEEDBACK_HOURLY_LIMIT);

        if ($appKeyUsedUp && ! $hasOwnKey) {
            $usage->recordLimited(AiUsageLog::PROVIDER_GEMINI, GeminiClient::PROFILE_JUDGE);
            $this->feedback = null;
            $this->feedbackError = 'That\'s '.self::FEEDBACK_HOURLY_LIMIT.' checks this hour — try again later.';

            return;
        }

        if (! $hasOwnKey) {
            RateLimiter::hit($limiterKey, 3600);
        }

        $appKeyRequestsBefore = $hasOwnKey ? $usage->appKeyRequestsToday($learner) : 0;

        $review = fn (): array => app(LearnerFeedback::class)->review(
            $learner,
            $conversation,
            situation: $situation,
            source: $source,
            spoken: $spoken,
            extraGuidance: $extraGuidance,
            onCallSucceeded: $onCallSucceeded,
            answers: $answers,
            practiceTask: $practiceTask,
        );

        try {
            $this->feedback = $appKeyUsedUp ? $usage->ownKeyOnly($review) : $review();
        } catch (AiLimitReached) {
            $this->feedback = null;
            $this->feedbackError = $appKeyUsedUp
                ? "Your AI key didn't answer — try again later."
                : "You've used today's AI checks — try again tomorrow.";
        } catch (Throwable) {
            $this->feedback = null;
            $this->feedbackError = "Couldn't check your English right now — try again in a moment.";
        }

        if ($hasOwnKey && $usage->appKeyRequestsToday($learner) > $appKeyRequestsBefore) {
            RateLimiter::hit($limiterKey, 3600);
        }
    }
}
