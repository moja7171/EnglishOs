<?php

use App\Models\Evidence;
use App\Models\MissionRun;
use Illuminate\Http\UploadedFile;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public MissionRun $run;

    public bool $readOnly = false;

    /** @var array<int, string> */
    public array $sentences = ['', '', '', '', ''];

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

    public function save(): void
    {
        $this->validate([
            'sentences' => 'array',
            'sentences.*' => 'nullable|string',
            'audioFile' => ['required', 'file', 'extensions:webm,ogg,mp3,wav,m4a', 'max:20480'],
        ]);

        $filledSentences = collect($this->sentences)->map(fn ($s) => trim($s))->filter()->values();

        if ($filledSentences->count() < 5) {
            $this->addError('sentences', 'Write all 5 personal sentences before continuing.');

            return;
        }

        $mission = $this->run->mission;

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'activation',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => $filledSentences->toJson(),
        ]);

        $path = $this->audioFile->store('missions/'.strtolower($mission->code).'/evidence', 'public');

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'activation',
            'type' => Evidence::TYPE_AUDIO,
            'content_ref' => \Illuminate\Support\Facades\Storage::disk('public')->url($path),
        ]);

        $this->redirect(route('missions.show', $mission));
    }
};
?>

@php $activation = $run->mission->stepContent('activation'); @endphp

<div class="space-y-6" x-data="{
    recording: false,
    seconds: 0,
    timer: null,
    mediaRecorder: null,
    chunks: [],
    uploading: false,
    uploaded: false,
    error: null,
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
        <div class="mt-2 space-y-2">
            @foreach ($sentences as $index => $sentence)
                <input
                    type="text"
                    wire:model="sentences.{{ $index }}"
                    placeholder="{{ $index + 1 }}."
                    @readonly($readOnly)
                    class="w-full rounded border border-neutral-300 bg-transparent px-2 py-1 text-sm dark:border-neutral-700"
                >
            @endforeach
        </div>
        @error('sentences')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <p class="text-xs font-semibold tracking-wide text-neutral-500 uppercase">Solo speaking — 2 minutes</p>

        @if ($readOnly)
            @if ($savedAudioUrl)
                <audio controls preload="none" class="mt-2 w-full">
                    <source src="{{ $savedAudioUrl }}">
                </audio>
            @endif
        @else
            <p class="text-xs text-neutral-500">Talk about your daily life without reading. Record when you're ready.</p>

            <div class="mt-3 flex items-center gap-3">
                <button
                    type="button"
                    x-show="!recording && !uploaded"
                    x-on:click="startRecording"
                    class="rounded-full bg-red-600 px-4 py-2 text-sm font-semibold text-white"
                >● Record</button>

                <button
                    type="button"
                    x-show="recording"
                    x-on:click="stopRecording"
                    class="rounded-full bg-neutral-900 px-4 py-2 text-sm font-semibold text-white dark:bg-white dark:text-neutral-900"
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
        <button
            wire:click="save"
            class="rounded bg-neutral-900 px-4 py-2 text-sm font-semibold text-white dark:bg-white dark:text-neutral-900"
        >
            Continue
        </button>
    @endunless
</div>
