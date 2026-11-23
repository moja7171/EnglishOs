<?php

namespace App\Models;

use Database\Factories\VocabItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A new word of a Vocab in Use lesson (a complete expression such as "comb your hair"): where it sits in the text
 * body, the book's own English explanation and where the section's mp3
 * says it.
 */
#[Fillable(['vocab_text_id', 'position', 'phrase', 'start', 'end', 'sentence_index', 'ipa', 'gloss', 'sentence', 'phrase_fa', 'audio_start', 'audio_end'])]
class VocabItem extends Model
{
    /** @use HasFactory<VocabItemFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'audio_start' => 'float',
            'audio_end' => 'float',
        ];
    }

    /**
     * Whether the word sits in the section's text (and so can be underlined there).
     */
    public function isInText(): bool
    {
        return $this->start !== null && $this->end !== null;
    }

    /**
     * The learners' review cards made from this word.
     *
     * @return HasMany<VocabularyWord, $this>
     */
    public function cards(): HasMany
    {
        return $this->hasMany(VocabularyWord::class, 'vocab_item_id');
    }

    /**
     * @return BelongsTo<VocabText, $this>
     */
    public function text(): BelongsTo
    {
        return $this->belongsTo(VocabText::class, 'vocab_text_id');
    }
}
