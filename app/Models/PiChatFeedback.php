<?php

namespace App\Models;

use Database\Factories\PiChatFeedbackFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A mission day's Pi practice kept apart from the one-row-per-date log:
 * the chats learners once pasted for feedback, and the tick of a second
 * day done on one date (see User::recordPiPracticeToday()). Only its
 * "practice_done" flag still matters; the app no longer checks chats.
 */
#[Fillable(['learner_id', 'mission_code', 'day_number', 'chat', 'feedback'])]
class PiChatFeedback extends Model
{
    /** @use HasFactory<PiChatFeedbackFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'feedback' => 'array',
        ];
    }

    /**
     * Whether this row ticks its day.
     */
    public function isPracticeDone(): bool
    {
        return ($this->feedback['practice_done'] ?? false) === true;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'learner_id');
    }
}
