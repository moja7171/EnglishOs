<?php

use App\Livewire\Concerns\TracksCheckAttempts;
use App\Models\Evidence;
use App\Models\MissionRun;
use App\Services\SentenceChecker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;
    use TracksCheckAttempts;

    public MissionRun $run;

    public bool $readOnly = false;

    /** @var array<int, string> */
    public array $sentences = ['', '', '', '', ''];

    /** @var array<int, array{severity: string, hint: string, checkedText: string}> keyed by sentence index */
    public array $feedback = [];

    /** @var array<int, string> keyed by sentence index — per-input check failure message */
    public array $checkErrors = [];

    public ?UploadedFile $audioFile = null;

    public ?string $savedAudioUrl = null;

    public function mount(): void
    {
        if (! $this->readOnly) {
            return;
        }

        $textEvidence = $this->run->evidence()->where('phase', 'activation')->where('type', Evidence::TYPE_TEXT)->latest()->first();
        $this->sentences = array_pad(json_decode($textEvidence?->content_ref ?? '[]', true), 5, '');

        $audioEvidence = $this->run->evidence()->where('phase', 'activation')->where('type', Evidence::TYPE_AUDIO)->latest()->first();
        $this->savedAudioUrl = $audioEvidence?->content_ref;
    }

    public function checkOne(int $index): void
    {
        $sentence = trim($this->sentences[$index] ?? '');

        if ($sentence === '') {
            $this->checkErrors[$index] = 'Write something first.';

            return;
        }

        $this->runCheck($index, $sentence);
    }

    /**
     * Asks the shared SentenceChecker to judge one personal sentence,
     * storing the verdict tagged with the exact text it applies to, so a
     * later edit doesn't leave a stale verdict attached to different text.
     * See EOS-009 §8 "الگوی چک جمله" for the shared rules.
     */
    private function runCheck(int $index, string $sentence): void
    {
        unset($this->checkErrors[$index]);

        try {
            $data = app(SentenceChecker::class)->check(
                judgment: 'Judge whether the learner wrote a genuine, natural personal sentence about their own '
                    .'daily life.',
                majorCriteria: 'it is just a fragment (not a real sentence), or it is not actually about the '
                    .'learner\'s own daily life',
                context: "a personal sentence about the learner's own daily life",
                text: $sentence,
            );

            $this->feedback[$index] = $data + ['checkedText' => $sentence];
            $this->trackCheckAttempt($index, $data['severity']);
        } catch (ConnectionException|RequestException) {
            // RequestException's message carries the raw HTTP response body
            // (which can be an arbitrarily large error page, not a clean
            // API message) — never show that to the learner.
            $this->checkErrors[$index] = "Couldn't reach the AI service — please try again.";
        } catch (\Throwable $e) {
            $this->checkErrors[$index] = "Couldn't check this one: {$e->getMessage()}";
        }
    }

    /**
     * After 3 failed attempts on the same sentence, the learner can ask the
     * AI to just write the corrected version — see TracksCheckAttempts.
     */
    public function revealCorrection(int $index): void
    {
        $sentence = trim($this->sentences[$index] ?? '');

        if ($sentence === '') {
            return;
        }

        $this->revealCorrectionFor(
            key: $index,
            context: "a personal sentence about the learner's own daily life",
            text: $sentence,
            errorBagKey: $index,
            onCorrected: function (string $corrected) use ($index) {
                $this->sentences[$index] = $corrected;
                $this->feedback[$index] = ['severity' => 'none', 'hint' => '', 'checkedText' => $corrected];
            },
        );
    }

    public function declineReveal(int $index): void
    {
        $this->declineCheckReveal($index);
    }

    public function save(): void
    {
        $this->validate([
            'sentences' => 'array',
            'sentences.*' => 'nullable|string',
            'audioFile' => ['required', 'file', 'extensions:webm,ogg,mp3,wav,m4a', 'max:20480'],
        ]);

        $filledSentences = collect($this->sentences)
            ->map(fn ($s, $i) => ['index' => $i, 'text' => trim((string) $s)])
            ->filter(fn ($s) => $s['text'] !== '');

        if ($filledSentences->count() < 5) {
            $this->addError('sentences', 'Write all 5 personal sentences before continuing.');

            return;
        }

        // Every filled sentence needs a fresh verdict before Continue is
        // allowed through — reuse an existing one only if it was checked
        // against this exact text (an edit since the last check invalidates it).
        foreach ($filledSentences as $item) {
            $alreadyChecked = ($this->feedback[$item['index']]['checkedText'] ?? null) === $item['text'];

            if (! $alreadyChecked) {
                $this->runCheck($item['index'], $item['text']);
            }
        }

        $hasMajorIssue = $filledSentences->contains(
            fn ($item) => ($this->feedback[$item['index']]['severity'] ?? null) === 'major'
        );

        if ($hasMajorIssue) {
            $this->addError('sentences', 'Fix the highlighted sentence before continuing.');

            return;
        }

        $mission = $this->run->mission;

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'activation',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => $filledSentences->pluck('text')->values()->toJson(),
        ]);

        $path = $this->audioFile->store('missions/'.strtolower($mission->code).'/evidence', 'public');

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'activation',
            'type' => Evidence::TYPE_AUDIO,
            'content_ref' => \Illuminate\Support\Facades\Storage::disk('public')->url($path),
        ]);

        $this->redirect(route('missions.show', $mission), navigate: true);
    }
};
?>

