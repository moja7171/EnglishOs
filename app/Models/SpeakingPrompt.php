<?php

namespace App\Models;

use App\Models\Concerns\HasSpacedRepetition;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['learner_id', 'source_mission_run_id', 'mission_code', 'prompt', 'last_recording_url', 'ease_factor', 'interval_days', 'repetitions', 'next_review_at', 'last_reviewed_at'])]
class SpeakingPrompt extends Model
{
    use HasFactory;
    use HasSpacedRepetition;

    /**
     * Explicit PHP-side defaults, not left to the migration's DB column
     * defaults — same Postgres/Eloquent hydration gap already documented
     * on VocabularyWord: a freshly created instance (firstOrCreate() in
     * Mission Result's addPromptsToSpeakingRecall()) would otherwise read
     * these as null in the very same request instead of their real value.
     */
    protected $attributes = [
        'ease_factor' => 2.5,
        'interval_days' => 0,
        'repetitions' => 0,
    ];

    protected function casts(): array
    {
        return [
            'ease_factor' => 'float',
            'next_review_at' => 'datetime',
            'last_reviewed_at' => 'datetime',
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
     * @return BelongsTo<MissionRun, $this>
     */
    public function sourceMissionRun(): BelongsTo
    {
        return $this->belongsTo(MissionRun::class, 'source_mission_run_id');
    }
}
