<?php

namespace App\Services;

use App\Models\DictionaryEntry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What the tap-a-word sheet shows for one word: the English meanings
 * (DictionaryApiClient) and the Persian translation (MyMemoryClient),
 * asked from the outside once per word for every learner and kept in
 * dictionary_entries after that.
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
        private readonly DictionaryApiClient $dictionary,
        private readonly MyMemoryClient $translator,
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

    private function fetchNew(string $key): ?DictionaryEntry
    {
        $dictionaryAnswered = true;
        $definition = null;

        try {
            $definition = $this->dictionary->define($key);
        } catch (Throwable $exception) {
            $dictionaryAnswered = false;
            Log::warning("Dictionary lookup failed for \"{$key}\": {$exception->getMessage()}");
        }

        $translation = $this->translate($key);

        if (! $dictionaryAnswered && $translation === null) {
            return null;
        }

        $attributes = [
            'word' => $key,
            'phonetic' => $definition['phonetic'] ?? null,
            'audio_url' => $definition['audio_url'] ?? null,
            'meanings' => $definition['meanings'] ?? [],
            'translation_fa' => $translation,
        ];

        return $dictionaryAnswered
            ? DictionaryEntry::create($attributes)
            : new DictionaryEntry($attributes);
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
