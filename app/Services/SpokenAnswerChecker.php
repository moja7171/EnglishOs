<?php

namespace App\Services;

use RuntimeException;

/**
 * Judges whether a TRANSCRIBED spoken answer is a genuine, relevant
 * attempt at a question/prompt — deliberately separate from
 * SentenceChecker, whose systemPrompt unconditionally also grades
 * spelling/capitalization/end punctuation (writing conventions that don't
 * apply to a raw Whisper transcript of speech, and aren't the point of a
 * conversation exercise anyway). Used by AI Conversation #1/#2 so an
 * off-topic or empty spoken answer gets caught, encouragingly, the same
 * way an off-topic typed answer already does elsewhere in the app — see
 * EOS-009 §8's "الگوی چک جمله".
 */
class SpokenAnswerChecker
{
    public function __construct(private readonly GeminiClient $gemini) {}

    /**
     * @return array{severity: string, hint: string}
     */
    public function checkRelevance(string $prompt, string $answer, string $learnerDescription, string $extraGuidance = ''): array
    {
        $raw = $this->gemini->chat(
            [['role' => 'user', 'text' => "Prompt: \"{$prompt}\"\nLearner's spoken answer: \"{$answer}\""]],
            systemPrompt: 'You are a warm, encouraging English conversation partner helping '.$learnerDescription.'. '
                .'Judge ONLY whether the learner gave a genuine, relevant spoken answer to the prompt — real '
                .'content that actually addresses what was asked. Do NOT judge grammar, spelling, punctuation, '
                .'or phrasing at all — this is a transcript of spoken conversation, not writing, and imperfect '
                .'grammar is completely fine and expected. Reply with ONLY valid JSON, no markdown fences: '
                .'{"severity": "major" or "none", "hint": "..."}. Use "major" ONLY when the answer is empty, '
                .'silent/inaudible, complete gibberish, or clearly about a totally different topic than what was '
                .'asked — be lenient, a short or imperfect but genuine attempt is always "none". If "major", the '
                .'hint must be one short, warm, encouraging sentence (never harsh or scolding) nudging them '
                .'toward the topic — never write their exact answer for them.'
                .$extraGuidance
        );

        $data = json_decode(trim($raw), true);

        if (! is_array($data) || ! isset($data['severity'], $data['hint'])) {
            throw new RuntimeException('Unexpected AI response format.');
        }

        return $data;
    }

    /**
     * Offered only after 3 genuinely failed attempts on the same
     * prompt (see TracksCheckAttempts) — a supportive nudge, not an
     * answer key: the learner still has to record and speak their own
     * response, this just gives them somewhere to start.
     */
    public function suggestExample(string $prompt, string $learnerDescription): string
    {
        return trim($this->gemini->chat(
            [['role' => 'user', 'text' => "Prompt: \"{$prompt}\""]],
            systemPrompt: 'You are a supportive English conversation partner helping '.$learnerDescription
                .' who has tried a few times and is stuck. Write ONE short, natural example answer to this '
                .'prompt — a genuine, simple, personal-sounding response, not a model essay — so they have '
                .'something to work from. They will still need to record and speak their own answer; this is '
                .'just a starting idea. Reply with ONLY the example sentence, no quotation marks, no explanation.'
        ));
    }
}
