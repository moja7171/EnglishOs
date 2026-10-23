<?php

namespace App\Models;

use App\Services\StreakRing;
use Database\Factories\DayActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One slice of a learner's daily streak ring: something they finished on
 * one of their local days ("step:12:listening_1", "review", "listening",
 * "pi", "pair"). Unique per learner, day and activity, so doing the same
 * thing twice in a day never counts twice. See App\Services\StreakRing.
 */
#[Fillable(['learner_id', 'on_date', 'activity'])]
class DayActivity extends Model
{
    /** @use HasFactory<DayActivityFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::created(fn (DayActivity $slice) => app(StreakRing::class)->forget($slice->learner_id));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'on_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'learner_id');
    }
}
