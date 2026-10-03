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
 */
#[Fillable(['learner_id', 'mission_code', 'source'])]
class AudioListen extends Model
{
    use HasFactory;

    public const SOURCE_LISTENING = 'listening';

    public const SOURCE_VIDEO_SHADOWING = 'video_shadowing';

    public const SOURCES = [self::SOURCE_LISTENING, self::SOURCE_VIDEO_SHADOWING];

    public function learner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'learner_id');
    }
}
