<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * What the outside dictionary and translation services said about one
 * word, kept once for every learner (see App\Services\WordLookup).
 *
 * @property list<array{pos: string, definitions: list<array{definition: string, example: ?string}>}> $meanings
 * @property list<array{pos: string, words: string}>|null $persian_senses
 */
#[Fillable(['word', 'phonetic', 'audio_url', 'meanings', 'translation_fa', 'persian_senses'])]
class DictionaryEntry extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'meanings' => 'array',
            'persian_senses' => 'array',
        ];
    }

    public function isKnownToDictionary(): bool
    {
        return $this->meanings !== [];
    }
}
