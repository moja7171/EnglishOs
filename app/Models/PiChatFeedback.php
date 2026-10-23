<?php

namespace App\Models;

use Database\Factories\PiChatFeedbackFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The last Pi chat the learner pasted on the /pi page for one mission day,
 * with the feedback it got (a LearnerFeedback::review() result) — kept so
 * the card is still there when they come back. Checking again replaces it.
 * Closing the card deletes it, unless the feedback saw the practice done:
 * then only the chat and the feedback go, and the row stays as that day's
 * tick (see close()).
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
     * Whether the feedback saw the chat really do the day's plan.
     */
    public function isPracticeDone(): bool
    {
        return ($this->feedback['practice_done'] ?? false) === true;
    }

    /**
     * Whether the learner closed the card, leaving only the day's tick.
     */
    public function isClosed(): bool
    {
        return $this->chat === '';
    }

    /**
     * Closing the card: forget the chat and its feedback, but keep a done
     * practice as the day's tick.
     */
    public function close(): void
    {
        if (! $this->isPracticeDone()) {
            $this->delete();

            return;
        }

        $this->update(['chat' => '', 'feedback' => ['practice_done' => true]]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'learner_id');
    }
}
