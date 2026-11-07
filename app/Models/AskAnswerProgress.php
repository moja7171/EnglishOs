<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which Ask & Answer situations a learner has played, so a new game takes
 * the ones neither friend has seen first, and the ones said wrong come back.
 */
#[Fillable(['user_id', 'ask_answer_item_id', 'times_seen', 'missed', 'last_seen_at'])]
class AskAnswerProgress extends Model
{
    protected $table = 'ask_answer_progress';

    protected function casts(): array
    {
        return [
            'times_seen' => 'integer',
            'missed' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AskAnswerItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(AskAnswerItem::class, 'ask_answer_item_id');
    }
}
