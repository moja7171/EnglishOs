<?php

namespace App\Models;

use Database\Factories\StepDraftFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A step's work so far — answers, AI results, recordings — kept on the
 * server as it happens (see KeepsStepDraft), so a learner who leaves the
 * step comes back to it. It is not Evidence: the step's save turns it
 * into Evidence and deletes the row. `paths` lists the recordings it kept
 * on the public disk.
 *
 * A draft nobody saves is removed after PRUNE_AFTER_DAYS, files and all.
 */
#[Fillable(['mission_run_id', 'phase', 'data', 'paths'])]
class StepDraft extends Model
{
    /** @use HasFactory<StepDraftFactory> */
    use HasFactory;

    use Prunable;

    public const PRUNE_AFTER_DAYS = 7;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'paths' => 'array',
        ];
    }

    /**
     * @return BelongsTo<MissionRun, $this>
     */
    public function missionRun(): BelongsTo
    {
        return $this->belongsTo(MissionRun::class);
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::where('updated_at', '<', now()->subDays(self::PRUNE_AFTER_DAYS));
    }

    protected function pruning(): void
    {
        Storage::disk('public')->delete($this->paths ?? []);
    }
}
