<?php

namespace App\Services;

use Throwable;

/**
 * Checks one spoken sentence of the Ask & Answer game against the level the
 * learner is on. The cheap checks come first and cost no AI call: the
 * sentence is the target (or one of its accepted alternatives), or it is
 * one of the known wrong versions written for this level — which already
 * carries the Persian reason. Only a sentence that is neither goes to the
 * AI, which says if it is also correct English that does what the Persian
 * line asked, and if not, what to say instead.
 *
 * A "level" is the array stored on AskAnswerItem: fa, fa_extra, en,
 * en_extra, alt and wrong.
 */
class AskAnswerChecker
{
    /** Spoken contractions are compared in their long form, so "I'm" and "I am" are the same sentence. */
    private const CONTRACTIONS = [
        "i'm" => 'i am', "you're" => 'you are', "we're" => 'we are', "they're" => 'they are',
        "he's" => 'he is', "she's" => 'she is', "it's" => 'it is', "that's" => 'that is', "there's" => 'there is',
        "what's" => 'what is', "where's" => 'where is', "who's" => 'who is', "how's" => 'how is',
        "i've" => 'i have', "you've" => 'you have', "we've" => 'we have', "they've" => 'they have',
        "i'll" => 'i will', "you'll" => 'you will', "we'll" => 'we will', "they'll" => 'they will', "he'll" => 'he will', "she'll" => 'she will',
        "i'd" => 'i would', "you'd" => 'you would', "we'd" => 'we would', "they'd" => 'they would',
        "don't" => 'do not', "doesn't" => 'does not', "didn't" => 'did not', "isn't" => 'is not', "aren't" => 'are not',
        "wasn't" => 'was not', "weren't" => 'were not', "haven't" => 'have not', "hasn't" => 'has not',
        "won't" => 'will not', "can't" => 'can not', 'cannot' => 'can not', "couldn't" => 'could not',
        "wouldn't" => 'would not', "shouldn't" => 'should not', "let's" => 'let us',
    ];

    /** Whisper often writes small numbers as digits; the target says them in words. */
    private const NUMBERS = [
        '0' => 'zero', '1' => 'one', '2' => 'two', '3' => 'three', '4' => 'four', '5' => 'five', '6' => 'six',
        '7' => 'seven', '8' => 'eight', '9' => 'nine', '10' => 'ten', '11' => 'eleven', '12' => 'twelve',
    ];

    /** Words a speaker says before the real sentence; they never make it wrong. */
    private const FILLERS = ['um', 'uh', 'er', 'hmm', 'well', 'okay', 'ok', 'so'];

    /** A sentence this much like the extra line counts as having said it. */
    private const EXTRA_SHARE = 0.6;

    /** What is left after the target may be this long before the AI has to read it. */
    private const MAX_TRAILING_WORDS = 14;

    public function __construct(private readonly GeminiClient $gemini) {}

    /**
     * @param  array{fa?: string, fa_extra?: ?string, en: string, en_extra?: ?string, alt?: list<string>, wrong?: list<array{text: string, why: string}>}  $level
     * @return array{status: 'ok'|'wrong'|'unchecked', extra: bool, correct: string, why: ?string, by: 'exact'|'known'|'ai'|'none'}
     */
    public function check(array $level, string $heard, string $learnerDescription = ''): array
    {
        $said = self::normalize($heard);
        $target = $level['en'];
        $extraWords = self::words(self::normalize((string) ($level['en_extra'] ?? '')));

        if ($said === '') {
            return $this->result('wrong', false, $target, 'چیزی شنیده نشد؛ دوباره بگو.', 'none');
        }

        foreach ([$target, ...($level['alt'] ?? [])] as $accepted) {
            $form = self::normalize($accepted);

            if ($said === $form) {
                return $this->result('ok', false, $target, null, 'exact');
            }

            if (str_starts_with($said, $form.' ')) {
                $rest = trim(substr($said, strlen($form)));

                // The target and then something more: the extra line, if it is that.
                if ($extraWords !== [] && $this->saidMost($extraWords, $rest)) {
                    return $this->result('ok', true, $target, null, 'exact');
                }

                if (count(self::words($rest)) > self::MAX_TRAILING_WORDS) {
                    continue;
                }

                // Unknown trailing words: the AI reads the whole sentence below.
                break;
            }
        }

        foreach ($level['wrong'] ?? [] as $wrong) {
            if ($said === self::normalize($wrong['text'])) {
                return $this->result('wrong', false, $target, $wrong['why'], 'known');
            }
        }

        return $this->askAi($level, $heard, $learnerDescription, $extraWords);
    }

