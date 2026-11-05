<?php

namespace App\Livewire\Concerns;

use App\Models\VoiceFeedback;
use App\Services\GameVoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Livewire\WithFileUploads;

/**
 * "Feedback on my sentences" for a game page or a Pair Practice round:
 * the learner's own recorded pieces arrive one by one in the background
 * (window.eosGameVoice uploads each into $voiceClip and calls the page's
 * saveVoiceClip()), and once it is over checkVoice() gives the
 * sentence-by-sentence feedback on them — once, under a lock, so both a
 * poll and a second tab can't ask twice. Shares the hourly feedback
 * budget of GivesConversationFeedback.
 */
trait ChecksGameVoice
{
    use GivesConversationFeedback;
    use WithFileUploads;

    /** One recorded piece, sent in the background. */
    public $voiceClip = null;

    /**
     * "Feedback on my sentences" on or off — kept for next time.
     */
    public function setVoiceFeedback(bool $on): void
    {
        auth()->user()->update([$this->voiceSetting() => $on]);
    }

    /**
     * The User column of this page's switch: Pair Practice and the Games
     * page each keep their own.
     */
    abstract protected function voiceSetting(): string;

    protected function keepVoiceClip(Model $subject, string $part, string $label): void
    {
        $this->validate(['voiceClip' => ['required', 'file', 'max:10240']]);

        app(GameVoice::class)->addClip(auth()->user(), $subject, $part, $label, $this->voiceClip);

        $this->voiceClip = null;
    }

    /**
     * @param  string  $situation  What the pieces are, for the AI (see LearnerFeedback::review()).
     */
    protected function checkVoice(Model $subject, string $situation, string $guidance): void
    {
        $voice = VoiceFeedback::existingFor(auth()->user(), $subject);

        if ($voice === null || $voice->isDone()) {
            return;
        }

        Cache::lock('voice-feedback:'.$voice->id, 90)->get(function () use ($voice, $situation, $guidance) {
            $voice->refresh();

            if ($voice->isDone()) {
                return;
            }

            if (! app(GameVoice::class)->hasEnoughToReview($voice)) {
                $voice->update(['status' => VoiceFeedback::STATUS_EMPTY]);

                return;
            }

            $this->requestConversationFeedback(
                '',
                situation: $situation,
                source: 'games',
                spoken: true,
                answers: collect($voice->answers())->map(fn (array $answer) => ['question' => $answer['question'], 'text' => $answer['text']])->all(),
                extraGuidance: $guidance,
            );

            $voice->update($this->feedback !== null
                ? ['status' => VoiceFeedback::STATUS_READY, 'feedback' => $this->feedback]
                : ['status' => VoiceFeedback::STATUS_FAILED, 'error' => $this->feedbackError]);
        });
    }
}
