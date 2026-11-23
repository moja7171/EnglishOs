<?php

namespace App\Models;

use Database\Factories\VocabItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bold word or phrase of a Vocab in Use text: where it sits in the text
 * body, the book's own English explanation and where the section's mp3
 * says it.
 */
#[Fillable(['vocab_text_id', 'position', 'phrase', 'start', 'end', 'sentence_index', 'ipa', 'gloss', 'phrase_fa', 'audio_start', 'audio_end'])]
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
     * @return BelongsTo<VocabText, $this>
     */
    public function text(): BelongsTo
    {
        return $this->belongsTo(VocabText::class, 'vocab_text_id');
    }
}
