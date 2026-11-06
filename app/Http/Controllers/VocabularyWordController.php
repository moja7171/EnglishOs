<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreVocabularyWordRequest;
use App\Models\DictionaryEntry;
use App\Services\WordSentences;
use Illuminate\Http\JsonResponse;

class VocabularyWordController extends Controller
{
    /**
     * Adds a word the learner tapped in a text to My Words. The Persian
     * meaning is read from the shared dictionary entry, not from the
     * request. A word they already have keeps its review progress — it only
     * gains a Persian meaning it was missing.
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

        if ($word->wasRecentlyCreated && blank($word->example)) {
            $learner = $request->user();

            dispatch(fn () => app(WordSentences::class)->makeOne($word, $learner))->afterResponse();
        }

        return response()->json(['saved' => true, 'already_had' => ! $word->wasRecentlyCreated], $word->wasRecentlyCreated ? 201 : 200);
    }
}
