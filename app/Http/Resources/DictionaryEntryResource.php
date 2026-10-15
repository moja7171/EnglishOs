<?php

namespace App\Http\Resources;

use App\Models\DictionaryEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What the tap-a-word sheet reads for one word.
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
            'translation_fa' => $this->translation_fa,
        ];
    }
}
