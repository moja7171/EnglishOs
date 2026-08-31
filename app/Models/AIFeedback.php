<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['evidence_id', 'strength', 'correction', 'tone'])]
class AIFeedback extends Model
{
    use HasFactory;

    // Consecutive capitals ("AIFeedback") snake-case to "a_i_feedback" —
    // the migration created "ai_feedbacks". Same class of bug as Evidence.
    protected $table = 'ai_feedbacks';

    /**
     * @return BelongsTo<Evidence, $this>
     */
    public function evidence(): BelongsTo
    {
        return $this->belongsTo(Evidence::class);
    }
}
