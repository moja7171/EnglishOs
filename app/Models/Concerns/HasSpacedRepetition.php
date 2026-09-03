<?php

namespace App\Models\Concerns;

/**
 * The SM-2 spaced-repetition algorithm (SuperMemo, 1987) — first built
 * for VocabularyWord, pulled out here so any future review-driven
 * feature (Speaking Recall, an Error Log review cycle, ...) schedules
 * itself with the exact same proven math instead of re-deriving it.
 * Chosen over a fixed-box Leitner scheme because it personalizes the
 * growing interval per item via ease_factor, instead of jumping every
 * item through the same fixed steps regardless of how easy or hard it
 * actually is for this learner.
 *
 * The consuming model needs these columns: ease_factor (float),
 * interval_days (int), repetitions (int), next_review_at (datetime,
 * nullable), last_reviewed_at (datetime, nullable). It must also set
 * its own model-level `protected $attributes` defaults for
 * ease_factor (2.5), interval_days (0), repetitions (0) — Eloquent
 * doesn't re-fetch a Postgres row's own column defaults after
 * insert(), so a freshly created instance would otherwise read these
 * as null in the very same request/test instead of their real value,
 * breaking needsWrittenReview()'s repetitions === 0 check for a
 * brand-new item. See VocabularyWord for a worked example.
 */
trait HasSpacedRepetition
{
    public function isDue(): bool
    {
        return $this->next_review_at <= now();
    }

    /**
     * True the moment this item is "fresh" — brand new (repetitions is
     * still its default 0) or just knocked back to the start by a
     * failed review — which is exactly when a review flow should ask
     * for the deeper, fully-checked form of practice instead of a
     * quick self-assessment.
     */
    public function needsWrittenReview(): bool
    {
        return $this->repetitions === 0;
    }

    /**
     * $quality is a 0-5 recall score, clamped: <3 is a failed recall
     * (back to day 1, repetitions reset to 0 — see needsWrittenReview());
     * >=3 grows the interval, using ease_factor to adapt per item rather
     * than jumping a fixed amount. A typical review flow maps its own
     * grading modes onto this same scale — e.g. a self-assessment tap
     * (Again/Good/Easy → 1/4/5) once an item has passed at least once,
     * or an AI-checked severity (major/minor/none → 1/4/5) the first
     * time (or right after a failure) — see VocabularyWord's My Words
     * flow for the reference implementation.
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
