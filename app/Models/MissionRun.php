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
     * The most recent Evidence row for a given step, if any. Step
     * components use this to reload what the learner already submitted
     * when reviewing a completed step in read-only mode.
     */
    public function latestEvidence(string $phase): ?Evidence
    {
        return $this->evidence()->where('phase', $phase)->latest()->first();
    }

    /**
     * The 1-5 comfort score the learner gave themselves at Mission Brief,
     * before starting — null if they haven't reached/answered it yet.
     */
    public function startingConfidence(): ?int
    {
        $evidence = $this->latestEvidence('mission_brief');

        return $evidence ? (int) $evidence->content_ref : null;
    }

    /**
     * Extra guidance appended to AI Instructor conversation prompts so the
     * tone adapts to how confident the learner said they felt — never
     * gating or skipping anything (Article 3, Evidence Before Progress:
     * only real Evidence decides progress), just how warm or how much the
     * AI pushes in follow-up questions (EOS-000 Principle 7: the system
     * should adapt itself to the learner without lowering quality).
     */
    public function aiToneGuidance(): string
    {
        return match (true) {
            $this->startingConfidence() === null => '',
            $this->startingConfidence() <= 2 => ' The learner rated their starting confidence on this topic low '
                .'(1-2 out of 5) — be extra warm and encouraging, and keep your follow-up simple and easy to answer.',
            $this->startingConfidence() >= 4 => ' The learner rated their starting confidence on this topic high '
                .'(4-5 out of 5) — you can make your follow-up a little more challenging or probing.',
            default => '',
        };
    }

    /**
     * Finds the learner's run for this mission — whatever its status — or
     * starts a new one. Keying only on learner+mission (not status) matters:
     * once a run is complete, revisiting the mission must show that same
     * completed run, not silently spawn a fresh empty one.
     */
    public static function findOrStart(User $learner, Mission $mission): self
    {
        return static::firstOrCreate(
            [
                'learner_id' => $learner->id,
                'mission_id' => $mission->id,
            ],
            ['status' => self::STATUS_IN_PROGRESS, 'started_at' => now()]
        );
    }
}
