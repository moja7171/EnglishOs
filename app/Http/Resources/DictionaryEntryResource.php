<?php

namespace App\Http\Resources;

use App\Models\DictionaryEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What the tap-a-word sheet reads for one word or phrase.
 *
 * @mixin DictionaryEntry
 */
class DictionaryEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'word' => $this->word,
            'phonetic' => $this->phonetic,
            'audio_url' => $this->audio_url,
            'meanings' => $this->meanings,
            // 'ok' = English meanings found; 'unknown' = the dictionary was
            // asked and does not list this exact form; 'unavailable' = it
            // could not be asked (the entry is not saved), so the sheet
            // offers a retry instead of claiming the word does not exist.
            'english_status' => match (true) {
                ! $this->exists => 'unavailable',
                $this->isKnownToDictionary() => 'ok',
                default => 'unknown',
            },
            // A phrase the dictionary does not list: the sheet shows only
            // its Persian, with nothing to add to My Words.
            'translation_only' => $this->isTranslationOnly(),
            'translation_fa' => $this->translation_fa,
            'persian_senses' => $this->persian_senses,
            'persian_source' => $this->persian_source,
        ];
    }
}
