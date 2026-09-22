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
     * Judges a shadowing attempt — did the learner actually say something
     * close to the target line, out loud? Deliberately lenient (mission
     * structure redesign, Epic C): this is pronunciation/rhythm practice,
     * not a dictation test, so a transcript that's close-but-not-exact
     * (Whisper mishears things too) must never read as a "failure". Only
     * a genuinely different or empty attempt counts as "major".
     *
     * @return array{severity: string, hint: string}
     */
    public function checkShadowing(string $targetLine, string $transcript, string $learnerDescription): array
    {
        $raw = $this->gemini->chat(
            [['role' => 'user', 'text' => "Target line to shadow: \"{$targetLine}\"\nWhisper's transcript of the learner's recording: \"{$transcript}\""]],
            systemPrompt: 'You are a warm, encouraging pronunciation coach helping '.$learnerDescription.'. The '
                .'learner just tried to repeat ("shadow") the target line out loud, and this is an automatic '
                .'speech-to-text transcript of their attempt. Judge ONLY whether they genuinely attempted to say '
                .'roughly the same words, in roughly the same order — do NOT judge grammar, pronunciation '
                .'accuracy, or exact wording, and remember automatic transcription itself is imperfect and often '
                .'mishears words even when pronunciation was fine. Reply with ONLY valid JSON, no markdown '
                .'fences: {"severity": "major" or "none", "hint": "..."}. Use "major" ONLY when the transcript is '
                .'empty, silent/inaudible, or clearly a different sentence entirely (not just a few mismatched '
                .'words) — be very lenient, a close or partial attempt is always "none". If "major", the hint '
                .'must be one short, warm, encouraging sentence (never harsh) asking them to try that line again '
                .'— never write the line for them.'
        );

        $data = json_decode(trim($raw), true);

        if (! is_array($data) || ! isset($data['severity'], $data['hint'])) {
            throw new RuntimeException('Unexpected AI response format.');
        }

        return $data;
    }

    /**
     * Judges the role-reversal round (mission structure redesign, Epic
     * E) — here the learner is the one asking a question, not answering
     * one, so this checks the transcript is a genuine, on-topic
     * QUESTION rather than a relevant answer. Same lenient philosophy
     * and JSON contract as checkRelevance(): a real, if imperfect,
     * attempt is always "none".
     *
     * @return array{severity: string, hint: string}
     */
    public function checkGenuineQuestion(string $topic, string $transcript, string $learnerDescription): array
    {
        $raw = $this->gemini->chat(
            [['role' => 'user', 'text' => "Topic: \"{$topic}\"\nLearner's transcribed spoken question: \"{$transcript}\""]],
            systemPrompt: 'You are a warm, encouraging English conversation partner helping '.$learnerDescription.'. '
                .'The learner is now asking YOU a question about the topic, instead of answering one. Judge ONLY '
                .'whether the transcript is a genuine, on-topic question (a real attempt to ask you something '
                .'related to the topic) — grammar, phrasing, and whether it\'s a perfectly formed question '
                .'sentence do NOT matter at all. Reply with ONLY valid JSON, no markdown fences: {"severity": '
                .'"major" or "none", "hint": "..."}. Use "major" ONLY when it\'s empty, silent/inaudible, complete '
                .'gibberish, or not actually a question at all (e.g. a statement) — be lenient, a short or '
                .'imperfect but genuine question attempt is always "none". If "major", the hint must be one '
                .'short, warm, encouraging sentence nudging them to ask something about the topic — never write '
                .'a question for them.'
        );

        $data = json_decode(trim($raw), true);

        if (! is_array($data) || ! isset($data['severity'], $data['hint'])) {
            throw new RuntimeException('Unexpected AI response format.');
        }

        return $data;
    }

    /**
     * Offered only after 3 genuinely failed attempts asking a question in
     * the role-reversal round (see TracksCheckAttempts) — a starting
     * idea, not something to just repeat verbatim.
     */
    public function suggestQuestion(string $topic, string $learnerDescription): string
    {
        return trim($this->gemini->chat(
            [['role' => 'user', 'text' => "Topic: \"{$topic}\""]],
            systemPrompt: 'You are a supportive English conversation partner helping '.$learnerDescription
                .' who has tried a few times and is stuck. Write ONE short, natural example QUESTION they '
                .'could ask you about this topic — a genuine, simple, curious-sounding question, not a model '
                .'essay — so they have something to work from. They will still need to record and speak their '
                .'own question; this is just a starting idea. Reply with ONLY the example question, no '
                .'quotation marks, no explanation.'
        ));
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