@php
    $activation = $run->mission->stepContent('activation');
    $vocabularyWords = $run->selectedVocabularyWords();
    $initialFilled = collect($sentences)->map(fn ($s) => trim((string) $s) !== '')->values();
@endphp

<div class="space-y-6" x-data="{
    recording: false,
    seconds: 0,
    timer: null,
    mediaRecorder: null,
    chunks: [],
    uploading: false,
    uploaded: false,
    error: null,
    filled: {{ $initialFilled->toJson() }},
    dismissed: {},
    get filledCount() { return this.filled.filter(Boolean).length },
    async startRecording() {
        this.error = null;
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            this.chunks = [];
            this.mediaRecorder = new MediaRecorder(stream);
            this.mediaRecorder.ondataavailable = (e) => { if (e.data.size > 0) this.chunks.push(e.data); };
            this.mediaRecorder.onstop = () => {
                stream.getTracks().forEach((t) => t.stop());
                const blob = new Blob(this.chunks, { type: 'audio/webm' });
                const file = new File([blob], 'activation-speaking.webm', { type: 'audio/webm' });
                this.uploading = true;
                this.$wire.upload('audioFile', file,
                    () => { this.uploading = false; this.uploaded = true; },
                    () => { this.uploading = false; this.error = 'Upload failed. Please try again.'; }
                );
            };
            this.mediaRecorder.start();
            this.recording = true;
            this.seconds = 0;
            this.timer = setInterval(() => { this.seconds++; }, 1000);
        } catch (e) {
            this.error = 'Microphone access was denied or is unavailable.';
        }
    },
    stopRecording() {
        this.mediaRecorder.stop();
        this.recording = false;
        clearInterval(this.timer);
    },
    get formattedTime() {
        const m = Math.floor(this.seconds / 60).toString().padStart(2, '0');
        const s = (this.seconds % 60).toString().padStart(2, '0');
        return m + ':' + s;
    },
}">
    <x-hook :text="$activation['hook'] ?? null" />

    <div>
        <p class="text-xs font-semibold tracking-wide text-neutral-500 uppercase">Write 5 personal sentences</p>
        <p class="text-xs text-neutral-500">{{ $activation['task'] ?? '' }}</p>
        @if ($vocabularyWords)
            <p class="mt-1 text-xs text-neutral-400 italic">
                Tip: try using some of your words from earlier — {{ collect($vocabularyWords)->take(4)->implode(', ') }}{{ count($vocabularyWords) > 4 ? ', …' : '' }}
            </p>
        @endif

        @unless ($readOnly)
            <div class="mt-2">
                <x-progress-bar>
                    <div
                        class="h-full rounded-full transition-all duration-300"
                        :class="filledCount >= 5 ? 'bg-green-600' : 'bg-neutral-900 dark:bg-white'"
                        :style="`width: ${Math.min(filledCount, 5) / 5 * 100}%`"
                    ></div>
                    <x-slot:label>
                        <p
                            class="text-xs font-semibold transition-colors"
                            :class="filledCount >= 5 ? 'text-green-600' : 'text-neutral-600 dark:text-neutral-400'"
                            x-text="`${Math.min(filledCount, 5)} of 5 written`"
                        ></p>
                    </x-slot:label>
                </x-progress-bar>
            </div>
        @endunless

        <div wire:loading.class="pointer-events-none" wire:target="checkOne,revealCorrection,declineReveal,save" class="mt-2 space-y-3">
            @foreach ($sentences as $index => $sentence)
                @php $itemFeedback = $feedback[$index] ?? null; @endphp
                <div class="rounded border border-neutral-300 p-3 dark:border-neutral-700">
                    <div class="flex items-center gap-2">
                        <input
                            type="text"
                            wire:model="sentences.{{ $index }}"
                            placeholder="{{ $index + 1 }}."
                            x-on:input="filled[{{ $index }}] = $el.value.trim() !== ''; dismissed[{{ $index }}] = true"
                            @readonly($readOnly)
                            wire:loading.attr="disabled"
                            wire:target="checkOne,revealCorrection,declineReveal,save"
                            class="w-full rounded border border-neutral-300 bg-transparent px-2 py-1 text-sm disabled:opacity-50 dark:border-neutral-700"
                        >
                        <span x-show="filled[{{ $index }}]" class="shrink-0 text-sm text-green-600">✓</span>
                        @unless ($readOnly)
                            <x-check-button method="checkOne" :index="$index" wire-target="checkOne,revealCorrection,declineReveal,save" />
                        @endunless
                    </div>

                    @unless ($readOnly)
                        <x-ai-thinking wire:loading wire:target="checkOne({{ $index }}), revealCorrection({{ $index }}), save" class="mt-2" />
                    @endunless

                    <div x-show="!dismissed[{{ $index }}]" x-transition.opacity.duration.300ms>
                        <x-severity-feedback :feedback="$itemFeedback" :error="$checkErrors[$index] ?? null" />
                    </div>

                    @unless ($readOnly)
                        <x-almost-reveal-notice :show="($checkAttempts[$index] ?? 0) === 2" />
                        <x-reveal-offer
                            :show="$offerReveal[$index] ?? false"
                            reveal-method="revealCorrection"
                            decline-method="declineReveal"
                            :index="$index"
                            wire-target="checkOne,revealCorrection,declineReveal,save"
                        />
                    @endunless
                </div>
            @endforeach
        </div>
        @error('sentences')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <p class="text-xs font-semibold tracking-wide text-neutral-500 uppercase">Solo speaking — 2 minutes</p>

        @if ($readOnly)
            <div class="mt-2">
                <x-audio-player :url="$savedAudioUrl" />
            </div>
        @else
            <p class="text-xs text-neutral-500">Talk about your daily life without reading. Record when you're ready.</p>

            <div class="mt-3 flex items-center gap-3">
                <button
                    type="button"
                    x-show="!recording && !uploaded"
                    x-on:click="startRecording"
                    class="cursor-pointer rounded-full bg-red-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-red-700"
                >● Record</button>

                <button
                    type="button"
                    x-show="recording"
                    x-on:click="stopRecording"
                    class="cursor-pointer rounded-full bg-neutral-900 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-neutral-700 dark:bg-white dark:text-neutral-900 dark:hover:bg-neutral-200"
                >■ Stop (<span x-text="formattedTime"></span>)</button>

                <span x-show="uploading" class="text-sm text-neutral-500">Uploading…</span>
                <span x-show="uploaded" class="text-sm text-green-600">✓ Recording saved</span>
            </div>

            <p x-show="error" x-text="error" class="mt-2 text-sm text-red-600"></p>
            @error('audioFile')
                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
            @enderror
        @endif
    </div>

    @unless ($readOnly)
        <x-continue-button
            on-click="filled.forEach((_, i) => dismissed[i] = true); $wire.save().then(() => { dismissed = {} })"
            wire-target="checkOne,revealCorrection,declineReveal,save"
            loading-label="Checking your sentences…"
        />
    @endunless
</div>
