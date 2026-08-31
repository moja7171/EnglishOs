<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['mission_run_id', 'phase', 'type', 'content_ref'])]
class Evidence extends Model
{
    use HasFactory;

    public const TYPE_AUDIO = 'audio';

    public const TYPE_TEXT = 'text';

    public const TYPE_TRANSCRIPT = 'transcript';

    public const TYPE_SCORE = 'score';

    /**
     * @return BelongsTo<MissionRun, $this>
     */
    public function missionRun(): BelongsTo
    {
        return $this->belongsTo(MissionRun::class);
    }

    /**
     * @return HasOne<AIFeedback, $this>
     */
    public function aiFeedback(): HasOne
    {
        return $this->hasOne(AIFeedback::class);
    }
}
