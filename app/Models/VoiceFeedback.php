<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

/**
 * One learner's voice in one game (PairGame) or Pair Practice round
 * (PairPracticeSession): the pieces their own phone recorded — only their
 * own speaking time — with transcripts, and once it is over the
 * sentence-by-sentence feedback (a LearnerFeedback::review() result).
 * Kept for a week, audio included.
 */
#[Fillable(['learner_id', 'subject_type', 'subject_id', 'clips', 'status', 'feedback', 'error'])]
class VoiceFeedback extends Model
{
    use Prunable;

    protected $table = 'voice_feedback';

    public const PRUNE_AFTER_DAYS = 7;

    public const DISK = 'local';

    /** Pieces arriving while the game goes on. */
    public const STATUS_RECORDING = 'recording';

    public const STATUS_READY = 'ready';

    /** Nothing long enough to give feedback on. */
    public const STATUS_EMPTY = 'empty';

    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'clips' => 'array',
            'feedback' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'learner_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public static function for(User $learner, Model $subject): self
    {
        return self::firstOrCreate(
            ['learner_id' => $learner->id, 'subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->getKey()],
            ['clips' => [], 'status' => self::STATUS_RECORDING],
        );
    }

    public static function existingFor(User $learner, Model $subject): ?self
    {
        return self::where('learner_id', $learner->id)
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->first();
    }

    public function isDone(): bool
    {
        return in_array($this->status, [self::STATUS_READY, self::STATUS_EMPTY, self::STATUS_FAILED], true);
    }

    /**
     * The pieces with something said, as LearnerFeedback answers: what was
     * on the card (question), the transcript (text) and where to hear it.
     *
     * @return list<array{question: ?string, text: string, audio: string}>
     */
    public function answers(): array
    {
        return collect($this->clips ?? [])
            ->filter(fn (array $clip) => filled($clip['transcript'] ?? null))
            ->map(fn (array $clip, int $index) => [
                'question' => $clip['label'] ?? null,
                'text' => $clip['transcript'],
                'audio' => route('voice-feedback.clip', ['voiceFeedback' => $this, 'clip' => $index]),
            ])
            ->values()
            ->all();
    }

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::PRUNE_AFTER_DAYS));
    }

    protected function pruning(): void
    {
        foreach ($this->clips ?? [] as $clip) {
            Storage::disk(self::DISK)->delete($clip['path']);
        }
    }
}
