{{--
    Shared mic-recording widget — previously duplicated (with small, real
    behavioral differences) across Activation, AI Conversation #1, and AI
    Conversation #2.

    @param string $field The Livewire property to upload the recording into.
    @param mixed $file The current value of that property (pass `:file="$audioFile"`)
        — used to render a playback player of the recording once uploaded.
    @param string|null $onRecorded A Livewire method name to call automatically
        once the upload succeeds (e.g. "submitAnswer"). Omit for a manual-submit
        flow (the caller has its own Continue/save button).
    @param int|string|null $onRecordedParam A single extra argument passed to
        $onRecorded (e.g. a question index), for a caller with more than one
        recorder on the page that all share one method. Omit when $onRecorded
        takes no arguments — every existing caller is unaffected.
    @param string|null $onUploaded Raw Alpine statement(s) run the moment the
        raw upload finishes, BEFORE $onRecorded's server call starts — e.g.
        showing the caller's own optimistic "sending…" bubble immediately
        (see ⚡ask-instructor.blade.php) rather than only reacting once the
        whole round-trip (transcribe + AI check) is done.
    @param string|null $onProcessed Raw Alpine statement(s) run once
        $onRecorded's server call settles — success OR failure, e.g.
        clearing the optimistic bubble $onUploaded showed (it would
        otherwise sit on screen forever if the call errors).
    @param string $fileName Filename given to the uploaded blob.
    @param bool $compact Icon-only buttons sized to match a row of other
        icon buttons (e.g. a chat composer) instead of the default
        labeled pill buttons. No playback block, no "Recording saved"
        text — meant for an auto-send flow (see $onRecorded) where the
        message appears in the thread immediately anyway.
--}}
@props(['field', 'file' => null, 'onRecorded' => null, 'onRecordedParam' => null, 'onUploaded' => null, 'onProcessed' => null, 'fileName' => 'recording.webm', 'compact' => false])

