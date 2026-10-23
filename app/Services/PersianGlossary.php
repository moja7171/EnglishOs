<?php

namespace App\Services;

use App\Models\PersianGloss;
use App\Models\PhraseForm;
use Illuminate\Support\Collection;

/**
 * Looks a word up in the offline English -> Persian dictionary (the
 * persian_glosses table, built from Wiktionary — see PersianGlossSeeder).
 * Instant, free and without a quota, so WordLookup asks it before any
 * outside service.
 *
 * A tapped word is often not a headword — "overslept", "running",
 * "colleagues" — so it is also tried as the word it is a form of: the one
 * Wiktionary's own definition names ("past tense of oversleep"), or else a
 * plain stripping of -s, -ed and -ing. A phrase is tried as its base form
 * instead ("gave up" as "give up", see PhraseForm).
 */
class PersianGlossary
{
    private const MAX_PARTS_OF_SPEECH = 4;

    private const MAX_WORDS = 4;

    /** Parts of speech with no English meaning to follow are listed in this order. */
    private const POS_ORDER = ['noun', 'verb', 'adjective', 'adverb', 'pronoun', 'preposition', 'conjunction', 'interjection', 'determiner', 'numeral'];

    private const FORM_OF = '/\b(?:past tense|past participle|present participle|gerund|plural|third-person singular|simple past|comparative|superlative|alternative form|alternative spelling|misspelling)\b[^.;]*?\bof\s+([a-z][a-z\'-]*%s)/i';

    /**
     * The Persian words for each part of speech the word can be, in the
     * order of the English meanings when there are any; [] when the
     * dictionary has nothing for the word or any form of it.
     *
     * @param  list<array{pos: string, definitions: list<array{definition: string, example: ?string}>}>  $meanings
     * @return list<array{pos: string, words: string}>
     */
    public function senses(string $word, array $meanings = []): array
    {
        $candidates = $this->candidates($word, $meanings);
        $rows = PersianGloss::whereIn('word', $candidates)->get()->groupBy('word');

        foreach ($candidates as $candidate) {
            if ($rows->has($candidate)) {
                return $this->present($rows->get($candidate), $meanings);
            }
        }

        return [];
    }

    /**
     * @param  list<array{pos: string, definitions: list<array{definition: string, example: ?string}>}>  $meanings
     * @return list<string>
     */
    private function candidates(string $word, array $meanings): array
    {
        $candidates = [$word];
        $isPhrase = WordLookup::isPhrase($word);

        // "simple past of give up" names as many words as the phrase has.
        $formOf = sprintf(self::FORM_OF, $isPhrase ? '(?:\s[a-z][a-z\'-]*){0,'.substr_count($word, ' ').'}' : '');

        foreach ($meanings as $group) {
            foreach ($group['definitions'] as $definition) {
                if (preg_match($formOf, $definition['definition'], $found) === 1) {
                    $candidates[] = mb_strtolower($found[1]);
                }
            }
        }

        if ($isPhrase) {
            $candidates[] = PhraseForm::baseOf($word);

            return array_values(array_unique(array_filter($candidates)));
        }

        if (str_ends_with($word, 'ies')) {
            $candidates[] = substr($word, 0, -3).'y';
        }

        foreach (['es', 's', 'ed', 'ing'] as $ending) {
            if (str_ends_with($word, $ending) && strlen($word) > strlen($ending) + 2) {
                $stem = substr($word, 0, -strlen($ending));
                $candidates[] = $stem;
                $candidates[] = $stem.'e';

                if (strlen($stem) > 3 && $stem[-1] === $stem[-2]) {
                    $candidates[] = substr($stem, 0, -1);
                }
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @param  Collection<int, PersianGloss>  $rows
     * @param  list<array{pos: string, definitions: list<array{definition: string, example: ?string}>}>  $meanings
     * @return list<array{pos: string, words: string}>
     */
    private function present($rows, array $meanings): array
    {
        $order = array_merge(array_column($meanings, 'pos'), self::POS_ORDER);

        return $rows
            ->sortBy(fn (PersianGloss $gloss) => ($found = array_search($gloss->pos, $order, true)) === false ? PHP_INT_MAX : $found)
            ->take(self::MAX_PARTS_OF_SPEECH)
            ->map(fn (PersianGloss $gloss) => [
                'pos' => $gloss->pos,
                'words' => implode('، ', array_slice($gloss->words, 0, self::MAX_WORDS)),
            ])
            ->values()
            ->all();
    }
}
