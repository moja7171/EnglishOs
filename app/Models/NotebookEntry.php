<?php

namespace App\Models;

use App\Models\Concerns\HasSpacedRepetition;
use App\Services\LearnerFeedback;
use Database\Factories\NotebookEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line in a learner's notebook — a mistake or a tip — written by
 * FeedbackNotebook from AI feedback, reviewed in Daily Review like any
 * other spaced-repetition item until the learner marks it learned.
 */
#[Fillable(['learner_id', 'kind', 'dedupe_key', 'label', 'original', 'better', 'note', 'source', 'times_seen', 'last_seen_at', 'learned_at', 'ease_factor', 'interval_days', 'repetitions', 'next_review_at', 'last_reviewed_at'])]
class NotebookEntry extends Model
{
    /** @use HasFactory<NotebookEntryFactory> */
    use HasFactory;

    use HasSpacedRepetition;

    public const KIND_MISTAKE = 'mistake';

    public const KIND_TIP = 'tip';

    /**
     * Explicit PHP-side defaults — same Postgres/Eloquent hydration gap
     * documented on VocabularyWord.
     */
    protected $attributes = [
        'times_seen' => 1,
        'ease_factor' => 2.5,
        'interval_days' => 0,
        'repetitions' => 0,
    ];

    protected function casts(): array
    {
        return [
            'ease_factor' => 'float',
            'last_seen_at' => 'datetime',
            'learned_at' => 'datetime',
            'next_review_at' => 'datetime',
            'last_reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'learner_id');
    }

    public function isMistake(): bool
    {
        return $this->kind === self::KIND_MISTAKE;
    }

    /**
     * A tip that is a speaking technique (not a phrase swap) — its label
     * is a LearnerFeedback::TECHNIQUES key.
     */
    public function techniqueName(): ?string
    {
        return $this->kind === self::KIND_TIP && $this->label !== null
            ? (LearnerFeedback::TECHNIQUES[$this->label]['name'] ?? null)
            : null;
    }

    /**
     * @param  Builder<NotebookEntry>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('learned_at');
    }
}
