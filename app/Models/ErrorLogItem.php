<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['mission_run_id', 'error', 'correction', 'new_example', 'drills', 'category'])]
class ErrorLogItem extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'drills' => 'array',
        ];
    }

    /**
     * @return BelongsTo<MissionRun, $this>
     */
    public function missionRun(): BelongsTo
    {
        return $this->belongsTo(MissionRun::class);
    }
}
