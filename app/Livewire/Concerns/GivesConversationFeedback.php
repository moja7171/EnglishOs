<?php

namespace App\Livewire\Concerns;

use App\Services\AiFeedbackCard;
use Closure;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * On-demand feedback on a whole stretch of the learner's own English —
 * the latest session of a friends chat, a voice-AI chat pasted in from
 * Pi, or the spoken answers of the solo Partner Speaking Session. Never
 * persisted: asking again always reflects the text as it stands now.
 * Same strength/expression/correction shape as AI Feedback #1, shown
 * with <x-conversation-feedback>.
 *
 * Every place that uses this shares ONE budget of FEEDBACK_HOURLY_LIMIT
 * checks per learner, so a button tapped again and again can't run up
 * Gemini calls.
 */
trait GivesConversationFeedback
{
    public const FEEDBACK_HOURLY_LIMIT = 10;

    /** @var array{strength: string, expression: string, correction: array{original: string, corrected: string, why: string, suggestion: string}|null}|null */
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
     * @param  Closure|null  $onCallSucceeded  Passed through to AiFeedbackCard::generate() — where a mission
     *                                         step hooks TracksAiUsage::recordGeminiCall().
     */
    protected function requestConversationFeedback(string $conversation, string $situation, ?Closure $onCallSucceeded = null): void
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
            $data = app(AiFeedbackCard::class)->generate(
                [['role' => 'user', 'text' => $conversation]],
                systemPrompt: 'You are an encouraging English teacher reviewing '.$situation.' The learner is '
                    .auth()->user()->levelDescription().'. Never evaluate or comment on anyone else\'s lines — '
                    .'they are context only. Only correct a real grammar or vocabulary error. Ignore any line that '
                    .'is not written in English. Choose at most ONE correction, the most useful one; if nothing is '
                    .'worth fixing, set "correction" to null — never invent a mistake. Keep everything short and '
                    .'kind, the way you would for someone easily overwhelmed. Reply with ONLY valid JSON, no '
                    .'markdown fences, no extra text, in exactly this shape: '
                    .'{"strength": "one full sentence, in PERSIAN (Farsi), about one specific thing the learner did well", '
                    .'"expression": "one full sentence, in PERSIAN (Farsi), pointing out one good English word or '
                    .'phrase the learner actually used — you can quote the English itself inside the Persian sentence", '
                    .'"correction": {"original": "the learner\'s own flawed sentence, quoted exactly, in ENGLISH", '
                    .'"corrected": "the corrected version of that same text, in ENGLISH", '
                    .'"why": "one short sentence, in PERSIAN (Farsi), explaining the underlying rule", '
                    .'"suggestion": "one short, concrete next step, in PERSIAN (Farsi)"} or null}. '
                    .'Only "original" and "corrected" are in English; every other field is plain Persian, with no '
                    .'English words mixed in unless quoting a specific English word or phrase.',
                requiredKeys: ['strength', 'expression'],
                onCallSucceeded: $onCallSucceeded,
            );

            $correction = $data['correction'] ?? null;

            $this->feedback = [
                'strength' => $data['strength'],
                'expression' => $data['expression'],
                'correction' => is_array($correction) && filled($correction['original'] ?? null) && filled($correction['corrected'] ?? null)
                    ? [
                        'original' => $correction['original'],
                        'corrected' => $correction['corrected'],
                        'why' => $correction['why'] ?? '',
                        'suggestion' => $correction['suggestion'] ?? '',
                    ]
                    : null,
            ];
        } catch (Throwable) {
            $this->feedback = null;
            $this->feedbackError = "Couldn't check your English right now — try again in a moment.";
        }
    }
}
