<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
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
    private const RETRY_ONLY_IF_FAILED_WITHIN_SECONDS = 6;

    private const RETRY_PAUSE_MICROSECONDS = 1_500_000;

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
     * @return array{severity: string, hint: string}
     */
    public function check(
        string $judgment,
        string $majorCriteria,
        string $context,
        string $text,
        ?string $extraGuidance = null,
        ?string $feedbackDepth = null,
    ): array {
        $ask = fn (): string => $this->gemini->chat(
            [['role' => 'user', 'text' => "Context: {$context}\nLearner wrote: \"{$text}\""]],
            systemPrompt: $this->systemPrompt($judgment, $majorCriteria, $extraGuidance, $feedbackDepth),
            profile: GeminiClient::PROFILE_JUDGE,
        );

        return $this->parseVerdict($this->askWithOneRetry($ask));
    }

    /**
     * A blip (dropped connection, a 5xx) that failed fast is tried once
     * more after a moment, so the learner never sees it. A slow failure,
     * a rate limit or a quota is not: waiting would only repeat it.
     *
     * @param  callable(): string  $ask
     */
    private function askWithOneRetry(callable $ask): string
    {
        $startedAt = microtime(true);

        try {
            return $ask();
        } catch (ConnectionException|RequestException $e) {
            $isBlip = (new AiModelChain)->classify($e)['kind'] === AiModelChain::KIND_TRANSIENT;

            if (! $isBlip || microtime(true) - $startedAt > self::RETRY_ONLY_IF_FAILED_WITHIN_SECONDS) {
                throw $e;
            }

            usleep(self::RETRY_PAUSE_MICROSECONDS);

            return $ask();
        }
    }

    /**
     * @return array{severity: string, hint: string}
     */
    private function parseVerdict(string $raw): array
    {
        $data = AiJson::decode($raw);

        if (! is_array($data) || ! in_array($data['severity'] ?? null, ['major', 'minor', 'none'], true)) {
            throw new RuntimeException('Unexpected AI response format.');
        }

        return ['severity' => $data['severity'], 'hint' => (string) ($data['hint'] ?? '')];
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
            .'or "minor" or "none", "hint": "..."}. Use "major" only for real problems: '.$majorCriteria.', or '
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
            .' For a grammar, meaning, or wording problem, the hint must be a short nudge — a statement, never '
            .'a question — that points at what to fix so the learner fixes it themselves; never write the '
            .'corrected sentence for them. '
            .'EXCEPTION: if the issue is a spelling mistake, a missing/wrong capital letter, or missing end '
            .'punctuation, say directly what the fix is — that is a fact, not the answer to the exercise. '
            .'Phrase a spelling fix explicitly, e.g. \'wrok\' should be spelled \'work\'. Never use double quote '
            .'characters inside the hint — quote a word with single quotes only, so the JSON stays valid. Keep the hint to ONE '
            .'short, simple sentence, no more than 12 words, plain everyday words — no jargon. '
            .'When the severity is "none", the hint is still never empty: one short, warm comment on what '
            .'works well, no more than 8 words (e.g. \'Clear and natural.\').';
    }
}
