<?php

namespace App\Services;

use App\Models\NotebookEntry;

/**
 * The card a notebook line is practised with, by the rung it is on
 * (NotebookEntry::$practice_level) — most cards take one tap: 1 tap the
 * wrong word in your own sentence, 2 pick the fix for the gap, 3 say the
 * right sentence. Built from the saved sentences, no AI: the wrong words
 * are what's left between the words both sentences start and end with. A
 * fix too big for a gap (several changes, a new sentence) is a "which is
 * right?" choice instead. Tips are always a choice — phrase swaps "which
 * sounds more natural?", techniques "which one uses it?" — then listen and
 * repeat.
 */
class NotebookDrill
{
    /** A fix that changes more words than this is a choice, not a gap. */
    public const MAX_GAP_WORDS = 3;

    /** Stands in for "no word here" on a pick chip. */
    public const NO_WORD = '—';

    /**
     * @return array{mode: 'spot', words: list<string>, wrong: list<int>}|array{mode: 'pick', before: string, after: string, options: list<string>, correct: int}|array{mode: 'choose', question: string, options: list<string>, correct: int}|array{mode: 'say'}
     */
    public static function card(NotebookEntry $entry): array
    {
        if ($entry->techniqueName() !== null) {
            return self::choice($entry, 'Which answer uses “'.$entry->techniqueName().'”?', $entry->better, LearnerFeedback::TECHNIQUES[$entry->label]['without']);
        }

        if ($entry->original === null || ($entry->isMistake() && $entry->practice_level >= NotebookEntry::LEVEL_SAY)) {
            return ['mode' => 'say'];
        }

        if (! $entry->isMistake()) {
            return self::choice($entry, 'Which sounds more natural?', $entry->better, $entry->original);
        }

        $gap = self::gap($entry->original, $entry->better);

        if ($gap === null || ($entry->practice_level === NotebookEntry::LEVEL_SPOT && $gap['wrong'] === [])) {
            return self::choice($entry, 'Which is right?', $entry->better, $entry->original);
        }

        if ($entry->practice_level === NotebookEntry::LEVEL_SPOT) {
            return ['mode' => 'spot', 'words' => $gap['words'], 'wrong' => $gap['wrong']];
        }

        $options = self::shuffled($entry, $gap['right'], $gap['said']);

        return ['mode' => 'pick', 'before' => $gap['before'], 'after' => $gap['after']] + $options;
    }

    /**
     * @return array{mode: 'choose', question: string, options: list<string>, correct: int}
     */
    private static function choice(NotebookEntry $entry, string $question, string $right, string $wrong): array
    {
        return ['mode' => 'choose', 'question' => $question] + self::shuffled($entry, $right, $wrong);
    }

    /**
     * The right answer first or second — fixed per line and rung, so the
     * card looks the same on every request.
     *
     * @return array{options: list<string>, correct: int}
     */
    private static function shuffled(NotebookEntry $entry, string $right, string $wrong): array
    {
        $rightFirst = crc32($entry->id.':'.$entry->practice_level) % 2 === 0;

        return ['options' => $rightFirst ? [$right, $wrong] : [$wrong, $right], 'correct' => $rightFirst ? 0 : 1];
    }

    /**
     * The one changed stretch between the learner's sentence and the fix,
     * or null when there isn't a small one.
     *
     * @return array{words: list<string>, wrong: list<int>, before: string, after: string, right: string, said: string}|null
     */
    private static function gap(string $original, string $better): ?array
    {
        $said = preg_split('/\s+/u', trim($original), -1, PREG_SPLIT_NO_EMPTY);
        $fixed = preg_split('/\s+/u', trim($better), -1, PREG_SPLIT_NO_EMPTY);
        $saidKeys = array_map(self::key(...), $said);
        $fixedKeys = array_map(self::key(...), $fixed);

        $start = 0;
        while ($start < count($said) && $start < count($fixed) && $saidKeys[$start] === $fixedKeys[$start]) {
            $start++;
        }

        $end = 0;
        while ($end < count($said) - $start && $end < count($fixed) - $start && $saidKeys[count($said) - 1 - $end] === $fixedKeys[count($fixed) - 1 - $end]) {
            $end++;
        }

        $wrongWords = array_slice($said, $start, count($said) - $start - $end);
        $rightWords = array_slice($fixed, $start, count($fixed) - $start - $end);

        if (($wrongWords === [] && $rightWords === []) || count($wrongWords) > self::MAX_GAP_WORDS || count($rightWords) > self::MAX_GAP_WORDS) {
            return null;
        }

        return [
            'words' => $said,
            'wrong' => $wrongWords === [] ? [] : range($start, $start + count($wrongWords) - 1),
            'before' => implode(' ', array_slice($fixed, 0, $start)),
            'after' => implode(' ', array_slice($fixed, count($fixed) - $end)),
            'right' => self::chip($rightWords),
            'said' => self::chip($wrongWords),
        ];
    }

    /**
     * Lower-case, curly apostrophes made straight, punctuation dropped —
     * "Home." and "home" are the same word.
     */
    private static function key(string $word): string
    {
        return preg_replace("/[^\p{L}\p{N}']/u", '', str_replace('’', "'", mb_strtolower($word)));
    }

    /**
     * @param  list<string>  $words
     */
    private static function chip(array $words): string
    {
        return $words === [] ? self::NO_WORD : rtrim(implode(' ', $words), '.,!?;:');
    }
}
