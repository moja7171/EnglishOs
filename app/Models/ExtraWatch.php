<?php

namespace App\Models;

use Database\Factories\ExtraWatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One learner watched one Extra English episode: a tick with no order. Watching
 * earns the day's Extra English slice of the ring (see StreakRing) and shows in
 * the Progress page and in Friends; it does not touch the reminders.
 */
#[Fillable(['user_id', 'extra_episode_id', 'watched_at'])]
class ExtraWatch extends Model
{
    /** @use HasFactory<ExtraWatchFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['watched_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<ExtraEpisode, $this>
     */
    public function episode(): BelongsTo
    {
        return $this->belongsTo(ExtraEpisode::class, 'extra_episode_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
