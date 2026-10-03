<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['reporter_id', 'reported_id', 'category', 'reason', 'message_snapshot'])]
class FriendReport extends Model
{
    use HasFactory;

    /**
     * Preset reasons shown as one-tap chips — an English free-text box is a
     * hurdle for a beginner who has a real complaint. `reason` still holds the
     * optional details (or the category label when none were given).
     *
     * @var array<string, string>
     */
    public const CATEGORIES = [
        'rude' => 'Rude or hurtful',
        'spam' => 'Spam',
        'inappropriate' => 'Inappropriate',
        'other' => 'Something else',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reported(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_id');
    }
}
