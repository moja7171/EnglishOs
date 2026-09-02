<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['learner_id', 'source_mission_run_id', 'word', 'meaning', 'ease_factor', 'interval_days', 'repetitions', 'next_review_at', 'last_reviewed_at'])]
class VocabularyWord extends Model
{
    use HasFactory;

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

    public function isDue(): bool
    {
        return $this->next_review_at <= now();
    }

    /**
     * True the moment this word is "fresh" — brand new (repetitions is
     * still its default 0) or just knocked back to the start by a failed
     * review — which is exactly when the My Words review flow asks for a
     * real written, AI-checked sentence instead of a quick
     * self-assessment. See review() and the "My Words" page.
     */
    public function needsWrittenReview(): bool
    {
        return $this->repetitions === 0;
    }

    /**
     * The SM-2 spaced-repetition algorithm (SuperMemo, 1987), applied
     * verbatim — chosen over a fixed-box Leitner scheme because it
     * personalizes the growing interval per word via ease_factor instead
     * of jumping every word through the same fixed steps regardless of
     * how easy or hard it actually is for this learner.
     *
     * $quality is a 0-5 recall score, clamped: <3 is a failed recall
     * (back to day 1, repetitions reset to 0 — see needsWrittenReview());
     * >=3 grows the interval, using ease_factor to adapt per word rather
     * than jumping a fixed amount. The My Words review flow maps its two
     * grading modes onto this same scale: a self-assessment tap
     * (Again/Good/Easy → 1/4/5) for a word already reviewed successfully
     * at least once, or an AI-checked sentence's severity
     * (major/minor/none → 1/4/5) the first time a word is reviewed (or
     * right after it was knocked back).
     */
    public function review(int $quality): void
    {
        $quality = max(0, min(5, $quality));

        if ($quality < 3) {
            $this->repetitions = 0;
            $this->interval_days = 1;
        } else {
            $this->repetitions++;
            $this->interval_days = match ($this->repetitions) {
                1 => 1,
                2 => 6,
                default => (int) round($this->interval_days * $this->ease_factor),
            };
        }

        $this->ease_factor = max(1.3, $this->ease_factor + (0.1 - (5 - $quality) * (0.08 + (5 - $quality) * 0.02)));
        $this->last_reviewed_at = now();
        $this->next_review_at = now()->addDays($this->interval_days);
        $this->save();
    }
}
