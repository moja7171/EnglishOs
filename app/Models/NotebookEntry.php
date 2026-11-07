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
 * or by itself after LEARNED_AFTER_STREAK good reviews in a row — or put
 * away to keep the list short, once a notebook has MAX_ACTIVE lines (see
 * FeedbackNotebook).
 */
#[Fillable(['learner_id', 'mission_id', 'kind', 'dedupe_key', 'label', 'original', 'better', 'note', 'source', 'times_seen', 'last_seen_at', 'learned_at', 'starred_at', 'practice_level', 'last_practised_at', 'ease_factor', 'interval_days', 'repetitions', 'next_review_at', 'last_reviewed_at'])]
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
     * At most this many of a day's speaking & grammar cards are notebook
     * lines while other items are waiting, so a busy feedback day can't push
     * speaking and grammar out. Places nothing else wants go to notebook
     * lines too.
     */
    public const DAILY_REVIEW_SLOTS = 3;

    /**
     * Good reviews in a row before a line counts as learned by itself — a
     * repeat mistake brings it back anyway (see FeedbackNotebook).
     */
    public const LEARNED_AFTER_STREAK = 3;

    /**
     * The most lines one notebook (mistakes, tips) keeps active per learner.
     * A new line past it puts the least important one away (see
     * FeedbackNotebook::trim()); it stays under "Learned and older".
     */
    public const MAX_ACTIVE = 40;

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
            'last_practised_at' => 'datetime',
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

    /**
     * The mission the line was last made in.
     *
     * @return BelongsTo<Mission, $this>
     */
    public function mission(): BelongsTo
    {
        return $this->belongsTo(Mission::class);
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
     * A speaking technique: never a line in the notebook, only the Tip card
     * on top of the Mistakes tab.
     */
    public function isTechnique(): bool
    {
        return $this->kind === self::KIND_TIP && $this->label !== null;
    }

    /**
     * A plain "say it like this" swap — a tip that isn't a technique. It
     * sits in the Mistakes tab as a "better way".
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
            ?? ($this->isMistake() ? 'Mistake' : 'Better way');
    }

    /**
     * The trait's SM-2 review, then "learned" once the line has been
     * remembered often enough — it leaves Daily Review by itself.
     */
    public function review(int $quality): void
    {
        $this->reviewOnSchedule($quality);

        if ($this->repetitions >= self::LEARNED_AFTER_STREAK) {
            $this->update(['learned_at' => now()]);
        }
    }

    /**
     * One rung up the practice ladder for a right answer, one down for a
     * wrong one — in Daily Review and the notebook's own practice alike.
     */
    public function practised(bool $correct): void
    {
        $this->update([
            'practice_level' => max(self::LEVEL_SPOT, min(self::LEVEL_SAY, $this->practice_level + ($correct ? 1 : -1))),
            'last_practised_at' => now(),
        ]);
    }

    /**
     * Saved or brought back by feedback since it was last practised —
     * never practised counts too.
     */
    public function isUnpractised(): bool
    {
        return $this->learned_at === null
            && ($this->last_practised_at === null || $this->last_seen_at?->greaterThan($this->last_practised_at));
    }

    /**
     * The status the Notebook's filter bar counts: not practised yet,
     * practising, or learned.
     */
    public function studyStatus(): string
    {
        return match (true) {
            $this->learned_at !== null => 'known',
            $this->isUnpractised() => 'new',
            default => 'learning',
        };
    }

    /**
     * @param  Builder<NotebookEntry>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('learned_at');
    }

    /**
     * The lines the Mistakes tab lists: mistakes and "better ways" (not the
     * speaking techniques, which only feed the Tip card).
     *
     * @param  Builder<NotebookEntry>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query
            ->where('kind', self::KIND_MISTAKE)
            ->orWhereNull('label'));
    }

    /**
     * The lines isUnpractised() is true for — the dot on the Notebook tab.
     *
     * @param  Builder<NotebookEntry>  $query
     */
    public function scopeUnpractised(Builder $query): void
    {
        $query->visible()->whereNull('learned_at')
            ->where(fn (Builder $query) => $query
                ->whereNull('last_practised_at')
                ->orWhereColumn('last_seen_at', '>', 'last_practised_at'));
    }

    /**
     * What Daily Review may show: the active mistakes. A "better way" is
     * not a mistake, so it is only practised in the notebook.
     *
     * @param  Builder<NotebookEntry>  $query
     */
    public function scopeInReview(Builder $query): void
    {
        $query->whereNull('learned_at')->where('kind', self::KIND_MISTAKE);
    }
}
