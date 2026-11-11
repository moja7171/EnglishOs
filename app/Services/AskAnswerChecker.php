<?php

namespace App\Services;

use Throwable;

/**
 * Checks one spoken sentence of the Ask & Answer game against the level the
 * learner is on. The cheap checks come first and cost no AI call: the
 * sentence is the target (or one of its accepted alternatives), or it is
 * one of the known wrong versions written for this level — which already
 * carries the Persian reason. Only a sentence that is neither goes to the
 * AI. The target is one example, not the only right sentence: any
 * grammatical sentence of the right kind (a question to ask, a real answer
 * to the question asked) on the topic of the task is right, with or
 * without more detail; the AI says what to fix when it is not.
 *
 * A "level" is the array stored on AskAnswerItem: fa, fa_extra, en,
 * en_extra, alt and wrong — plus, when the call site knows them, `friend`
 * (what the friend said, in Persian) and `asked` (for an answer, the
 * question that was asked).
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

                // The target and then exactly the extra line.
                if ($extraWords !== [] && self::words($rest) === $extraWords) {
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
     * @param  array{fa?: string, en: string, en_extra?: ?string}  $level
     * @param  list<string>  $extraWords
     * @return array{status: 'ok'|'wrong'|'unchecked', extra: bool, correct: string, why: ?string, by: 'exact'|'known'|'ai'|'none'}
     */
    private function askAi(array $level, string $heard, string $learnerDescription, array $extraWords): array
    {
        try {
            $raw = $this->gemini->chat(
                [['role' => 'user', 'text' => $this->aiMessage($level, $heard)]],
                systemPrompt: 'You are a warm English speaking coach for '.($learnerDescription !== '' ? $learnerDescription : 'a Persian-speaking learner').'. '
                    .'The learner plays a speaking game: they are asked (in Persian) to ASK a question or to ANSWER one, and they say it in English. '
                    .'You get the Persian task, one EXAMPLE sentence, sometimes what the friend said (Persian) and, for an answer, the question that was asked. '
                    .'The example is only one way to say it: the learner does NOT have to use its words or its details. '
                    .'Their sentence is correct when ALL of these are true: (1) it is natural, grammatical English, judged strictly — word order, question forms, '
                    .'auxiliaries, tenses, prepositions, articles, word forms — for the WHOLE sentence, including anything extra they add; '
                    .'(2) it is the right kind of sentence: a real question when they were asked to ask, a real answer to the given question when they were asked to answer; '
                    .'(3) it is on the topic of the task and the situation. More detail than the example is welcome and is not a mistake. '
                    .'It is NOT correct when there is a grammar or word-order mistake anywhere, when it is the wrong kind of sentence, '
                    .'when it is off the topic, or when it answers a different question. Never say a good sentence is wrong just because it differs from the example. '
                    .'The transcript is automatic speech-to-text: ignore capital letters, punctuation and anything that looks like a mishearing. Use only simple words. '
                    .'Reply with ONLY valid JSON, no markdown fences: {"ok": true or false, "correct": "if not ok: the learner\'s own sentence fixed with the fewest changes when '
                    .'it was close to the task, otherwise the example sentence; else the learner\'s sentence", "why": "if not ok: one short line in simple Persian '
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
     * @param  array<string, mixed>  $level
     */
    private function aiMessage(array $level, string $heard): string
    {
        $lines = ["Task (Persian): {$level['fa']}"];

        if (filled($level['friend'] ?? null)) {
            $lines[] = "What the friend said (Persian): {$level['friend']}";
        }

        if (filled($level['asked'] ?? null)) {
            $lines[] = "The question the learner is answering: \"{$level['asked']}\"";
        }

        $lines[] = "Example sentence: \"{$level['en']}\"";
        $lines[] = "Learner's spoken sentence (automatic transcript): \"{$heard}\"";

        return implode("\n", $lines);
    }

    /**
     * English wrapped in « » inside a Persian line is set as its own
     * left-to-right run, so the quotes, the full stop and a "?" stay where
     * they belong instead of jumping to the wrong side of the phrase.
     */
    public static function isolateEnglish(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        return preg_replace_callback(
            '/«([^»]*[A-Za-z][^»]*)»/u',
            fn (array $match) => "«\u{2066}".trim($match[1])."\u{2069}»",
            str_replace(["\u{2066}", "\u{2069}"], '', $text),
        ) ?? $text;
    }

    /**
     * @param  'ok'|'wrong'|'unchecked'  $status
     * @param  'exact'|'known'|'ai'|'none'  $by
     * @return array{status: 'ok'|'wrong'|'unchecked', extra: bool, correct: string, why: ?string, by: 'exact'|'known'|'ai'|'none'}
     */
    private function result(string $status, bool $extra, string $correct, ?string $why, string $by): array
    {
        return ['status' => $status, 'extra' => $extra, 'correct' => $correct, 'why' => self::isolateEnglish($why), 'by' => $by];
    }
}
