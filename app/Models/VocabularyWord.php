<?php

namespace App\Models;

use App\Models\Concerns\HasSpacedRepetition;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['learner_id', 'source_mission_run_id', 'word', 'meaning', 'ease_factor', 'interval_days', 'repetitions', 'next_review_at', 'last_reviewed_at'])]
class VocabularyWord extends Model
{
    use HasFactory;
    use HasSpacedRepetition;

    /**
     * Explicit PHP-side defaults, not left to the migration's DB column
     * defaults — Eloquent doesn't re-fetch a Postgres row's own defaults
     * after insert(), so a freshly created instance (e.g.
     * firstOrCreate() in Vocabulary Builder's save()) would otherwise
     * read these as null in the very same request instead of their real
     * value, breaking needsWrittenReview()'s repetitions === 0 check for
     * a brand-new word. Setting them here means every INSERT writes them
     * explicitly, so there's nothing to re-fetch.
     */
    protected $attributes = [
        'ease_factor' => 2.5,
        'interval_days' => 0,
        'repetitions' => 0,
    ];

    protected function casts(): array
    {
        return [
            'ease_factor' => 'float',
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
     * @return BelongsTo<MissionRun, $this>
     */
    public function sourceMissionRun(): BelongsTo
    {
        return $this->belongsTo(MissionRun::class, 'source_mission_run_id');
    }

    // isDue(), needsWrittenReview(), and review() — the SM-2 spaced-
    // repetition schedule — come from HasSpacedRepetition, shared with
    // any future review-driven feature (Speaking Recall, Error Log
    // review, ...) instead of being re-derived per model.
}
