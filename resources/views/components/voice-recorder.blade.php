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
    @param string $fileName Filename given to the uploaded blob.
--}}
@props(['field', 'file' => null, 'onRecorded' => null, 'onRecordedParam' => null, 'fileName' => 'recording.webm'])

<div
    x-data="{
        recording: false,
        seconds: 0,
        timer: null,
        mediaRecorder: null,
        chunks: [],
        uploading: false,
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
                    const file = new File([blob], '{{ $fileName }}', { type: 'audio/webm' });
                    this.uploading = true;
                    this.$wire.upload('{{ $field }}', file,
                        () => {
                            this.uploading = false;
                            @if ($onRecorded && $onRecordedParam !== null)
                                this.$wire.call('{{ $onRecorded }}', {{ Illuminate\Support\Js::from($onRecordedParam) }});
                            @elseif ($onRecorded)
                                this.$wire.call('{{ $onRecorded }}');
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
        get formattedTime() {
            const m = Math.floor(this.seconds / 60).toString().padStart(2, '0');
            const s = (this.seconds % 60).toString().padStart(2, '0');
            return m + ':' + s;
        },
    }"
>
    <div class="flex items-center gap-3">
        <button
            type="button"
            x-show="!recording"
            x-on:click="startRecording"
            :disabled="uploading"
            class="inline-flex cursor-pointer items-center gap-1.5 rounded-full bg-accent px-4 py-2 text-sm font-semibold text-white transition-colors hover:opacity-90 disabled:pointer-events-none disabled:opacity-50 dark:bg-accent-dark"
        >@svg('heroicon-s-microphone', 'h-4 w-4') Record</button>

        <button
            type="button"
            x-show="recording"
            x-on:click="stopRecording"
            class="inline-flex cursor-pointer items-center gap-1.5 rounded-full bg-ink px-4 py-2 text-sm font-semibold text-ground transition-colors hover:opacity-85 dark:bg-ink-dark dark:text-ground-dark"
        >@svg('heroicon-s-stop-circle', 'h-4 w-4') Stop (<span x-text="formattedTime"></span>)</button>

        <span x-show="uploading" class="text-sm text-ink-faint dark:text-ink-faint-dark">Uploading…</span>
        <span x-show="!uploading && !recording && {{ $file ? 'true' : 'false' }}" class="inline-flex items-center gap-1 text-sm text-success dark:text-success-dark">
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
</div>
