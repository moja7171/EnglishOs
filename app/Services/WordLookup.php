<?php

namespace App\Services;

use App\Models\DictionaryEntry;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What the tap-a-word sheet shows for one word: the English meanings
 * (WiktionaryClient) and the Persian ones — from the offline dictionary
 * (PersianGlossary) when it has the word, else MyMemoryClient and later
 * Gemini. Outside services are asked once per word for every learner and
 * the answer is kept in dictionary_entries.
 *
 * Fails soft in both directions: a source that cannot be reached leaves
 * its half of the entry empty rather than failing the lookup, and a gap
 * caused by an outage is never cached as if it were an answer — a word is
 * only stored as "unknown to the dictionary" when the dictionary actually
 * said so.
 */
class WordLookup
{
    /** Longest word the sheet looks up — keeps a stray long selection out of the services. */
    private const MAX_LENGTH = 40;

    /** How long a missing Persian translation waits before the next tap asks again. */
    private const TRANSLATION_RETRY_SECONDS = 600;

    public function __construct(
        private readonly WiktionaryClient $dictionary,
        private readonly MyMemoryClient $translator,
        private readonly GeminiWordDefiner $gemini,
        private readonly PersianGlossary $glossary,
    ) {}

    /**
     * The word as it is looked up and stored: lower-cased, without the
     * punctuation or quotes around it. null for anything that is not one
     * plain English word (a phrase, a number, text in another script).
     */
    public static function normalize(string $word): ?string
    {
        $word = mb_strtolower(str_replace('’', "'", $word));
        $word = preg_replace('/^[^\p{L}]+|[^\p{L}]+$/u', '', $word) ?? '';

        if ($word === '' || mb_strlen($word) > self::MAX_LENGTH) {
            return null;
        }

        return preg_match("/^[a-z]+(?:['-][a-z]+)*$/", $word) === 1 ? $word : null;
    }

    /**
     * null when $word is not a lookable word, or when neither source could
     * be asked. Otherwise the entry — saved, except while the dictionary
     * could not be reached (then it is only a transient one, so the next
     * tap asks again).
     */
    public function lookup(string $word): ?DictionaryEntry
    {
        $key = self::normalize($word);

        if ($key === null) {
            return null;
        }

        $entry = DictionaryEntry::where('word', $key)->first();

        if ($entry === null) {
            return $this->fetchNew($key);
        }

        if ($entry->translation_fa === null && Cache::add("dictionary-entry-translation-retry:{$key}", true, self::TRANSLATION_RETRY_SECONDS)) {
            $translation = $this->translate($key);

            if ($translation !== null) {
                $entry->update(['translation_fa' => $translation]);
            }
        }

        return $entry;
    }

    /**
     * Replaces the quick Persian answer with the meanings per part of
     * speech: from the offline dictionary when it has the word (now that
     * the English meanings can point at the base form of "overslept"), else
     * Gemini's (see GeminiWordDefiner::persianMeanings()). Asked after the
     * first answer has been shown, so the tap itself stays fast, and kept
     * for every learner like the rest of the entry. Returns the entry
     * unchanged when it already has them or Gemini cannot be asked — a
     * failure is not retried for a few minutes, so a quota that ran out is
     * not hammered by every tap.
     */
    public function improvePersian(DictionaryEntry $entry): DictionaryEntry
    {
        if (! $entry->exists || $entry->persian_senses !== null) {
            return $entry;
        }

        if (! Cache::add("dictionary-entry-persian-retry:{$entry->word}", true, self::TRANSLATION_RETRY_SECONDS)) {
            return $entry;
        }

        $source = 'wiktionary';
        $senses = $this->glossary->senses($entry->word, $entry->meanings);

        if ($senses === []) {
            $source = 'gemini';

            try {
                $senses = $this->gemini->persianMeanings($entry->word, $entry->meanings);
            } catch (Throwable $exception) {
                Log::warning("Persian meanings failed for \"{$entry->word}\": {$exception->getMessage()}");

                return $entry;
            }
        }

        if ($senses !== []) {
            $entry->update(['persian_senses' => $senses, 'persian_source' => $source, 'translation_fa' => $senses[0]['words']]);
            Cache::forget("dictionary-entry-persian-retry:{$entry->word}");
        }

        return $entry;
    }

    /**
     * Asks Wiktionary and, unless the offline dictionary already has the
     * Persian, MyMemory at the same time — a first tap on a word costs the
     * slower answer, not their sum.
     */
    private function fetchNew(string $key): ?DictionaryEntry
    {
        $early = $this->glossary->senses($key);

        $responses = Http::pool(function (Pool $pool) use ($key, $early) {
            $requests = [$this->dictionary->fetch($pool->as('dictionary'), $key)];

            if ($early === []) {
                $requests[] = $this->translator->fetch($pool->as('translation'), $key);
            }

            return $requests;
        });

        $dictionaryAnswered = true;
        $definition = null;
        $translation = null;

        try {
            $definition = $this->dictionary->parse($responses['dictionary']);
        } catch (Throwable $exception) {
            $dictionaryAnswered = false;
            Log::warning("Dictionary lookup failed for \"{$key}\": {$exception->getMessage()}");
        }

        if (isset($responses['translation'])) {
            try {
                $translation = $this->translator->parse($responses['translation'], $key);
            } catch (Throwable $exception) {
                Log::warning("Persian translation failed for \"{$key}\": {$exception->getMessage()}");
            }
        }

        // The dictionary is down, or it does not list this form: ask Gemini
        // for the meanings (and a Persian word if nothing else had one).
        $second = $definition === null ? $this->askGemini($key) : null;

        if ($second !== null) {
            $definition = ['phonetic' => null, 'audio_url' => null, 'meanings' => $second['meanings']];
            $translation ??= $second['translation_fa'];
            $dictionaryAnswered = true;
        }

        // Asked again now that the English meanings are known: they put the
        // parts of speech in their order, and a form such as "overslept" is
        // only found once they say it is the past tense of "oversleep".
        $senses = $this->glossary->senses($key, $definition['meanings'] ?? []);

        if (! $dictionaryAnswered && $translation === null && $senses === []) {
            return null;
        }

        $attributes = [
            'word' => $key,
            'phonetic' => $definition['phonetic'] ?? null,
            'audio_url' => $definition['audio_url'] ?? null,
            'meanings' => $definition['meanings'] ?? [],
            'translation_fa' => $senses !== [] ? $senses[0]['words'] : $translation,
            'persian_senses' => $senses ?: null,
            'persian_source' => $senses !== [] ? 'wiktionary' : ($translation !== null ? 'mymemory' : null),
        ];

        return $dictionaryAnswered
            ? DictionaryEntry::create($attributes)
            : new DictionaryEntry($attributes);
    }

    /**
     * @return array{meanings: list<array{pos: string, definitions: list<array{definition: string, example: ?string}>}>, translation_fa: ?string}|null
     */
    private function askGemini(string $key): ?array
    {
        try {
            $answer = $this->gemini->define($key);
        } catch (Throwable $exception) {
            Log::warning("Gemini word lookup failed for \"{$key}\": {$exception->getMessage()}");

            return null;
        }

        return $answer['meanings'] === [] ? null : $answer;
    }

    private function translate(string $key): ?string
    {
        try {
            return $this->translator->translateToPersian($key);
        } catch (Throwable $exception) {
            Log::warning("Persian translation failed for \"{$key}\": {$exception->getMessage()}");

            return null;
        }
    }
}
