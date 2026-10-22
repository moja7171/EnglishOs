<?php

namespace App\Models;

use Database\Factories\AiUsageLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One AI request made for a learner — which provider, whose key, how it
 * ended and, inside a mission, which step asked for it. Written by
 * App\Services\AiUsage, read by the admin page (App\Services\AdminInsights).
 * Holds no prompt, reply or key.
 *
 * Rows older than PRUNE_AFTER_DAYS are removed.
 */
#[Fillable(['user_id', 'provider', 'profile', 'key_source', 'outcome', 'failure_kind', 'mission_code', 'step_key', 'created_at'])]
class AiUsageLog extends Model
{
    /** @use HasFactory<AiUsageLogFactory> */
    use HasFactory;

    use Prunable;

    public const UPDATED_AT = null;

    public const PRUNE_AFTER_DAYS = 90;

    public const PROVIDER_GEMINI = 'gemini';

    public const PROVIDER_GROQ = 'groq';

    /** The learner's own key (Profile → AI keys). */
    public const KEY_OWN = 'own';

    /** The app's key. */
    public const KEY_APP = 'app';

    public const OUTCOME_OK = 'ok';

    /** Every model on that key failed. */
    public const OUTCOME_FAILED = 'failed';

    /** Refused by the app's own hourly cap (see GivesConversationFeedback) — no request was made. */
    public const OUTCOME_LIMITED = 'limited';

    /** Refused by the admin's daily cap for this learner — no request was made. */
    public const OUTCOME_BLOCKED = 'blocked';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::PRUNE_AFTER_DAYS));
    }
}