<div
    x-data="{
        recording: false,
        cancelled: false,
        seconds: 0,
        timer: null,
        mediaRecorder: null,
        chunks: [],
        uploading: false,
        // True from the moment the raw upload finishes until $onRecorded's
        // own server call (transcribe + AI check, whatever it does) also
        // finishes — closes the gap where the recorder's own 'Uploading…'
        // state had already cleared but the caller's own wire:loading
        // indicator hadn't shown up yet, which looked like nothing was
        // happening at all.
        processing: false,
        error: null,
        async startRecording() {
            this.error = null;
            this.cancelled = false;
            try {
                const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                this.chunks = [];
                this.mediaRecorder = new MediaRecorder(stream);
                this.mediaRecorder.ondataavailable = (e) => { if (e.data.size > 0) this.chunks.push(e.data); };
                this.mediaRecorder.onstop = () => {
                    stream.getTracks().forEach((t) => t.stop());
                    if (this.cancelled) return;
                    const blob = new Blob(this.chunks, { type: 'audio/webm' });
                    const file = new File([blob], '{{ $fileName }}', { type: 'audio/webm' });
                    this.uploading = true;
                    this.$wire.upload('{{ $field }}', file,
                        () => {
                            this.uploading = false;
                            {{ $onUploaded }}
                            @if ($onRecorded)
                                this.processing = true;
                                this.$wire.call('{{ $onRecorded }}'@if($onRecordedParam !== null), {{ Illuminate\Support\Js::from($onRecordedParam) }}@endif)
                                    .finally(() => { this.processing = false; {{ $onProcessed }} });
                            @endif
                        },
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
        cancelRecording() {
            this.cancelled = true;
            this.mediaRecorder.stop();
            this.recording = false;
            clearInterval(this.timer);
        },
        get formattedTime() {
            const m = Math.floor(this.seconds / 60).toString().padStart(2, '0');
            const s = (this.seconds % 60).toString().padStart(2, '0');
            return m + ':' + s;
        },
    }"
>
    @if ($compact)
        <div class="flex items-center gap-1">
            <button
                type="button"
                x-show="!recording && !uploading && !processing"
                x-on:click="startRecording"
                title="Record a voice message"
                class="inline-flex h-9 w-9 shrink-0 cursor-pointer items-center justify-center rounded-full text-ink-faint transition-colors hover:bg-surface-sunken hover:text-ink dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark dark:hover:text-ink-dark"
            >@svg('heroicon-o-microphone', 'h-4 w-4')</button>

            <button
                type="button"
                x-show="recording"
                x-cloak
                x-on:click="cancelRecording"
                title="Cancel"
                class="inline-flex h-9 w-9 shrink-0 cursor-pointer items-center justify-center rounded-full text-ink-faint transition-colors hover:bg-surface-sunken hover:text-ink dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark dark:hover:text-ink-dark"
            >@svg('heroicon-o-x-mark', 'h-4 w-4')</button>

            <button
                type="button"
                x-show="recording"
                x-cloak
                x-on:click="stopRecording"
                title="Stop recording"
                class="inline-flex h-9 items-center gap-1 rounded-full bg-red-600 px-2.5 text-white transition-colors hover:opacity-90"
            >@svg('heroicon-s-stop-circle', 'h-4 w-4') <span class="text-xs tabular-nums" x-text="formattedTime"></span></button>

            <span x-show="uploading || processing" x-cloak x-bind:title="uploading ? 'Uploading…' : 'Processing…'" class="inline-flex h-9 w-9 shrink-0 items-center justify-center text-ink-faint dark:text-ink-faint-dark">
                @svg('heroicon-o-arrow-path', 'h-4 w-4 animate-spin')
            </span>
        </div>

        <p x-show="error" x-cloak x-text="error" class="mt-1 text-xs text-red-600"></p>
    @else
        <div class="flex items-center gap-3">
            <button
                type="button"
                x-show="!recording"
                x-on:click="startRecording"
                :disabled="uploading || processing"
                class="inline-flex cursor-pointer items-center gap-1.5 rounded-full bg-accent px-4 py-2 text-sm font-semibold text-white transition-colors hover:opacity-90 disabled:pointer-events-none disabled:opacity-50 dark:bg-accent-dark"
            >@svg('heroicon-s-microphone', 'h-4 w-4') Record</button>

            <button
                type="button"
                x-show="recording"
                x-on:click="stopRecording"
                class="inline-flex cursor-pointer items-center gap-1.5 rounded-full bg-ink px-4 py-2 text-sm font-semibold text-ground transition-colors hover:opacity-85 dark:bg-ink-dark dark:text-ground-dark"
            >@svg('heroicon-s-stop-circle', 'h-4 w-4') Stop (<span x-text="formattedTime"></span>)</button>

            <button
                type="button"
                x-show="recording"
                x-on:click="cancelRecording"
                title="Cancel"
                class="inline-flex cursor-pointer items-center gap-1 rounded-full border border-line px-3 py-2 text-sm font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
            >@svg('heroicon-o-x-mark', 'h-4 w-4') Cancel</button>

            <span x-show="uploading" x-cloak class="inline-flex items-center gap-1.5 text-sm text-ink-soft dark:text-ink-soft-dark">
                @svg('heroicon-o-arrow-path', 'h-4 w-4 animate-spin') Uploading…
            </span>
            <span x-show="processing" x-cloak class="inline-flex items-center gap-1.5 text-sm text-ink-soft dark:text-ink-soft-dark">
                @svg('heroicon-o-arrow-path', 'h-4 w-4 animate-spin') Processing…
            </span>
            <span x-show="!uploading && !processing && !recording && {{ $file ? 'true' : 'false' }}" class="inline-flex items-center gap-1 text-sm text-success dark:text-success-dark">
                @svg('heroicon-o-check-circle', 'h-4 w-4')
                Recording saved
            </span>
        </div>

        <p x-show="error" x-text="error" class="mt-2 text-sm text-red-600"></p>

        @if ($file)
            <div class="mt-3">
                <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Listen back — not happy with it? Just record again.</p>
                <div class="mt-1">
                    <x-audio-player :url="$file->temporaryUrl()" />
                </div>
            </div>
        @endif
    @endif
</div>
