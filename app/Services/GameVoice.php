<?php

namespace App\Services;

use App\Models\User;
use App\Models\VoiceFeedback;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The voice side of games and Pair Practice: each phone records only its
 * owner's own speaking time and sends every piece quietly once it ends;
 * here it is kept (private disk) and turned into text right away, so the
 * feedback at the end only has to read the transcripts.
 */
class GameVoice
{
    /** Fewer words than this in all of a learner's pieces: nothing to give feedback on. */
    public const MIN_WORDS = 6;

    /**
     * Keeps one piece. $part names the moment it is from ("turn-2",
     * "speaking-5"), so a piece sent twice on a weak connection is kept
     * once; $label is what was on the card. Nothing is kept once the
     * feedback was given.
     */
    public function addClip(User $learner, Model $subject, string $part, ?string $label, UploadedFile $file): void
    {
        $voice = VoiceFeedback::for($learner, $subject);

        if ($voice->isDone() || collect($voice->clips)->contains('part', $part)) {
            return;
        }

        $path = $file->store('voice-feedback/'.$voice->id, VoiceFeedback::DISK);

        try {
            $transcript = trim(app(GroqClient::class)->transcribe(Storage::disk(VoiceFeedback::DISK)->path($path)));
        } catch (Throwable) {
            $transcript = null;
        }

        DB::transaction(function () use ($voice, $part, $label, $path, $transcript) {
            $locked = VoiceFeedback::lockForUpdate()->findOrFail($voice->id);

            if (collect($locked->clips)->contains('part', $part)) {
                Storage::disk(VoiceFeedback::DISK)->delete($path);

                return;
            }

            $locked->update(['clips' => [...$locked->clips, [
                'part' => $part,
                'label' => $label,
                'path' => $path,
                'transcript' => $transcript === '' ? null : $transcript,
            ]]]);
        });
    }

    public function hasEnoughToReview(VoiceFeedback $voice): bool
    {
        return str_word_count(collect($voice->answers())->pluck('text')->implode(' ')) >= self::MIN_WORDS;
    }
}
