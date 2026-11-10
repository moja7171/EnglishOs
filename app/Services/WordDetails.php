<?php

namespace App\Services;

use App\Models\Mission;
use App\Models\VocabularyWord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Fills in what a saved word is missing — its type (noun, verb…), its
 * English meaning, its Persian and a sentence to learn it from — so a card
 * never shows the word alone.
 * The free sources go first (the lesson the word came from, the course's
 * own Persian), then the shared dictionary entry, and only for a Persian
 * still missing, one Gemini question. Nothing here ever throws: a word the
 * sources cannot help stays as it was and is tried again next time.
 */
class WordDetails
{
    /** How many words a screen asks to be filled at once, and how long it waits before asking again. */
    public const BATCH = 8;

    private const RETRY_MINUTES = 15;

    public function __construct(private readonly WordLookup $lookup, private readonly WordSentences $sentences) {}

    /**
     * Whether the word lacks its type, its English meaning or its Persian.
     */
    public static function isMissingDetails(VocabularyWord $word): bool
    {
        return blank($word->pos) || blank($word->meaning) || blank($word->meaning_fa);
    }

    /**
     * Whether the word has no sentence at all (needs its sentences loaded).
     */
    public static function lacksSentence(VocabularyWord $word): bool
    {
        return $word->learningSentences() === [];
    }

    /**
     * Fills the blanks of one word — details and a first sentence — and
     * says whether anything was added.
     */
    public function fill(VocabularyWord $word): bool
    {
        $before = $word->only(['pos', 'meaning', 'meaning_fa']);
        $gotSentence = false;

        if (self::isMissingDetails($word)) {
            $word->fillMissingDetailsFromSource();
            $this->fillFromCourse($word);

            if (self::isMissingDetails($word)) {
                $this->fillFromDictionary($word);
            }

            if (blank($word->meaning_fa)) {
                $this->fillPersianFromAi($word);
            }
        }

        $word->load('sentences');

        if (self::lacksSentence($word) && $word->learner !== null) {
            $gotSentence = $this->sentences->makeOne($word, $word->learner) !== null;
        }

        return $gotSentence || $word->only(['pos', 'meaning', 'meaning_fa']) !== $before;
    }

    /**
     * Fills a screen's words after the answer has gone out, and at most
     * once per learner in a while, so opening a page again never repeats
     * the same questions.
     *
     * @param  Collection<int, VocabularyWord>  $words
     */
    public function fillLaterFor(int $learnerId, Collection $words): void
    {
        $words->loadMissing('sentences');
        $missing = $words->filter(fn (VocabularyWord $word) => self::isMissingDetails($word) || self::lacksSentence($word))->take(self::BATCH);

        if ($missing->isEmpty() || ! Cache::add("word-details-asked:{$learnerId}", true, now()->addMinutes(self::RETRY_MINUTES))) {
            return;
        }

        $ids = $missing->pluck('id')->all();

        dispatch(static function () use ($ids) {
            $details = app(self::class);

            VocabularyWord::with('learner')->whereIn('id', $ids)->get()->each(fn (VocabularyWord $word) => $details->fill($word));
        })->afterResponse();
    }

    private function fillFromCourse(VocabularyWord $word): void
    {
        if (blank($word->meaning_fa)) {
            $persian = Mission::persianMeanings()[mb_strtolower($word->word)] ?? null;

            if (filled($persian)) {
                $word->update(['meaning_fa' => $persian]);
            }
        }
    }

    private function fillFromDictionary(VocabularyWord $word): void
    {
        try {
            $entry = $this->lookup->lookup($word->word);
        } catch (Throwable) {
            return;
        }

        if ($entry === null) {
            return;
        }

        $first = $entry->meanings[0] ?? null;
        $blanks = [];

        if (blank($word->pos) && filled($first['pos'] ?? null)) {
            $blanks['pos'] = $first['pos'];
        }

        if (blank($word->meaning) && filled($first['definitions'][0]['definition'] ?? null)) {
            $blanks['meaning'] = $first['definitions'][0]['definition'];
        }

        if (blank($word->meaning_fa) && filled($entry->translation_fa)) {
            $blanks['meaning_fa'] = $entry->translation_fa;
        }

        if ($blanks !== []) {
            $word->update($blanks);
        }
    }

    private function fillPersianFromAi(VocabularyWord $word): void
    {
        $persian = $this->lookup->persianInContext($word->word, $word->meaning, $word->pos, $word->example);

        if ($persian !== null) {
            $word->update(['meaning_fa' => $persian, 'meaning_fa_checked_at' => now()]);
        }
    }
}
