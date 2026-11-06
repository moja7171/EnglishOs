<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreVocabularyWordRequest;
use App\Models\DictionaryEntry;
use App\Models\VocabularyWord;
use App\Services\WordLookup;
use App\Services\WordSentences;
use Illuminate\Http\JsonResponse;

class VocabularyWordController extends Controller
{
    /**
     * Adds a word the learner tapped in a text to My Words. The Persian
     * meaning is read from the shared dictionary entry, not from the
     * request — and, once the answer is sent, replaced by the Persian of the
     * meaning they chose in the sentence they met it in (Gemini), because the
     * entry's own Persian is for the word in general. A word they already
     * have keeps its review progress — it only gains a Persian meaning it was
     * missing.
     */
    public function store(StoreVocabularyWordRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $translation = DictionaryEntry::where('word', $validated['word'])->value('translation_fa');

        $word = $request->user()->vocabularyWords()->firstOrCreate(
            ['word' => $validated['word']],
            [
                'meaning' => $validated['meaning'] ?? null,
                'meaning_fa' => $translation,
                'pos' => $validated['pos'] ?? null,
                'example' => $validated['example'] ?? null,
                'next_review_at' => now()->addDay(),
            ],
        );

        if (! $word->wasRecentlyCreated && blank($word->meaning_fa) && filled($translation)) {
            $word->update(['meaning_fa' => $translation]);
        }

        if ($word->wasRecentlyCreated) {
            dispatch(function () use ($word) {
                $inContext = app(WordLookup::class)->persianInContext($word->word, $word->meaning, $word->pos, $word->example);

                if ($inContext !== null) {
                    $word->update(['meaning_fa' => $inContext, 'meaning_fa_checked_at' => now()]);
                }
            })->afterResponse();
        }

        if ($word->wasRecentlyCreated && (blank($word->example) || ! VocabularyWord::isSimple($word->example))) {
            $learner = $request->user();

            dispatch(fn () => app(WordSentences::class)->makeOne($word, $learner))->afterResponse();
        }

        return response()->json(['saved' => true, 'already_had' => ! $word->wasRecentlyCreated], $word->wasRecentlyCreated ? 201 : 200);
    }
}
