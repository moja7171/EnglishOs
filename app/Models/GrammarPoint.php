<?php

namespace App\Models;

use App\Models\Concerns\HasSpacedRepetition;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['learner_id', 'source_mission_run_id', 'mission_code', 'focus', 'example_sentence', 'rule_reminder', 'ease_factor', 'interval_days', 'repetitions', 'next_review_at', 'last_reviewed_at'])]
class GrammarPoint extends Model
{
    use HasFactory;
    use HasSpacedRepetition;

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

    public function learner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'learner_id');
    }

    public function sourceMissionRun(): BelongsTo
    {
        return $this->belongsTo(MissionRun::class, 'source_mission_run_id');
    }
}
