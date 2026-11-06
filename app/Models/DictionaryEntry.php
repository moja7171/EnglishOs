<?php

namespace App\Models;

use App\Services\WordFrequency;
use App\Services\WordLookup;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * What the outside dictionary and translation services said about one
 * word or phrase, kept once for every learner (see App\Services\WordLookup).
 *
 * @property list<array{pos: string, definitions: list<array{definition: string, example: ?string}>}> $meanings
 * @property list<array{pos: string, words: string}>|null $persian_senses
 * @property int|null $commonness
 */
#[Fillable(['word', 'phonetic', 'audio_url', 'meanings', 'translation_fa', 'persian_senses', 'persian_source', 'commonness'])]
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

    /**
     * How common the word or phrase is (see WordFrequency): the level kept
     * on the entry, else a single word's from the frequency list. null for
     * a phrase that has not been rated, or one the dictionary does not
     * list.
     */
    public function knownCommonness(): ?int
    {
        if ($this->commonness !== null) {
            return $this->commonness;
        }

        return WordLookup::isPhrase($this->word) ? null : app(WordFrequency::class)->levelOf($this->word);
    }

    public function isKnownToDictionary(): bool
    {
        return $this->meanings !== [];
    }

    /**
     * A phrase the dictionary does not list ("went to the shop"): the sheet
     * shows only its Persian translation, with nothing to add to My Words.
     */
    public function isTranslationOnly(): bool
    {
        return $this->exists && WordLookup::isPhrase($this->word) && ! $this->isKnownToDictionary();
    }
}
