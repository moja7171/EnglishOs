<?php

namespace App\Http\Controllers;

use App\Http\Resources\DictionaryEntryResource;
use App\Services\WordLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Answers the tap-a-word sheet: the English meanings and Persian
 * translation of one word (see WordLookup), and whether the learner
 * already has it in My Words. A plain JSON endpoint rather than a Livewire
 * action, so a lookup never re-renders the mission step the learner is
 * reading.
 */
class DictionaryLookupController extends Controller
{
    public function __invoke(Request $request, WordLookup $lookup): JsonResponse
    {
        $word = WordLookup::normalize((string) $request->query('word', ''));

        if ($word === null) {
            return response()->json(['message' => 'That is not a single English word.'], 422);
        }

        $entry = $lookup->lookup($word);

        if ($entry === null) {
            return response()->json(['message' => 'The dictionary could not be reached.'], 503);
        }

        return (new DictionaryEntryResource($entry))
            ->additional(['saved' => $request->user()->vocabularyWords()->where('word', $word)->exists()])
            ->response();
    }
}