    /**
     * Lower-case, contractions opened, small digits as words, no
     * punctuation, single spaces and no leading "um/so": what two spoken
     * versions of the same sentence have in common.
     */
    public static function normalize(string $text): string
    {
        $text = mb_strtolower(trim(str_replace(['’', '‘', '`'], "'", $text)));
        $text = preg_replace('/\s+/', ' ', $text) ?? '';

        foreach (self::CONTRACTIONS as $short => $long) {
            $text = preg_replace('/(?<![a-z\'])'.preg_quote($short, '/').'(?![a-z\'])/u', $long, $text) ?? $text;
        }

        $text = preg_replace_callback('/(?<![\d:])\b(\d{1,2})\b(?![\d:])/', fn (array $match) => self::NUMBERS[$match[1]] ?? $match[1], $text) ?? $text;
        $text = trim((string) preg_replace('/[^a-z0-9\' ]+/u', ' ', $text));
        $words = array_values(array_filter(explode(' ', $text), fn (string $word) => $word !== ''));

        while ($words !== [] && in_array($words[0], self::FILLERS, true)) {
            array_shift($words);
        }

        return implode(' ', $words);
    }

    /**
     * @return list<string>
     */
    private static function words(string $normalized): array
    {
        return $normalized === '' ? [] : explode(' ', $normalized);
    }

    /**
     * @param  list<string>  $extraWords
     */
    private function saidMost(array $extraWords, string $rest): bool
    {
        $heard = self::words($rest);

        return $heard !== [] && count(array_intersect($extraWords, $heard)) / count($extraWords) >= self::EXTRA_SHARE;
    }

    /**
     * @param  array{fa?: string, en: string, en_extra?: ?string}  $level
     * @param  list<string>  $extraWords
     * @return array{status: 'ok'|'wrong'|'unchecked', extra: bool, correct: string, why: ?string, by: 'exact'|'known'|'ai'|'none'}
     */
    private function askAi(array $level, string $heard, string $learnerDescription, array $extraWords): array
    {
        try {
            $raw = $this->gemini->chat(
                [['role' => 'user', 'text' => "Task (Persian): {$level['fa']}\nTarget sentence: \"{$level['en']}\"\nLearner's spoken sentence (automatic transcript): \"{$heard}\""]],
                systemPrompt: 'You are a warm English speaking coach for '.($learnerDescription !== '' ? $learnerDescription : 'a Persian-speaking learner').'. '
                    .'The learner was asked (in Persian) to say something in English; a target sentence is given. Judge the learner\'s sentence: '
                    .'it is correct only if it is natural, grammatical English that says the SAME thing as the target: the same topic, the same question or '
                    .'statement and the same details, even when the words differ a little (other order, a contraction, a synonym). A different sentence is '
                    .'NOT correct even when it is good English: another question, another topic, an extra question, or a missing part of the task. '
                    .'In that case set ok to false, give the target sentence as "correct" and say in "why" that it does not do what the task asks. '
                    .'Judge GRAMMAR and WORD ORDER like question forms, auxiliaries, tenses and prepositions. The transcript is automatic speech-to-text: '
                    .'ignore capital letters, punctuation and anything that looks like a mishearing. Use only simple words in your correction. '
                    .'Reply with ONLY valid JSON, no markdown fences: {"ok": true or false, "correct": "if not ok: the fixed sentence the learner should say, '
                    .'close to what they tried when it is the same idea, otherwise the target sentence; else the learner\'s sentence", "why": "if not ok: one short line in simple Persian '
                    .'saying what to fix, English words in Latin letters inside «»; else null", "extra": true or false}. "extra" is true only when the '
                    .'learner added a real extra detail on top of what the task asked. Never use double quote characters inside any text value.',
                profile: GeminiClient::PROFILE_JUDGE,
            );
        } catch (Throwable) {
            return $this->result('unchecked', false, $level['en'], null, 'none');
        }

        $data = AiJson::decode($raw);

        if (! is_array($data) || ! is_bool($data['ok'] ?? null)) {
            return $this->result('unchecked', false, $level['en'], null, 'none');
        }

        if ($data['ok']) {
            return $this->result('ok', ($data['extra'] ?? false) === true && $extraWords !== [], $level['en'], null, 'ai');
        }

        $correct = trim((string) ($data['correct'] ?? ''));
        $why = trim((string) ($data['why'] ?? ''));

        return $this->result('wrong', false, $correct !== '' ? $correct : $level['en'], $why !== '' ? $why : null, 'ai');
    }

    /**
     * @param  'ok'|'wrong'|'unchecked'  $status
     * @param  'exact'|'known'|'ai'|'none'  $by
     * @return array{status: 'ok'|'wrong'|'unchecked', extra: bool, correct: string, why: ?string, by: 'exact'|'known'|'ai'|'none'}
     */
    private function result(string $status, bool $extra, string $correct, ?string $why, string $by): array
    {
        return ['status' => $status, 'extra' => $extra, 'correct' => $correct, 'why' => $why, 'by' => $by];
    }
}
