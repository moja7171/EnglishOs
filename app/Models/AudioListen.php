<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One completed listen of an in-app recording or video: the player saw at
 * least 90% of it play through, not skipped to (see eosListenTracker in
 * resources/js/app.js). `source` is the mission step the media belongs to,
 * one of SOURCES.
 *
 * `day_number` is the mission day the listen happened on. A daily pick on
 * /listening (SOURCE_LISTENING_PICK) also has a `pick_key`, see pickKey();
 * a pick that plays outside the app can only be self_reported.
 */
#[Fillable(['learner_id', 'mission_code', 'source', 'day_number', 'pick_key', 'self_reported'])]
class AudioListen extends Model
{
    use HasFactory;

    public const SOURCE_LISTENING = 'listening';

    public const SOURCE_VIDEO_SHADOWING = 'video_shadowing';

    public const SOURCE_LISTENING_PICK = 'listening_pick';

    public const SOURCES = [self::SOURCE_LISTENING, self::SOURCE_VIDEO_SHADOWING, self::SOURCE_LISTENING_PICK];

    /**
     * How many listens of one recording the program is built around: the
     * same episode comes back on all four days of its mission.
     */
    public const TARGET = 4;

    /**
     * The fraction of a recording's own length that must pass before the
     * next listen of it can count. A real listen needs 90% of the audio
     * played, which at the fastest speed (1.25x) is about 72% of its
     * length in real time — so anything quicker is a duplicate or a faked
     * call, but a genuine back-to-back listen at top speed still counts.
     */
    public const MIN_GAP_FRACTION = 0.7;

    /**
     * Two self-reports of the same pick closer together than this are one
     * accidental double tap.
     */
    public const SELF_REPORT_GAP_SECONDS = 60;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['self_reported' => 'boolean'];
    }

    /**
     * The identity of one of a day's three picks (level 0-2).
     */
    public static function pickKey(int $dayNumber, int $level): string
    {
        return "{$dayNumber}-{$level}";
    }

    public function learner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'learner_id');
    }
}
