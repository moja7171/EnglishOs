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
 * other spaced-repetition item until it is learned: marked by the learner,
 * or by itself after LEARNED_AFTER_STREAK good reviews in a row.
 */
#[Fillable(['learner_id', 'kind', 'dedupe_key', 'label', 'original', 'better', 'note', 'source', 'times_seen', 'last_seen_at', 'learned_at', 'starred_at', 'practice_level', 'ease_factor', 'interval_days', 'repetitions', 'next_review_at', 'last_reviewed_at'])]
class NotebookEntry extends Model
{
    /** @use HasFactory<NotebookEntryFactory> */
    use HasFactory;

    use HasSpacedRepetition {
        review as private reviewOnSchedule;
    }

    public const KIND_MISTAKE = 'mistake';

    public const KIND_TIP = 'tip';

    /**
     * At most this many of Daily Review's eight cards a day are notebook
     * lines, so a busy feedback day can't push words and grammar out.
     */
    public const DAILY_REVIEW_SLOTS = 3;

    /**
     * Good reviews in a row, and the gap they must have reached, before a
     * line counts as learned by itself — a repeat mistake brings it back
     * anyway (see FeedbackNotebook).
     */
    public const LEARNED_AFTER_STREAK = 3;

    public const LEARNED_AFTER_DAYS = 21;

    /**
     * The practice ladder's rungs (see NotebookDrill): tap the wrong word,
     * pick the fix from chips, then say it.
     */
    public const LEVEL_SPOT = 1;

    public const LEVEL_PICK = 2;

    public const LEVEL_SAY = 3;

    /**
     * Explicit PHP-side defaults — same Postgres/Eloquent hydration gap
     * documented on VocabularyWord.
     */
    protected $attributes = [
        'times_seen' => 1,
        'practice_level' => self::LEVEL_SPOT,
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
            'starred_at' => 'datetime',
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
     * A plain "say it like this" swap — a tip that isn't a technique.
     */
    public function isPhraseSwap(): bool
    {
        return $this->kind === self::KIND_TIP && $this->label === null;
    }

    /**
     * The line's heading: a technique's or mistake category's name, an
     * older line's own free-text rule, or a plain title.
     */
    public function heading(): string
    {
        return $this->techniqueName()
            ?? ($this->label !== null ? (LearnerFeedback::MISTAKE_CATEGORIES[$this->label] ?? $this->label) : null)
            ?? ($this->isMistake() ? 'Mistake' : 'Say it like this');
    }

    /**
     * The trait's SM-2 review, then "learned" once the line has been
     * remembered often enough — it leaves Daily Review by itself.
     */
    public function review(int $quality): void
    {
        $this->reviewOnSchedule($quality);

        if ($this->repetitions >= self::LEARNED_AFTER_STREAK && $this->interval_days >= self::LEARNED_AFTER_DAYS) {
            $this->update(['learned_at' => now()]);
        }
    }

    /**
     * One rung up the practice ladder for a right answer, one down for a
     * wrong one — in Daily Review and the notebook's own practice alike.
     */
    public function practised(bool $correct): void
    {
        $this->update(['practice_level' => max(self::LEVEL_SPOT, min(self::LEVEL_SAY, $this->practice_level + ($correct ? 1 : -1)))]);
    }

    /**
     * @param  Builder<NotebookEntry>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('learned_at');
    }

    /**
     * What Daily Review may show: active mistakes and techniques, and the
     * phrase swaps the learner starred — the rest are hard to self-test
     * and rarely come back.
     *
     * @param  Builder<NotebookEntry>  $query
     */
    public function scopeInReview(Builder $query): void
    {
        $query->whereNull('learned_at')
            ->where(fn (Builder $query) => $query
                ->where('kind', self::KIND_MISTAKE)
                ->orWhereNotNull('label')
                ->orWhereNotNull('starred_at'));
    }
}
