<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['mission_id', 'step_key', 'user_a_id', 'user_b_id'])]
class PartnerSession extends Model
{
    use HasFactory;

    /**
     * @return BelongsTo<Mission, $this>
     */
    public function mission(): BelongsTo
    {
        return $this->belongsTo(Mission::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function userA(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_a_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function userB(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_b_id');
    }

    /**
     * @return HasMany<PartnerSessionAnswer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(PartnerSessionAnswer::class);
    }

    public function isAccessibleBy(User $user): bool
    {
        return $user->is($this->userA) || $user->is($this->userB);
    }

    /**
     * The OTHER participant, from $user's point of view. Only meaningful
     * when $user is actually part of this session — callers check
     * isAccessibleBy() first.
     */
    public function partnerFor(User $user): User
    {
        return $user->is($this->userA) ? $this->userB : $this->userA;
    }

    /**
     * Finds the one shared session for this mission+step+pair, or starts
     * it — order-independent (either friend can be the one who clicks
     * first), via a normalized low/high user id pair so both people always
     * land on the exact same session, never two duplicate ones.
     */
    public static function findOrStartFor(Mission $mission, string $stepKey, User $a, User $b): self
    {
        [$lowId, $highId] = $a->id < $b->id ? [$a->id, $b->id] : [$b->id, $a->id];

        return static::firstOrCreate([
            'mission_id' => $mission->id,
            'step_key' => $stepKey,
            'user_a_id' => $lowId,
            'user_b_id' => $highId,
        ]);
    }
}
