<?php

namespace App\Services;

use App\Models\AskAnswerItem;

/**
 * The automatic checks every Ask & Answer situation passes before anyone
 * reads it: structure (three levels each side, optional lines, known wrong
 * versions with a reason), length, topics a learner should not be asked
 * about, words a speech-to-text engine mishears (names), duplicates and
 * words beyond the learner's level. A situation with problems is not
 * written to the bank; one without still gets read by a person.
 *
 * An item is as in database/seeders/data/ask_answer/*.json: {k, s, c, q, a}
 * where q and a are three levels [fa, fa_extra, en, en_extra, [alt], [[wrong, why]]].
 */
class AskAnswerFilter
{
    /** A level 1 question is this many words at most. */
    private const MAX_WORDS_LEVEL_ONE_QUESTION = 10;

    /** Subjects that are sensitive for the learners or silly as practice. */
    private const BANNED = ['beer', 'wine', 'vodka', 'alcohol', 'drunk', 'boyfriend', 'girlfriend', 'dating', 'politics', 'religion', 'salary of', 'how old are you', 'cat on the table'];

    /** Capitalised words that speech-to-text writes right anyway. */
    private const SAFE_CAPITALS = ['I', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December', 'English', 'CV', 'Persian'];

    /** Little words that are always fine, so the frequency list is not asked about them. */
    private const ALWAYS_FINE = ['ok', 'cv', 'tv', 'pm', 'am', 'practise', 'practised', 'cafe', 'café'];

    public function __construct(private readonly WordFrequency $frequency) {}

    /**
     * @param  array<string, mixed>  $item
     * @param  list<string>  $allowedWords  words (lower case) taught up to this mission, which may be rare in general
     * @param  list<string>  $takenQuestions  normalised level 1 questions already in the bank or batch
     * @return list<string> what is wrong; empty when the item passes
     */
    public function problems(array $item, array $allowedWords = [], array $takenQuestions = []): array
    {
        $problems = [];

        if (! in_array($item['k'] ?? null, ['s', 'f'], true)) {
            $problems[] = 'kind must be s or f';
        }

        if (blank($item['s'] ?? null)) {
            $problems[] = 'no subject (s)';
        }

        if (($item['k'] ?? null) === 'f' && blank($item['c'] ?? null)) {
            $problems[] = 'a follow-up needs a context line (c)';
        }

        if (($item['k'] ?? null) === 's' && filled($item['c'] ?? null)) {
            $problems[] = 'a plain situation has no context line';
        }

        foreach (['q' => 'question', 'a' => 'answer'] as $side => $name) {
            $levels = $item[$side] ?? [];

            if (count($levels) !== count(AskAnswerItem::LEVELS)) {
                $problems[] = "$name must have exactly 3 levels";

                continue;
            }

            foreach (array_values($levels) as $index => $level) {
                $problems = [...$problems, ...$this->levelProblems($level, "$name L".($index + 1), $allowedWords)];
            }

            $first = str_word_count($levels[0][2] ?? '');
            $last = str_word_count($levels[2][2] ?? '');

            if ($last < $first) {
                $problems[] = "$name: level 3 is shorter than level 1";
            }
        }

        if (str_word_count($item['q'][0][2] ?? '') > self::MAX_WORDS_LEVEL_ONE_QUESTION) {
            $problems[] = 'level 1 question is too long';
        }

        $question = AskAnswerChecker::normalize($item['q'][0][2] ?? '');

        if ($question !== '' && in_array($question, $takenQuestions, true)) {
            $problems[] = 'the level 1 question is already in the bank';
        }

        return $problems;
    }

    /**
     * @param  list<mixed>  $level
     * @param  list<string>  $allowedWords
     * @return list<string>
     */
    private function levelProblems(array $level, string $where, array $allowedWords): array
    {
        if (count($level) !== 6) {
            return ["$where: a level is [fa, fa_extra, en, en_extra, [alt], [[wrong, why]]]"];
        }

        [$fa, $faExtra, $en, $enExtra, $alt, $wrong] = $level;
        $problems = [];

        foreach (['Persian' => $fa, 'optional Persian' => $faExtra, 'English' => $en, 'optional English' => $enExtra] as $name => $text) {
            if (blank($text)) {
                $problems[] = "$where: no $name";
            }
        }

        if (blank($wrong) || ! is_array($wrong)) {
            $problems[] = "$where: no known wrong version";
        }

        $target = AskAnswerChecker::normalize((string) $en);
        $accepted = collect([$en, ...((array) $alt)])->map(fn ($text) => AskAnswerChecker::normalize((string) $text))->all();

        foreach ((array) $wrong as $row) {
            if (! is_array($row) || count($row) !== 2 || blank($row[0]) || blank($row[1])) {
                $problems[] = "$where: a wrong version needs [text, Persian reason]";

                continue;
            }

            if (in_array(AskAnswerChecker::normalize($row[0]), $accepted, true)) {
                $problems[] = "$where: wrong version \"{$row[0]}\" is also accepted";
            }
        }

        $english = implode(' ', array_filter([$en, $enExtra, ...((array) $alt)], 'is_string'));
        $lower = mb_strtolower($english.' '.$fa.' '.$faExtra);

        foreach (self::BANNED as $banned) {
            if (preg_match('/\b'.preg_quote($banned, '/').'\b/u', $lower) === 1) {
                $problems[] = "$where: sensitive or silly topic \"$banned\"";
            }
        }

        foreach ($this->nameLikeWords($english) as $name) {
            $problems[] = "$where: \"$name\" looks like a name, which speech-to-text mishears";
        }

        foreach ($this->hardWords($english, $allowedWords) as $word) {
            $problems[] = "$where: \"$word\" is beyond the level";
        }

        return $target === '' ? [...$problems, "$where: empty English"] : $problems;
    }

    /**
     * Capitalised words in the middle of a sentence that are not on the
     * safe list.
     *
     * @return list<string>
     */
    private function nameLikeWords(string $english): array
    {
        preg_match_all('/(?<![.!?]\s)(?<!^)\b([A-Z][a-z]+)\b/u', $english, $matches);

        return array_values(array_unique(array_filter($matches[1], fn (string $word) => ! in_array($word, self::SAFE_CAPITALS, true) && ! in_array(rtrim($word, 's'), self::SAFE_CAPITALS, true))));
    }

    /**
     * Words the frequency list calls rare that this mission never taught.
     *
     * @param  list<string>  $allowedWords
     * @return list<string>
     */
    private function hardWords(string $english, array $allowedWords): array
    {
        preg_match_all("/[\p{L}]+(?:'[a-z]+)?/u", $english, $matches);

        return collect($matches[0])
            ->map(fn (string $word) => mb_strtolower(preg_replace("/'.*/", '', $word)))
            ->unique()
            ->reject(fn (string $word) => mb_strlen($word) <= 2 || in_array($word, self::ALWAYS_FINE, true) || in_array($word, $allowedWords, true))
            ->filter(fn (string $word) => $this->frequency->levelOf($word) === WordFrequency::RARE)
            ->values()
            ->all();
    }
}
