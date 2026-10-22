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
 * the card is still there when they come back. Checking again replaces it;
 * closing the card deletes it.
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
     * @return BelongsTo<User, $this>
     */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'learner_id');
    }
}
