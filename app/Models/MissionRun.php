<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['learner_id', 'mission_id', 'status', 'started_at', 'completed_at'])]
class MissionRun extends Model
{
    use HasFactory;

    // Mirrors EOS-007 §10 decision states.
    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    public const STATUS_RETRY_EVIDENCE = 'retry_evidence';

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'learner_id');
    }

    /**
     * @return BelongsTo<Mission, $this>
     */
    public function mission(): BelongsTo
    {
        return $this->belongsTo(Mission::class);
    }

    /**
     * @return HasMany<Evidence, $this>
     */
    public function evidence(): HasMany
    {
        return $this->hasMany(Evidence::class);
    }

    /**
     * @return HasMany<ErrorLogItem, $this>
     */
    public function errorLogItems(): HasMany
    {
        return $this->hasMany(ErrorLogItem::class);
    }

    /**
     * @return HasMany<SelfAssessment, $this>
     */
    public function selfAssessments(): HasMany
    {
        return $this->hasMany(SelfAssessment::class);
    }

    /**
     * @return HasOne<Reflection, $this>
     */
    public function reflection(): HasOne
    {
        return $this->hasOne(Reflection::class);
    }

    /**
     * The next step key the learner must complete, in mission order.
     * Null once every step has recorded Evidence (EOS-003 §7: no
     * skipping ahead without Evidence for the current step).
     */
    public function currentStepKey(): ?string
    {
        $recorded = $this->evidence()->pluck('phase')->all();

        foreach ($this->mission->stepKeys() as $key) {
            if (! in_array($key, $recorded, true)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Finds the learner's in-progress run for this mission, or starts one.
     */
    public static function findOrStart(User $learner, Mission $mission): self
    {
        return static::firstOrCreate(
            [
                'learner_id' => $learner->id,
                'mission_id' => $mission->id,
                'status' => self::STATUS_IN_PROGRESS,
            ],
            ['started_at' => now()]
        );
    }
}
