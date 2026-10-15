<?php

namespace App\Http\Controllers;

use App\Http\Resources\DictionaryEntryResource;
use App\Models\DictionaryEntry;
use App\Services\WordLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The second step of a tap on a word: once the sheet has shown the quick
 * answer, it asks for the better Persian meanings (one line per part of
 * speech). Separate from the lookup so the first answer never waits for
 * Gemini.
 */
class DictionaryPersianController extends Controller
{
    public function __invoke(Request $request, WordLookup $lookup): JsonResponse
    {
        $word = WordLookup::normalize((string) $request->query('word', ''));
        $entry = $word === null ? null : DictionaryEntry::where('word', $word)->first();

        if ($entry === null) {
            return response()->json(['message' => 'Look the word up first.'], 404);
        }

        $entry = $lookup->improvePersian($entry);

        if ($entry->persian_senses === null) {
            return response()->json(['message' => 'No better Persian meaning right now.'], 503);
        }

        return (new DictionaryEntryResource($entry))->response();
    }
}
