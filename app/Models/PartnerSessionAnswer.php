<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'partner_session_id', 'question_index', 'responder_id', 'type', 'body',
    'attachment_path', 'attachment_name', 'attachment_mime',
])]
class PartnerSessionAnswer extends Model
{
    use HasFactory;

    public const TYPE_TEXT = 'text';

    public const TYPE_VOICE = 'voice';

    /**
     * @return BelongsTo<PartnerSession, $this>
     */
    public function partnerSession(): BelongsTo
    {
        return $this->belongsTo(PartnerSession::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responder_id');
    }

    public function hasAttachment(): bool
    {
        return $this->attachment_path !== null;
    }

    public function isAccessibleBy(User $user): bool
    {
        return $this->partnerSession->isAccessibleBy($user);
    }
}
