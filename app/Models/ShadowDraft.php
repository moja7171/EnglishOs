<?php

namespace App\Models;

use Database\Factories\ShadowDraftFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A shadowing recording kept on the server the moment it is made (see
 * KeepsShadowDrafts), so a learner who leaves the step finds it again. It
 * is not Evidence: the step's save turns it into Evidence and deletes the
 * row. `feedback` is the AI verdict on it, where the step has one.
 *
 * A draft nobody saves is removed after PRUNE_AFTER_DAYS, file and all.
 */
#[Fillable(['mission_run_id', 'phase', 'line_index', 'path', 'feedback'])]
class ShadowDraft extends Model
{
    /** @use HasFactory<ShadowDraftFactory> */
    use HasFactory;

    use Prunable;

    public const PRUNE_AFTER_DAYS = 7;

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
     * @return BelongsTo<MissionRun, $this>
     */
    public function missionRun(): BelongsTo
    {
        return $this->belongsTo(MissionRun::class);
    }

    public function url(): string
    {
        return Storage::disk('public')->url($this->path);
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
        Storage::disk('public')->delete($this->path);
    }
}
