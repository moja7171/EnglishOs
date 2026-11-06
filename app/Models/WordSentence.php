<?php

namespace App\Models;

use Database\Factories\WordSentenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A sentence with one of the learner's words in it: made by the AI, or
 * written by the learner (then `better` holds the AI's corrected version
 * when theirs had a mistake, and `note` a short line about it).
 */
#[Fillable(['vocabulary_word_id', 'sentence', 'source', 'better', 'note'])]
class WordSentence extends Model
{
    /** @use HasFactory<WordSentenceFactory> */
    use HasFactory;

    public const SOURCE_AI = 'ai';

    public const SOURCE_OWN = 'own';

    /**
     * @return BelongsTo<VocabularyWord, $this>
     */
    public function word(): BelongsTo
    {
        return $this->belongsTo(VocabularyWord::class, 'vocabulary_word_id');
    }

    public function isOwn(): bool
    {
        return $this->source === self::SOURCE_OWN;
    }

    /**
     * The sentence to show and learn: the corrected one when the AI fixed
     * the learner's own.
     */
    public function shown(): string
    {
        return $this->better ?: $this->sentence;
    }
}
