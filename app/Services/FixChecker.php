<?php

namespace App\Services;

use Throwable;

/**
 * Checks a learner's own fix of an old mistake (a Daily Review mistake
 * card, the notebook's practice): the saved correction is only ONE right
 * answer, so a different attempt gets a quick AI "is this also correct?".
 * When the AI can't answer, only the saved correction counts — the same
 * exact match the check always had.
 */
class FixChecker
{
    public function __construct(private readonly GeminiClient $gemini) {}

    public function accepts(string $mistake, string $attempt, string $savedFix): bool
    {
        if (trim($attempt) === '') {
            return false;
        }

        if (self::normalize($attempt) === self::normalize($savedFix)) {
            return true;
        }

        try {
            $raw = $this->gemini->chat(
                [['role' => 'user', 'text' => "Wrong sentence: \"{$mistake}\"\nSaved correction: \"{$savedFix}\"\nLearner's fix: \"{$attempt}\""]],
                systemPrompt: 'An English learner made a mistake and was asked to write the sentence correctly. Is the '
                    .'learner\'s fix correct, natural English that fixes the mistake and keeps the meaning? It does not '
                    .'have to match the saved correction word for word. Ignore capital letters and end punctuation. '
                    .'Reply with ONLY valid JSON, no markdown fences: {"correct": true or false}.',
                profile: GeminiClient::PROFILE_JUDGE,
            );
        } catch (Throwable) {
            return false;
        }

        $data = json_decode(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($raw)), true);

        return is_array($data) && ($data['correct'] ?? null) === true;
    }

    /**
     * Lower-case, single-spaced, no end full stop/!/? — the leniency Error
     * Log's own drills use.
     */
    public static function normalize(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', strtolower(rtrim(trim($text), '.!?'))));
    }
}
