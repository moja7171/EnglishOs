<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One completed placement attempt. See App\Services\PlacementTest for
 * what the test is and what its result does (and deliberately doesn't)
 * change.
 */
#[Fillable(['learner_id', 'kind', 'checkpoint_mission_code', 'level', 'recognition_level', 'spoken_level', 'transcript', 'audio_url', 'detail'])]
class PlacementTest extends Model
{
    use HasFactory;

    public const KIND_INITIAL = 'initial';

    public const KIND_CHECKPOINT = 'checkpoint';

    protected function casts(): array
    {
        return [
            'detail' => 'array',
        ];
    }

    public function learner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'learner_id');
    }
}
