<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The learner's own "I listened" tick for the daily listening picks that
 * live outside the app (see App\Services\ListeningPicks). It is a
 * self-report, not Evidence — there is nothing to attach — so it is a
 * deliberate, documented exception to the "streak is built from real
 * Evidence" rule: User::activeDates() counts these days too, because the
 * product decision was that the daily listening habit should keep the
 * streak alive.
 */
#[Fillable(['learner_id', 'listened_on', 'mission_code', 'day_number'])]
class ListeningLog extends Model
{
    use HasFactory;

    // listened_on is deliberately a plain 'Y-m-d' string, not a 'date'
    // cast: a cast stores "Y-m-d 00:00:00" on SQLite, which then neither
    // matches a 'Y-m-d' lookup nor de-duplicates against Evidence's
    // DATE(created_at) inside User::activeDates()'s UNION.

    public function learner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'learner_id');
    }
}
