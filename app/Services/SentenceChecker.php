<?php

namespace App\Services;

use RuntimeException;

/**
 * Shared AI sentence-check rules used by every mission step with free-text
 * sentence input (Vocabulary Builder, Listening, and any future step — see
 * EOS-009 §8 "الگوی چک جمله"). Handles the boilerplate every such check
 * needs — spelling, capitalization, end punctuation, word form; with a
 * problem, the sentence written right plus one Persian line on why (a B1
 * learner could not act on an English-only nudge) — so each caller only
 * supplies what makes ITS check different: the actual judgment being made.
 */
class SentenceChecker
{
    public function __construct(private readonly GeminiClient $gemini) {}

    /**
     * @param  string  $judgment  This check's primary judgment, e.g. "Judge
     *                            whether the learner used the target word correctly, naturally, and
     *                            as a genuine personal sentence (not just repeating the dictionary
     *                            definition)."
     * @param  string  $majorCriteria  What counts as a real ("major")
     *                                 problem beyond "it is not real English", e.g. "the word is missing
     *                                 or used with the wrong meaning, the sentence just repeats the
     *                                 definition".
     * @param  string  $context  What this specific text is meant to be,
     *                           given to the AI alongside the learner's text.
     * @param  string  $text  The learner's actual sentence.
     * @param  string|null  $extraGuidance  Any additional rule specific to
     *                                      this check, appended after the shared rules (e.g. a instruction not
     *                                      to fact-check details the AI wasn't given).
     * @param  string|null  $feedbackDepth  Mission::feedbackDepth() — where the "none"/"minor"
     *                                      line falls at this point in the roadmap (S5). Never touches
     *                                      what counts as "major", tone, or hint length.
     * @return array{severity: string, hint: string, corrected: ?string, why_fa: ?string}
     */
    public function check(
        string $judgment,
        string $majorCriteria,
        string $context,
        string $text,
        ?string $extraGuidance = null,
        ?string $feedbackDepth = null,
    ): array {
        $raw = $this->gemini->chat(
            [['role' => 'user', 'text' => "Context: {$context}\nLearner wrote: \"{$text}\""]],
            systemPrompt: $this->systemPrompt($judgment, $majorCriteria, $extraGuidance, $feedbackDepth),
            profile: GeminiClient::PROFILE_JUDGE,
        );

        return $this->parseVerdict($raw);
    }

    /**
     * The fixed sentence and the Persian why only come with a real
     * problem: a "none" verdict carries neither.
     *
     * @return array{severity: string, hint: string, corrected: ?string, why_fa: ?string}
     */
    private function parseVerdict(string $raw): array
    {
        $data = AiJson::decode($raw);

        if (! is_array($data) || ! in_array($data['severity'] ?? null, ['major', 'minor', 'none'], true)) {
            throw new RuntimeException('Unexpected AI response format.');
        }

        $filled = fn (string $field) => $data['severity'] !== 'none' && filled($data[$field] ?? null) ? trim((string) $data[$field]) : null;

        return [
            'severity' => $data['severity'],
            'hint' => (string) ($data['hint'] ?? ''),
            'corrected' => $filled('corrected'),
            'why_fa' => $filled('why_fa'),
        ];
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
            systemPrompt: 'You are a supportive English writing assistant helping '.$this->learnerDescription().' who '
                .'has tried several times and is stuck. Rewrite their sentence into a correct, natural version — '
                .'fixing grammar, spelling, word choice, and tense — while keeping their original personal '
                .'meaning as much as possible. Reply with ONLY the corrected sentence, no quotation marks, no '
                .'explanation, nothing else.',
            profile: GeminiClient::PROFILE_JUDGE,
        ));
    }

    /**
     * auth()->user() is null in a few unit-style tests that call this
     * service directly with no authenticated learner — falls back to the
     * app's original B1 baseline in that case, same as
     * User::levelDescription()'s own default.
     */
    private function learnerDescription(): string
    {
        return auth()->user()?->levelDescription() ?? 'a B1 (intermediate) English learner';
    }

    private function systemPrompt(string $judgment, string $majorCriteria, ?string $extraGuidance, ?string $feedbackDepth = null): string
    {
        $prompt = 'You are a supportive English writing assistant helping '.$this->learnerDescription().'. '.$judgment.' A short, '
            .'minimal sentence is completely fine — do not ask for more detail. Also check: the spelling of '
            .'every word; that the sentence starts with a capital letter and ends with proper punctuation '
            .'(. ? or !); and that any word is used in the right grammatical form (e.g. not a noun used as a '
            .'verb). Reply with ONLY valid JSON, no markdown fences, shaped exactly like: {"severity": "major" '
            .'or "minor" or "none", "hint": "...", "corrected": "...", "why_fa": "..."}. Use "major" only for real problems: '.$majorCriteria.', or '
            .'it is not real English, or it makes no sense (words that do not say anything meaningful, even if each '
            .'word is spelled correctly). Use "minor" for small slips: spelling mistakes, a missing capital letter '
            .'or end punctuation, a wrong word form, or article/preposition/tense mistakes — things that do '
            .'not block understanding. Use "none" when it is good.';

        if ($extraGuidance) {
            $prompt .= ' '.$extraGuidance;
        }

        if ($feedbackDepth) {
            $prompt .= ' '.$feedbackDepth;
        }

        return $prompt
            .' For "major" or "minor": the hint is a short English nudge — a statement, never a question — '
            .'naming what to fix, ONE short simple sentence, no more than 12 words, plain everyday words, no '
            .'jargon; "corrected" is the learner\'s whole sentence written correctly and naturally, changing as '
            .'little as possible and keeping their own meaning; "why_fa" is ONE short sentence in PERSIAN '
            .'(Farsi), at most 15 words, saying why — plain Persian, any English word quoted in Latin letters '
            .'inside «», e.g. «for» — never spell an English word in Persian letters. Never use double quote '
            .'characters inside any value — quote with single quotes or «» only, so the JSON stays valid. '
            .'When the severity is "none", "corrected" and "why_fa" are empty strings and the hint is still '
            .'never empty: one short, warm comment on what works well, no more than 8 words (e.g. '
            .'\'Clear and natural.\').';
    }
}
