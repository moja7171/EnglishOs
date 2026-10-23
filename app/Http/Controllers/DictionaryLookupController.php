<?php

namespace App\Http\Controllers;

use App\Http\Resources\DictionaryEntryResource;
use App\Services\PhraseFinder;
use App\Services\WordLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Answers the tap-a-word sheet: the English meanings and Persian
 * translation of one word or phrase (see WordLookup), whether the learner
 * already has it in My Words, and — for a single word — the phrase it is
 * part of in the sentence it was tapped in (see PhraseFinder), which the
 * sheet offers as a ready suggestion. A plain JSON endpoint rather than a
 * Livewire action, so a lookup never re-renders the mission step the
 * learner is reading.
 */
class DictionaryLookupController extends Controller
{
    public function __invoke(Request $request, WordLookup $lookup, PhraseFinder $phrases): JsonResponse
    {
        $word = WordLookup::normalize((string) $request->query('word', ''));

        if ($word === null) {
            return response()->json(['message' => 'That is not an English word or phrase.'], 422);
        }

        $sentence = Str::limit(trim((string) $request->query('sentence', '')), 300, '') ?: null;
        $entry = $lookup->lookup($word, $sentence);

        if ($entry === null) {
            return response()->json(['message' => 'The dictionary could not be reached.'], 503);
        }

        $phrase = WordLookup::isPhrase($word) ? null : $phrases->find(
            $word,
            $this->words($request->query('before')),
            $this->words($request->query('after')),
        );

        return (new DictionaryEntryResource($entry))
            ->additional([
                'saved' => $request->user()->vocabularyWords()->where('word', $entry->word)->exists(),
                'phrase' => $phrase,
            ])
            ->response();
    }

    /**
     * The words the sheet sent from one side of the tapped word (PhraseFinder
     * keeps the nearest few).
     *
     * @return list<string>
     */
    private function words(mixed $text): array
    {
        return preg_split('/\s+/u', trim(is_string($text) ? $text : ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
