<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'learner_id', 'mission_run_id', 'step_key', 'role', 'body', 'type',
    'attachment_path', 'attachment_name', 'attachment_mime',
])]
class InstructorMessage extends Model
{
    use HasFactory;

    public const ROLE_LEARNER = 'learner';

    public const ROLE_INSTRUCTOR = 'instructor';

    public const TYPE_TEXT = 'text';

    public const TYPE_VOICE = 'voice';

    public const TYPE_FILE = 'file';

    /**
     * @return BelongsTo<User, $this>
     */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'learner_id');
    }

    /**
     * @return BelongsTo<MissionRun, $this>
     */
    public function missionRun(): BelongsTo
    {
        return $this->belongsTo(MissionRun::class);
    }

    public function hasAttachment(): bool
    {
        return $this->attachment_path !== null;
    }

    /**
     * Unlike a DirectMessage (shared between two people), this is private
     * to the one learner it belongs to — never the AI "sender", never
     * anyone else.
     */
    public function isAccessibleBy(User $user): bool
    {
        return $user->is($this->learner);
    }
}
