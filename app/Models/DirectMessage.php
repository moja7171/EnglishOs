<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'sender_id', 'recipient_id', 'type', 'body', 'read_at',
    'attachment_path', 'attachment_name', 'attachment_mime',
])]
class DirectMessage extends Model
{
    use HasFactory;

    public const TYPE_MESSAGE = 'message';

    public const TYPE_NUDGE = 'nudge';

    public const TYPE_AUDIO = 'audio';

    public const TYPE_FILE = 'file';

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function hasAttachment(): bool
    {
        return $this->attachment_path !== null;
    }

    /**
     * Only the sender or recipient of THIS specific message may ever read
     * its attachment — checked by the friends.attachment route before it
     * streams anything, since the file lives on the private disk, not a
     * guessable public URL.
     */
    public function isAccessibleBy(User $user): bool
    {
        return $user->is($this->sender) || $user->is($this->recipient);
    }
}
