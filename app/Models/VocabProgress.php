<?php

namespace App\Models;

use App\Models\Concerns\HasSpacedRepetition;
use Database\Factories\VocabProgressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One review card of the Vocab in Use course: a whole sentence of the book
 * the learner saved a word from, with its own spaced-repetition schedule.
 * Words sharing a sentence share the card. Kept apart from the Review tab
 * and the Notebook on purpose.
 */
#[Fillable(['user_id', 'vocab_text_id', 'sentence_index', 'saved_item_ids', 'ladder_step', 'lapses', 'misses_in_row', 'ease_factor', 'interval_days', 'repetitions', 'next_review_at', 'last_reviewed_at'])]
class VocabProgress extends Model
{
    /** @use HasFactory<VocabProgressFactory> */
    use HasFactory;

    use HasSpacedRepetition {
        scheduleAfter as private sm2ScheduleAfter;
    }

    /**
     * The first reviews follow the book's own advice (Unit 1): a sentence
     * comes back a day after it was saved, then after 3, 7 and 14 days.
     * Only after that does it follow its own SM-2 schedule.
     */
    public const FIRST_INTERVALS = [1 => 3, 2 => 7, 3 => 14];

    /** Wrong answers in a row that put a sentence on the tricky list. */
    public const TRICKY_AFTER = 4;

    public const LADDER_STEPS = 4;

    protected $table = 'vocab_progress';

    protected $attributes = [
        'saved_item_ids' => '[]',
        'ladder_step' => 1,
        'lapses' => 0,
        'misses_in_row' => 0,
        'ease_factor' => 2.5,
        'interval_days' => 0,
        'repetitions' => 0,
    ];

    protected function casts(): array
    {
        return [
            'saved_item_ids' => 'array',
            'ease_factor' => 'float',
            'next_review_at' => 'datetime',
            'last_reviewed_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<VocabProgress>  $query
     * @return Builder<VocabProgress>
     */
    public function scopeDue(Builder $query): Builder
    {
        return $query->where('next_review_at', '<=', now());
    }

    public function isTricky(): bool
    {
        return $this->misses_in_row >= self::TRICKY_AFTER;
    }

    /**
     * The fixed 1-3-7-14 days first, then SM-2 (see HasSpacedRepetition).
     * A failed answer starts over from tomorrow.
     *
     * @return array{repetitions: int, interval_days: int, ease_factor: float}
     */
    public function scheduleAfter(int $quality): array
    {
        $schedule = $this->sm2ScheduleAfter($quality);

        if ($quality < 3 || ! isset(self::FIRST_INTERVALS[$schedule['repetitions']])) {
            return $schedule;
        }

        $days = self::FIRST_INTERVALS[$schedule['repetitions']];
        $schedule['interval_days'] = $quality === 5 ? (int) round($days * 1.3) : $days;

        return $schedule;
    }

    /**
     * Applies one review. Right moves the sentence one step up the ladder
     * (See, Gap, Order, Recall) and clears its misses; wrong moves it one
     * step down and counts a miss. Only the course's own schedule changes:
     * a review here never touches the streak or the daily ring.
     */
    public function grade(int $quality): void
    {
        $schedule = $this->scheduleAfter($quality);
        $right = $quality >= 3;

        $this->forceFill([
            'repetitions' => $schedule['repetitions'],
            'interval_days' => $schedule['interval_days'],
            'ease_factor' => $schedule['ease_factor'],
            'ladder_step' => $right ? min(self::LADDER_STEPS, $this->ladder_step + 1) : max(1, $this->ladder_step - 1),
            'misses_in_row' => $right ? 0 : $this->misses_in_row + 1,
            'lapses' => $right ? $this->lapses : $this->lapses + 1,
            'last_reviewed_at' => now(),
            'next_review_at' => now()->addDays($schedule['interval_days']),
        ])->save();
    }

    /**
     * @return BelongsTo<VocabText, $this>
     */
    public function text(): BelongsTo
    {
        return $this->belongsTo(VocabText::class, 'vocab_text_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
