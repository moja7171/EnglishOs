<?php

namespace App\Services;

use App\Models\DictionaryEntry;
use App\Models\PhraseForm;

/**
 * The phrase a tapped word is part of — "gave" in "She gave up smoking" is
 * part of "give up" — for the ready-made suggestion at the top of the
 * tap-a-word sheet. Only phrases written next to each other count ("gave
 * it up" is not found), and only the ones in phrase_forms, the phrasal
 * verbs and fixed phrases Wiktionary lists.
 */
class PhraseFinder
{
    /** Words read on each side of the tapped one. */
    public const REACH = 3;

    private const MAX_WORDS = 4;

    public function __construct(private readonly PersianGlossary $glossary) {}

    /**
     * The longest known phrase that holds $word, with where it starts and
     * ends counted in words from $word (-1 = the word before it) and its
     * Persian when the dictionary already has it; null when there is none.
     *
     * @param  list<string>  $before  the words in front of $word, nearest last
     * @param  list<string>  $after  the words after $word, nearest first
     * @return array{phrase: string, from: int, to: int, translation_fa: ?string}|null
     */
    public function find(string $word, array $before, array $after): ?array
    {
        $before = $this->plainWords(array_reverse(array_slice($before, -self::REACH)));
        $after = $this->plainWords(array_slice($after, 0, self::REACH));
        $spans = [];

        for ($from = 0; $from <= count($before); $from++) {
            for ($to = 0; $to <= count($after); $to++) {
                if ($from + $to + 1 >= 2 && $from + $to + 1 <= self::MAX_WORDS) {
                    $words = [...array_reverse(array_slice($before, 0, $from)), $word, ...array_slice($after, 0, $to)];
                    $spans[implode(' ', $words)] = ['from' => -$from, 'to' => $to];
                }
            }
        }

        if ($spans === []) {
            return null;
        }

        $found = PhraseForm::whereIn('form', array_keys($spans))->get()->groupBy('form');

        $best = collect($spans)
            ->filter(fn (array $span, string $form) => $found->has($form))
            ->sortByDesc(fn (array $span) => [$span['to'] - $span['from'], $span['from']])
            ->keys()
            ->first();

        if ($best === null) {
            return null;
        }

        $phrase = PhraseForm::baseOf($best);

        return [
            'phrase' => $phrase,
            ...$spans[$best],
            'translation_fa' => DictionaryEntry::where('word', $phrase)->value('translation_fa')
                ?? ($this->glossary->senses($phrase)[0]['words'] ?? null),
        ];
    }

    /**
     * The words as WordLookup would look them up, up to the first one that
     * is not a plain English word — a phrase does not run across a number
     * or a dash.
     *
     * @param  list<string>  $words
     * @return list<string>
     */
    private function plainWords(array $words): array
    {
        $plain = [];

        foreach ($words as $word) {
            $normalized = WordLookup::normalize((string) $word);

            if ($normalized === null || str_contains($normalized, ' ')) {
                break;
            }

            $plain[] = $normalized;
        }

        return $plain;
    }
}
