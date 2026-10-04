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
 * as null in the very same request/test instead of their real value.
 * See VocabularyWord for a worked example.
 */
trait HasSpacedRepetition
{
    public function isDue(): bool
    {
        return $this->next_review_at <= now();
    }

    /**
     * A 0-100 "memory freshness" indicator — 100 right after a review,
     * decaying to 0 by twice the current interval overdue (Duolingo-style
     * skill-strength decay). Deliberately NOT tied to isDue(): an item can
     * read as, say, 40% fresh well before its next_review_at arrives,
     * since decay is continuous while isDue() is a hard cutoff. Only
     * meaningful once repetitions > 0 — a brand-new item hasn't started
     * decaying yet, so this returns 100 for
     * it rather than a number that would misleadingly suggest otherwise.
     */
    public function freshness(): int
    {
        if ($this->repetitions === 0 || ! $this->last_reviewed_at) {
            return 100;
        }

        $elapsedDays = $this->last_reviewed_at->diffInDays(now());
        $decayWindow = max(1, $this->interval_days * 2);

        return (int) max(0, round(100 - ($elapsedDays / $decayWindow) * 100));
    }

    /**
     * What the schedule would become after a review graded $quality
     * (0-5, clamped), without touching the model — review() applies it,
     * and the review card calls it to print "Good · 6d" on its buttons.
     * <3 is a failed recall (back to day 1, repetitions reset to 0); >=3
     * grows the interval, using ease_factor to adapt per item rather than
     * jumping a fixed amount. A 5 ("Easy") earns an Anki-style bonus on
     * top (first review 3 days instead of 1, second 8 instead of 6, then
     * x1.3) so the Good and Easy buttons genuinely lead to different
     * dates — before the bonus they always produced the same interval and
     * only differed in ease_factor.
     *
     * @return array{repetitions: int, interval_days: int, ease_factor: float}
     */
    public function scheduleAfter(int $quality): array
    {
        $quality = max(0, min(5, $quality));
        $repetitions = $this->repetitions;
        $interval = $this->interval_days;

        if ($quality < 3) {
            $repetitions = 0;
            $interval = 1;
        } else {
            $repetitions++;
            $interval = match ($repetitions) {
                1 => 1,
                2 => 6,
                default => (int) round($interval * $this->ease_factor),
            };

            if ($quality === 5) {
                $interval = match ($repetitions) {
                    1 => 3,
                    2 => 8,
                    default => (int) round($interval * 1.3),
                };
            }
        }

        return [
            'repetitions' => $repetitions,
            'interval_days' => $interval,
            'ease_factor' => max(1.3, $this->ease_factor + (0.1 - (5 - $quality) * (0.08 + (5 - $quality) * 0.02))),
        ];
    }

    /**
     * $quality is a 0-5 recall score — see scheduleAfter() for how it moves
     * the schedule. A typical review flow maps its own grading modes onto
     * this same scale — e.g. a self-assessment tap (Again/Good/Easy →
     * 1/4/5) — see VocabularyWord's My Words flow for the reference
     * implementation.
     */
    public function review(int $quality): void
    {
        $schedule = $this->scheduleAfter($quality);

        $this->repetitions = $schedule['repetitions'];
        $this->interval_days = $schedule['interval_days'];
        $this->ease_factor = $schedule['ease_factor'];
        $this->last_reviewed_at = now();
        $this->next_review_at = now()->addDays($this->interval_days);
        $this->save();
    }

    /**
     * The gap a review graded $quality would schedule, as a short label
     * for a grade button: "1d", "6d", "3mo", "1y".
     */
    public function nextIntervalLabel(int $quality): string
    {
        $days = $this->scheduleAfter($quality)['interval_days'];

        return match (true) {
            $days < 30 => "{$days}d",
            $days < 365 => max(1, (int) round($days / 30)).'mo',
            default => max(1, (int) round($days / 365)).'y',
        };
    }

    /**
     * 0-5 "how well do I know this" level for the five-dot strength
     * indicator: 0 for something never reviewed or just forgotten, then
     * climbing with the current interval (a longer gap only ever happens
     * after repeated successful recalls).
     */
    public function strengthLevel(): int
    {
        if ($this->repetitions === 0 || ! $this->last_reviewed_at) {
            return 0;
        }

        return match (true) {
            $this->interval_days < 2 => 1,
            $this->interval_days < 7 => 2,
            $this->interval_days < 21 => 3,
            $this->interval_days < 60 => 4,
            default => 5,
        };
    }

    public function strengthLabel(): string
    {
        return match (true) {
            ! $this->last_reviewed_at => 'New',
            $this->repetitions === 0 => 'Relearning',
            $this->strengthLevel() <= 2 => 'Learning',
            $this->strengthLevel() === 3 => 'Familiar',
            $this->strengthLevel() === 4 => 'Strong',
            default => 'Known',
        };
    }
}
