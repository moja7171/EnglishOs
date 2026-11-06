<?php

namespace App\Http\Controllers;

use App\Services\WordLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The Persian line of the tap-a-word sheet for one English meaning: asked
 * when the learner picks another meaning than the first, so the Persian
 * above always says what the meaning below does (see
 * WordLookup::persianForMeaning()).
 */
class DictionaryMeaningPersianController extends Controller
{
    public function __invoke(Request $request, WordLookup $lookup): JsonResponse
    {
        $word = WordLookup::normalize((string) $request->query('word', ''));
        $meaning = Str::limit(trim((string) $request->query('meaning', '')), 300, '');
        $pos = Str::limit(trim((string) $request->query('pos', '')), 30, '') ?: null;

        if ($word === null || $meaning === '') {
            return response()->json(['message' => 'Send a word and one of its meanings.'], 422);
        }

        $persian = $lookup->persianForMeaning($word, $meaning, $pos);

        if ($persian === null) {
            return response()->json(['message' => 'No Persian for this meaning right now.'], 503);
        }

        return response()->json(['data' => ['translation_fa' => $persian]]);
    }
}
