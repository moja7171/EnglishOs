<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The learner's own "I practiced" tick for the daily voice practice with
 * Pi that lives outside the app (see App\Services\PiPractice). Like
 * ListeningLog it is a self-report, not Evidence, and User::activeDates()
 * counts these days toward the streak too — each tick is filed separately
 * from the listening tick, and either one alone keeps the streak alive.
 */
#[Fillable(['learner_id', 'practiced_on', 'mission_code', 'day_number'])]
class PiPracticeLog extends Model
{
    use HasFactory;

    // practiced_on is deliberately a plain 'Y-m-d' string, not a 'date'
    // cast, for the same reason as ListeningLog::listened_on: a cast would
    // break the de-duplicating UNION in User::activeDates() on SQLite.

    public function learner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'learner_id');
    }
}
