<?php

namespace App\Models;

use App\Services\DayFinishedAlerts;
use App\Services\FinishLineAlerts;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Throwable;

#[Fillable(['mission_run_id', 'phase', 'type', 'content_ref'])]
class Evidence extends Model
{
    use HasFactory;

    // "evidence" is uncountable, so Eloquent's pluralized-table guess
    // ("evidence") is wrong — the migration created "evidences".
    protected $table = 'evidences';

    public const TYPE_AUDIO = 'audio';

    public const TYPE_TEXT = 'text';

    public const TYPE_TRANSCRIPT = 'transcript';

    public const TYPE_SCORE = 'score';

    /**
     * A new answer may finish its mission day, which tells the learner's
     * friends (see DayFinishedAlerts) and the learner their finish date
     * (see FinishLineAlerts). A failure there never blocks saving the
     * learner's answer.
     */
    protected static function booted(): void
    {
        static::created(function (Evidence $evidence): void {
            if (! $evidence->completesItsDay()) {
                return;
            }

            foreach ([DayFinishedAlerts::class, FinishLineAlerts::class] as $alerts) {
                try {
                    app($alerts)->evidenceRecorded($evidence);
                } catch (Throwable $e) {
                    report($e);
                }
            }
        });
    }

    /**
     * This answer is the first-ever one to its step and that step was the
     * last one missing from its mission day.
     */
    public function completesItsDay(): bool
    {
        $run = $this->missionRun;

        if (! $run) {
            return false;
        }

        $isFirstAnswer = static::where('mission_run_id', $this->mission_run_id)
            ->where('phase', $this->phase)
            ->count() === 1;

        if (! $isFirstAnswer) {
            return false;
        }

        $day = collect($run->dayProgress())
            ->first(fn (array $day) => in_array($this->phase, $day['stepKeys'], true));

        return $day !== null && $day['done'];
    }

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
