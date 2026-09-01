<?php

namespace App\Services;

use RuntimeException;

/**
 * Shared AI sentence-check rules used by every mission step with free-text
 * sentence input (Vocabulary Builder, Listening, and any future step — see
 * EOS-009 §8 "الگوی چک جمله"). Handles the boilerplate every such check
 * needs — spelling, capitalization, end punctuation, word form; guiding
 * hints only, except a narrow exception for purely mechanical fixes — so
 * each caller only supplies what makes ITS check different: the actual
 * judgment being made.
 */
class SentenceChecker
{
    public function __construct(private readonly GeminiClient $gemini) {}

    /**
     * @param  string  $judgment  This check's primary judgment, e.g. "Judge
     *   whether the learner used the target word correctly, naturally, and
     *   as a genuine personal sentence (not just repeating the dictionary
     *   definition)."
     * @param  string  $majorCriteria  What counts as a real ("major")
     *   problem beyond "it is not real English", e.g. "the word is missing
     *   or used with the wrong meaning, the sentence just repeats the
     *   definition".
     * @param  string  $context  What this specific text is meant to be,
     *   given to the AI alongside the learner's text.
     * @param  string  $text  The learner's actual sentence.
     * @param  string|null  $extraGuidance  Any additional rule specific to
     *   this check, appended after the shared rules (e.g. a instruction not
     *   to fact-check details the AI wasn't given).
     * @return array{severity: string, hint: string}
     */
    public function check(
        string $judgment,
        string $majorCriteria,
        string $context,
        string $text,
        ?string $extraGuidance = null,
    ): array {
        $raw = $this->gemini->chat(
            [['role' => 'user', 'text' => "Context: {$context}\nLearner wrote: \"{$text}\""]],
            systemPrompt: $this->systemPrompt($judgment, $majorCriteria, $extraGuidance)
        );

        $data = json_decode(trim($raw), true);

        if (! is_array($data) || ! isset($data['severity'])) {
            throw new RuntimeException('Unexpected AI response format.');
        }

        return $data;
    }

    /**
     * Directly rewrites the learner's sentence into a correct, natural
     * version — the one deliberate exception to "never write it for them"
     * (see check()'s system prompt above). Only meant to be offered after
     * the learner has genuinely tried a field several times and is stuck;
     * see EOS-009 §8 for the standard 3-attempts-then-offer pattern.
     *
     * @param  string  $context  What this text is meant to be, same as check().
     * @param  string  $text  The learner's actual (still imperfect) sentence.
     */
    public function correct(string $context, string $text): string
    {
        return trim($this->gemini->chat(
            [['role' => 'user', 'text' => "Context: {$context}\nLearner wrote: \"{$text}\""]],
            systemPrompt: 'You are a supportive English writing assistant helping a B1 learner who has tried '
                .'several times and is stuck. Rewrite their sentence into a correct, natural version — fixing '
                .'grammar, spelling, word choice, and tense — while keeping their original personal meaning as '
                .'much as possible. Reply with ONLY the corrected sentence, no quotation marks, no explanation, '
                .'nothing else.'
        ));
    }

    private function systemPrompt(string $judgment, string $majorCriteria, ?string $extraGuidance): string
    {
        $prompt = 'You are a supportive English writing assistant helping a B1 learner. '.$judgment.' A short, '
            .'minimal sentence is completely fine — do not ask for more detail. Also check: the spelling of '
            .'every word; that the sentence starts with a capital letter and ends with proper punctuation '
            .'(. ? or !); and that any word is used in the right grammatical form (e.g. not a noun used as a '
            .'verb). Reply with ONLY valid JSON, no markdown fences, shaped exactly like: {"severity": "major" '
            .'or "minor" or "none", "hint": "..."}. Use "major" only for real problems: '.$majorCriteria.', or '
            .'it is not real English. Use "minor" for small slips: spelling mistakes, a missing capital letter '
            .'or end punctuation, a wrong word form, or article/preposition/tense mistakes — things that do '
            .'not block understanding. Use "none" when it is good.';

        if ($extraGuidance) {
            $prompt .= ' '.$extraGuidance;
        }

        return $prompt
            .' For a grammar, meaning, or wording problem, the hint must be a short guiding question or nudge '
            .'that helps the learner fix it themselves — never write the corrected sentence for them. '
            .'EXCEPTION: if the issue is a spelling mistake, a missing/wrong capital letter, or missing end '
            .'punctuation, say directly what the fix is — that is a fact, not the answer to the exercise. '
            .'Phrase a spelling fix explicitly, e.g. \'"wrok" should be spelled "work".\' Keep the hint to ONE '
            .'short, simple sentence, no more than 12 words, plain everyday words — no jargon.';
    }
}
