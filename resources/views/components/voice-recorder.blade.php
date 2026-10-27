{{--
    Shared mic-recording widget — previously duplicated (with small, real
    behavioral differences) across Activation, AI Conversation #1, and AI
    Conversation #2.

    Works like a real voice recorder: Record → Pause/Resume as often as
    needed (a live level meter shows the mic is really hearing you) →
    Stop → listen back → Send/Save, re-record, or throw the take away.
    Nothing is uploaded until the learner chooses to send it, so a fumbled
    sentence never reaches the AI check. A take auto-stops into review at
    MAX_SECONDS, and auto-pauses if the page is hidden (a phone call, an
    app switch) instead of silently recording on.

    @param string $field The Livewire property to upload the recording into.
    @param mixed $file The current value of that property (pass `:file="$audioFile"`)
        — used to render a playback player of the recording once uploaded.
        May instead be the URL of a recording already kept on the server
        (see KeepsShadowDrafts), which plays back the same way.
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
    @param string $fileName Filename given to the uploaded blob. A browser
        that can only record mp4 (iOS Safari) gets the same name with an
        .m4a extension instead of .webm, so the file is labeled truthfully.
    @param bool $compact Icon-only buttons sized to match a row of other
        icon buttons (e.g. a chat composer) instead of the default
        labeled pill buttons. No level meter, no "Recording saved"
        text — meant for an auto-send flow (see $onRecorded) where the
        message appears in the thread immediately anyway.
    @param bool $big The one-task lesson screen's recorder: a 72px round
        mic in the middle of the screen with a soft double ring (and a
        72px stop whose ring breathes while recording) instead of the
        labeled pill buttons. Review and the saved take look the same as
        always.
--}}
@props(['field', 'file' => null, 'onRecorded' => null, 'onRecordedParam' => null, 'onUploaded' => null, 'onProcessed' => null, 'fileName' => 'recording.webm', 'compact' => false, 'big' => false])

<div
    x-data="{
        // Tells the bottom nav to get out of the way while a take is open
        // (recording, paused, or waiting in review): a stray tap on a tab
        // would throw it away.
        // (An init() method rather than an init attribute on purpose —
        // some tests assert a step renders no auto-run attribute at all.)
        init() {
            this.$watch('state', (state) => window.dispatchEvent(new CustomEvent('eos-recording', { detail: state !== 'idle' })));
            this.onVisibilityChange = () => { if (document.hidden && this.state === 'recording') this.pauseRecording(); };
            document.addEventListener('visibilitychange', this.onVisibilityChange);
        },
        MAX_SECONDS: 180,
        // idle | recording | paused | review
        state: 'idle',
        cancelled: false,
        seconds: 0,
        elapsedMs: 0,
        segmentStartedAt: 0,
        timer: null,
        mediaRecorder: null,
        stream: null,
        audioContext: null,
        meterFrame: null,
        levels: [0, 0, 0, 0, 0, 0, 0],
        pendingFile: null,
        previewUrl: null,
        previewDuration: 0,
        previewTime: 0,
        previewPlaying: false,
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
            this.discardTake();
            try {
                this.stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                const mimeType = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4']
                    .find((type) => MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(type));
                // Local to this take: a cancelled take's late events must
                // never leak into (or end) a new one started right after.
                const chunks = [];
                const recorder = mimeType ? new MediaRecorder(this.stream, { mimeType }) : new MediaRecorder(this.stream);
                this.mediaRecorder = recorder;
                recorder.ondataavailable = (e) => { if (e.data.size > 0) chunks.push(e.data); };
                recorder.onstop = () => {
                    if (this.cancelled || recorder !== this.mediaRecorder) return;
                    this.releaseMic();
                    const type = (recorder.mimeType || 'audio/webm').split(';')[0];
                    const name = type === 'audio/mp4' ? this.fileName.replace(/\.webm$/, '.m4a') : this.fileName;
                    const blob = new Blob(chunks, { type });
                    this.pendingFile = new File([blob], name, { type });
                    this.previewUrl = URL.createObjectURL(blob);
                    this.state = 'review';
                };
                this.mediaRecorder.start();
                this.startMeter();
                this.elapsedMs = 0;
                this.seconds = 0;
                this.segmentStartedAt = performance.now();
                this.state = 'recording';
                this.timer = setInterval(() => this.tick(), 250);
            } catch (e) {
                this.releaseMic();
                this.error = 'Microphone access was denied or is unavailable.';
            }
        },
        pauseRecording() {
            if (this.state !== 'recording' || this.mediaRecorder.state !== 'recording') return;
            this.mediaRecorder.pause();
            this.elapsedMs += performance.now() - this.segmentStartedAt;
            this.levels = this.levels.map(() => 0);
            this.state = 'paused';
        },
        resumeRecording() {
            if (this.state !== 'paused' || this.mediaRecorder.state !== 'paused') return;
            this.mediaRecorder.resume();
            this.segmentStartedAt = performance.now();
            this.state = 'recording';
        },
        stopRecording() {
            if ((this.state !== 'recording' && this.state !== 'paused') || this.mediaRecorder.state === 'inactive') return;
            if (this.state === 'recording') this.elapsedMs += performance.now() - this.segmentStartedAt;
            this.previewDuration = this.elapsedMs / 1000;
            clearInterval(this.timer);
            this.mediaRecorder.stop();
        },
        cancelRecording() {
            this.cancelled = true;
            clearInterval(this.timer);
            if (this.mediaRecorder && this.mediaRecorder.state !== 'inactive') this.mediaRecorder.stop();
            this.releaseMic();
            this.state = 'idle';
        },
        tick() {
            const ms = this.elapsedMs + (this.state === 'recording' ? performance.now() - this.segmentStartedAt : 0);
            this.seconds = Math.floor(ms / 1000);
            if (this.seconds >= this.MAX_SECONDS) this.stopRecording();
        },
        // A nicety, not a requirement: if the browser has no Web Audio the
        // take still records, just without the moving bars.
        startMeter() {
            try {
                this.audioContext = new (window.AudioContext || window.webkitAudioContext)();
                const analyser = this.audioContext.createAnalyser();
                analyser.fftSize = 256;
                this.audioContext.createMediaStreamSource(this.stream).connect(analyser);
                const samples = new Uint8Array(analyser.fftSize);
                let lastDrawnAt = 0;
                const draw = (now) => {
                    this.meterFrame = requestAnimationFrame(draw);
                    if (this.state !== 'recording' || now - lastDrawnAt < 90) return;
                    lastDrawnAt = now;
                    analyser.getByteTimeDomainData(samples);
                    let sum = 0;
                    for (const sample of samples) { const d = (sample - 128) / 128; sum += d * d; }
                    this.levels = [...this.levels.slice(1), Math.min(1, Math.sqrt(sum / samples.length) * 4)];
                };
                this.meterFrame = requestAnimationFrame(draw);
            } catch (e) {}
        },
        releaseMic() {
            cancelAnimationFrame(this.meterFrame);
            if (this.audioContext) { this.audioContext.close().catch(() => {}); this.audioContext = null; }
            if (this.stream) { this.stream.getTracks().forEach((t) => t.stop()); this.stream = null; }
            this.levels = this.levels.map(() => 0);
        },
        togglePreview() {
            this.$refs.preview.paused ? this.$refs.preview.play() : this.$refs.preview.pause();
        },
        // The take's real length: MediaRecorder webm has no duration in
        // its header (Infinity) until the browser has scanned the whole
        // file, which a seek far past the end forces — the same trick as
        // the shared audio player. The timer's value stays as the fallback.
        resolvePreviewDuration() {
            const audio = this.$refs.preview;
            if (isFinite(audio.duration) && audio.duration > 0) { this.previewDuration = audio.duration; return; }
            audio.currentTime = 1e101;
            audio.addEventListener('timeupdate', () => { audio.currentTime = 0; }, { once: true });
        },
        // Every frame while playing, not just on 'timeupdate' (~4×/s), so
        // the bar moves smoothly, not in steps.
        followPreview() {
            const audio = this.$refs.preview;
            const tick = () => {
                if (audio.paused || audio.ended) return;
                this.previewTime = audio.currentTime;
                requestAnimationFrame(tick);
            };
            requestAnimationFrame(tick);
        },
        discardTake() {
            if (this.$refs.preview) this.$refs.preview.pause();
            if (this.previewUrl) URL.revokeObjectURL(this.previewUrl);
            this.previewUrl = null;
            this.pendingFile = null;
            this.previewTime = 0;
            this.previewPlaying = false;
            if (this.state === 'review') this.state = 'idle';
        },
        // Whatever goes wrong on the way, the take comes back to review so
        // one tap on Send retries it — a weak connection must never eat a
        // recording.
        handBackTake() {
            this.uploading = false;
            this.processing = false;
            this.state = this.pendingFile ? 'review' : 'idle';
            this.error = 'Not sent. Check your connection and tap Send again.';
        },
        sendRecording() {
            if (!this.pendingFile) return;
            this.$refs.preview.pause();
            this.error = null;
            this.uploading = true;
            this.state = 'idle';
            // Livewire only reports a failed upload after one more
            // successful round-trip (_uploadErrored), and on a weak
            // connection that round-trip fails too: the error callback
            // never fired, the spinner turned forever and the take sat
            // hidden with no Send left. So every Livewire request of the
            // upload is watched here, plus a stall timer for an upload
            // that just hangs (progress events re-arm it).
            let settled = false;
            let stallTimer = null;
            const stopWatching = this.$wire.$intercept(({ action, onError, onFailure }) => {
                if (!['_startUpload', '_finishUpload', '_uploadErrored'].includes(action.name)) return;
                onError(({ response, preventDefault }) => {
                    if (response.status !== 419) preventDefault();
                    window.eosConnectionNotice?.skip();
                    failed();
                });
                onFailure(() => { window.eosConnectionNotice?.skip(); failed(); });
            });
            const settle = () => {
                settled = true;
                clearTimeout(stallTimer);
                stopWatching();
            };
            const failed = () => {
                if (settled) return;
                settle();
                // Empties Livewire's queue for this field (and aborts a
                // hung request): a stuck entry there blocks every later
                // upload of the same field, so a retry would never start.
                this.$wire.cancelUpload('{{ $field }}');
                this.handBackTake();
            };
            const armStallTimer = () => {
                clearTimeout(stallTimer);
                stallTimer = setTimeout(failed, 45000);
            };
            armStallTimer();
            this.$wire.upload('{{ $field }}', this.pendingFile,
                () => {
                    if (settled) return;
                    settle();
                    this.uploading = false;
                    {{ $onUploaded }}
                    @if ($onRecorded)
                        this.processing = true;
                        this.$wire.call('{{ $onRecorded }}'@if($onRecordedParam !== null), {{ Illuminate\Support\Js::from($onRecordedParam) }}@endif)
                            .then(() => this.discardTake())
                            .catch((failure) => {
                                // A validation answer (422) means the server
                                // did handle the take. No answer, or a host
                                // error page, means it never did: keep it.
                                if (failure?.status === 422) {
                                    this.discardTake();
                                    return;
                                }
                                window.eosConnectionNotice?.skip();
                                this.handBackTake();
                            })
                            .finally(() => { this.processing = false; {{ $onProcessed }} });
                    @else
                        this.discardTake();
                    @endif
                },
                failed,
                armStallTimer
            );
        },
        fileName: {{ Illuminate\Support\Js::from($fileName) }},
        clock(totalSeconds) {
            const whole = Math.floor(totalSeconds);
            return Math.floor(whole / 60).toString().padStart(2, '0') + ':' + (whole % 60).toString().padStart(2, '0');
        },
        get formattedTime() {
            return this.clock(this.seconds);
        },
        get nearLimit() {
            return this.MAX_SECONDS - this.seconds <= 10;
        },
        get previewPercent() {
            return this.previewDuration > 0 ? Math.min(100, (this.previewTime / this.previewDuration) * 100) : 0;
        },
        get previewClock() {
            return this.clock(this.previewPlaying || this.previewTime > 0 ? this.previewTime : this.previewDuration);
        },
        // Alpine calls this when the recorder leaves the page (step change,
        // re-render): stops a take still in progress — otherwise the mic
        // stays live with no button left to turn it off — and makes sure
        // the bottom nav can't be left hidden by a take that no longer exists.
        destroy() {
            this.cancelRecording();
            this.discardTake();
            document.removeEventListener('visibilitychange', this.onVisibilityChange);
            window.dispatchEvent(new CustomEvent('eos-recording', { detail: false }));
        },
    }"
    @if ($big) class="flex w-full flex-col items-center gap-3" @endif
>
    {{-- The take's own playback, before it is sent. A finished play-through
         leaves the bar full (and the clock on the total) until Play again. --}}
    <audio
        x-ref="preview"
        x-bind:src="previewUrl"
        x-on:loadedmetadata="resolvePreviewDuration()"
        x-on:durationchange="if (isFinite($el.duration) && $el.duration > 0) previewDuration = $el.duration"
        x-on:play="previewPlaying = true; followPreview()"
        x-on:pause="previewPlaying = false"
        x-on:ended="previewPlaying = false; previewTime = previewDuration"
        preload="auto"
        class="hidden"
    ></audio>

    @if ($compact)
        <div class="flex items-center gap-1">
            <button
                type="button"
                x-show="state === 'idle' && !uploading && !processing"
                x-on:click="startRecording"
                title="Record a voice message"
                class="inline-flex h-9 w-9 shrink-0 cursor-pointer items-center justify-center rounded-full text-ink-faint transition-colors hover:bg-surface-sunken hover:text-ink dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark dark:hover:text-ink-dark"
            >@svg('heroicon-o-microphone', 'h-4 w-4')</button>

            {{-- Recording / paused --}}
            <button
                type="button"
                x-show="state === 'recording' || state === 'paused'"
                x-cloak
                x-on:click="cancelRecording"
                title="Cancel"
                class="inline-flex h-9 w-9 shrink-0 cursor-pointer items-center justify-center rounded-full text-ink-faint transition-colors hover:bg-surface-sunken hover:text-ink dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark dark:hover:text-ink-dark"
            >@svg('heroicon-o-x-mark', 'h-4 w-4')</button>

            <button
                type="button"
                x-show="state === 'recording' || state === 'paused'"
                x-cloak
                x-on:click="state === 'recording' ? pauseRecording() : resumeRecording()"
                x-bind:title="state === 'recording' ? 'Pause' : 'Resume'"
                class="inline-flex h-9 w-9 shrink-0 cursor-pointer items-center justify-center rounded-full text-ink-faint transition-colors hover:bg-surface-sunken hover:text-ink dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark dark:hover:text-ink-dark"
            >
                <span x-show="state === 'recording'">@svg('heroicon-s-pause', 'h-4 w-4')</span>
                <span x-show="state === 'paused'" x-cloak>@svg('heroicon-s-microphone', 'h-4 w-4 text-danger')</span>
            </button>

            <button
                type="button"
                x-show="state === 'recording' || state === 'paused'"
                x-cloak
                x-on:click="stopRecording"
                title="Stop recording"
                class="inline-flex h-9 items-center gap-1 rounded-full bg-red-600 px-2.5 text-white transition-colors hover:opacity-90"
            >@svg('heroicon-s-stop-circle', 'h-4 w-4') <span class="text-xs tabular-nums" x-bind:class="{ 'animate-pulse': state === 'paused' }" x-text="formattedTime"></span></button>

            {{-- Review --}}
            <button
                type="button"
                x-show="state === 'review'"
                x-cloak
                x-on:click="discardTake"
                title="Delete"
                class="inline-flex h-9 w-9 shrink-0 cursor-pointer items-center justify-center rounded-full text-ink-faint transition-colors hover:bg-surface-sunken hover:text-ink dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark dark:hover:text-ink-dark"
            >@svg('heroicon-o-trash', 'h-4 w-4')</button>

            <button
                type="button"
                x-show="state === 'review'"
                x-cloak
                x-on:click="togglePreview"
                x-bind:title="previewPlaying ? 'Pause' : 'Listen'"
                class="inline-flex h-9 items-center gap-1 rounded-full bg-surface-sunken px-2.5 text-ink transition-colors hover:opacity-85 dark:bg-surface-sunken-dark dark:text-ink-dark"
            >
                <span x-show="!previewPlaying">@svg('heroicon-s-play', 'h-4 w-4')</span>
                <span x-show="previewPlaying" x-cloak>@svg('heroicon-s-pause', 'h-4 w-4')</span>
                <span class="text-xs tabular-nums" x-text="previewClock"></span>
            </button>

            <button
                type="button"
                x-show="state === 'review'"
                x-cloak
                x-on:click="sendRecording"
                title="Send"
                class="inline-flex h-9 w-9 shrink-0 cursor-pointer items-center justify-center rounded-full bg-accent text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
            >@svg('heroicon-s-paper-airplane', 'h-4 w-4')</button>

            <span x-show="uploading || processing" x-cloak x-bind:title="uploading ? 'Uploading…' : 'Processing…'" class="inline-flex h-9 w-9 shrink-0 items-center justify-center text-ink-faint dark:text-ink-faint-dark">
                @svg('heroicon-o-arrow-path', 'h-4 w-4 animate-spin')
            </span>
        </div>

        <p x-show="error" x-cloak x-text="error" class="mt-1 text-xs text-danger-ink"></p>
    @else
        <div x-show="state === 'idle'" @class(['flex flex-wrap items-center gap-3' => ! $big, 'flex flex-col items-center gap-2 text-center' => $big])>
            @if ($big && $file)
                <button
                    type="button"
                    x-show="!uploading && !processing"
                    x-on:click="startRecording"
                    class="inline-flex cursor-pointer items-center gap-1.5 text-sm font-bold text-accent-ink dark:text-accent-ink-dark"
                >@svg('heroicon-o-arrow-path', 'h-4 w-4') Record again</button>
            @elseif ($big)
                <button
                    type="button"
                    x-on:click="startRecording"
                    :disabled="uploading || processing"
                    aria-label="Record"
                    class="my-5 inline-flex cursor-pointer rounded-full text-accent transition-transform active:scale-95 disabled:pointer-events-none disabled:opacity-50 dark:text-accent-dark"
                ><span class="mic-ring inline-flex h-18 w-18 items-center justify-center rounded-full bg-current">@svg('heroicon-s-microphone', 'h-8 w-8 text-white')</span></button>
                <span x-show="!uploading && !processing" class="text-sm font-bold text-ink-faint dark:text-ink-faint-dark">Tap to record</span>
            @else
                <button
                    type="button"
                    x-on:click="startRecording"
                    :disabled="uploading || processing"
                    class="inline-flex cursor-pointer items-center gap-1.5 rounded-full bg-accent px-4 py-2 text-sm font-semibold text-white transition-colors hover:opacity-90 disabled:pointer-events-none disabled:opacity-50 dark:bg-accent-dark"
                >@svg('heroicon-s-microphone', 'h-4 w-4') Record</button>
            @endif

            <span x-show="uploading" x-cloak class="inline-flex items-center gap-1.5 text-sm text-ink-soft dark:text-ink-soft-dark">
                @svg('heroicon-o-arrow-path', 'h-4 w-4 animate-spin') Uploading…
            </span>
            <span x-show="processing" x-cloak class="inline-flex items-center gap-1.5 text-sm text-ink-soft dark:text-ink-soft-dark">
                @svg('heroicon-o-arrow-path', 'h-4 w-4 animate-spin') Processing…
            </span>
            <span x-show="!uploading && !processing && {{ $file && ! $big ? 'true' : 'false' }}" class="inline-flex items-center gap-1 text-sm text-success dark:text-success-dark">
                @svg('heroicon-o-check-circle', 'h-4 w-4')
                Recording saved
            </span>
        </div>

        {{-- Recording / paused --}}
        @if ($big)
            <div x-show="state === 'recording' || state === 'paused'" x-cloak class="flex flex-col items-center gap-3">
                <div class="flex items-center gap-5">
                    <button
                        type="button"
                        x-on:click="cancelRecording"
                        title="Cancel"
                        class="inline-flex h-11 w-11 shrink-0 cursor-pointer items-center justify-center rounded-full border border-line text-ink-soft transition-colors hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                    >@svg('heroicon-o-x-mark', 'h-5 w-5')</button>
                    <button
                        type="button"
                        x-on:click="stopRecording"
                        aria-label="Stop"
                        class="my-5 inline-flex cursor-pointer rounded-full text-red-600 transition-transform active:scale-95"
                    ><span x-bind:class="state === 'recording' ? 'animate-mic-breathe' : 'mic-ring'" class="inline-flex h-18 w-18 items-center justify-center rounded-full bg-current">@svg('heroicon-s-stop', 'h-8 w-8 text-white')</span></button>
                    <button
                        type="button"
                        x-on:click="state === 'recording' ? pauseRecording() : resumeRecording()"
                        x-bind:title="state === 'recording' ? 'Pause' : 'Resume'"
                        class="inline-flex h-11 w-11 shrink-0 cursor-pointer items-center justify-center rounded-full border border-line text-ink-soft transition-colors hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                    >
                        <span x-show="state === 'recording'">@svg('heroicon-s-pause', 'h-5 w-5')</span>
                        <span x-show="state === 'paused'" x-cloak>@svg('heroicon-s-microphone', 'h-5 w-5 text-danger')</span>
                    </button>
                </div>
                <div class="inline-flex items-center gap-2 text-sm font-bold text-ink tabular-nums dark:text-ink-dark">
                    <span x-bind:class="{ 'animate-pulse': state === 'paused', 'text-warning-ink': nearLimit }" x-text="formattedTime"></span>
                    <span class="flex h-5 items-center gap-0.5" aria-hidden="true">
                        <template x-for="(level, index) in levels" :key="index">
                            <span
                                class="w-1 rounded-full transition-[height] duration-100"
                                x-bind:class="state === 'recording' ? 'bg-danger' : 'bg-line dark:bg-line-dark'"
                                x-bind:style="`height: ${4 + Math.round(level * 16)}px`"
                            ></span>
                        </template>
                    </span>
                </div>
            </div>
        @else
        <div x-show="state === 'recording' || state === 'paused'" x-cloak class="flex flex-wrap items-center gap-3">
            <div class="inline-flex items-center gap-2 text-sm font-semibold text-ink tabular-nums dark:text-ink-dark">
                <span class="h-2.5 w-2.5 shrink-0 rounded-full" x-bind:class="state === 'recording' ? 'bg-danger animate-pulse' : 'bg-warning'"></span>
                <span x-bind:class="{ 'animate-pulse': state === 'paused', 'text-warning-ink': nearLimit }" x-text="formattedTime"></span>
                <span class="flex h-5 items-center gap-0.5" aria-hidden="true">
                    <template x-for="(level, index) in levels" :key="index">
                        <span
                            class="w-1 rounded-full transition-[height] duration-100"
                            x-bind:class="state === 'recording' ? 'bg-danger' : 'bg-line dark:bg-line-dark'"
                            x-bind:style="`height: ${4 + Math.round(level * 16)}px`"
                        ></span>
                    </template>
                </span>
            </div>

            <div class="flex items-center gap-2">
                <button
                    type="button"
                    x-on:click="state === 'recording' ? pauseRecording() : resumeRecording()"
                    x-bind:title="state === 'recording' ? 'Pause' : 'Resume'"
                    class="inline-flex h-9 w-9 shrink-0 cursor-pointer items-center justify-center rounded-full border border-line text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                >
                    <span x-show="state === 'recording'">@svg('heroicon-s-pause', 'h-4 w-4')</span>
                    <span x-show="state === 'paused'" x-cloak>@svg('heroicon-s-microphone', 'h-4 w-4 text-danger')</span>
                </button>

                <button
                    type="button"
                    x-on:click="stopRecording"
                    class="inline-flex cursor-pointer items-center gap-1.5 rounded-full bg-ink px-4 py-2 text-sm font-semibold text-ground transition-colors hover:opacity-85 dark:bg-ink-dark dark:text-ground-dark"
                >@svg('heroicon-s-stop-circle', 'h-4 w-4') Stop</button>

                <button
                    type="button"
                    x-on:click="cancelRecording"
                    title="Cancel"
                    class="inline-flex h-9 w-9 shrink-0 cursor-pointer items-center justify-center rounded-full border border-line text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                >@svg('heroicon-o-x-mark', 'h-4 w-4')</button>
            </div>
        </div>

        @endif

        {{-- Review: listen before it goes anywhere --}}
        <div x-show="state === 'review'" x-cloak @class(['flex flex-wrap items-center gap-2', 'w-full' => $big])>
            <div class="flex min-w-0 flex-1 basis-48 items-center gap-2 rounded-full bg-surface-sunken py-1 pr-3 pl-1 dark:bg-surface-sunken-dark">
                <button
                    type="button"
                    x-on:click="togglePreview"
                    x-bind:title="previewPlaying ? 'Pause' : 'Listen'"
                    class="inline-flex h-8 w-8 shrink-0 cursor-pointer items-center justify-center rounded-full bg-surface text-ink shadow-sm transition-colors hover:opacity-85 dark:bg-surface-dark dark:text-ink-dark"
                >
                    <span x-show="!previewPlaying">@svg('heroicon-s-play', 'h-4 w-4')</span>
                    <span x-show="previewPlaying" x-cloak>@svg('heroicon-s-pause', 'h-4 w-4')</span>
                </button>
                <div class="h-1.5 min-w-0 flex-1 overflow-hidden rounded-full bg-line dark:bg-line-dark">
                    <div class="h-full rounded-full bg-accent dark:bg-accent-dark" x-bind:style="`width: ${previewPercent}%`"></div>
                </div>
                <span class="text-xs text-ink-soft tabular-nums dark:text-ink-soft-dark" x-text="previewClock"></span>
            </div>

            <div class="flex items-center gap-2">
                <button
                    type="button"
                    x-on:click="startRecording"
                    title="Record again"
                    class="inline-flex h-9 w-9 shrink-0 cursor-pointer items-center justify-center rounded-full border border-line text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                >@svg('heroicon-o-arrow-uturn-left', 'h-4 w-4')</button>

                <button
                    type="button"
                    x-on:click="discardTake"
                    title="Delete"
                    class="inline-flex h-9 w-9 shrink-0 cursor-pointer items-center justify-center rounded-full border border-line text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                >@svg('heroicon-o-trash', 'h-4 w-4')</button>

                <button
                    type="button"
                    x-on:click="sendRecording"
                    class="inline-flex cursor-pointer items-center gap-1.5 rounded-full bg-accent px-4 py-2 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
                >@svg('heroicon-s-check', 'h-4 w-4') {{ $onRecorded ? 'Send' : 'Save' }}</button>
            </div>
        </div>

        <p x-show="error" x-text="error" class="mt-2 text-sm text-danger-ink"></p>

        @if ($file)
            <div @class(['mt-3' => ! $big, 'order-first w-full' => $big])>
                @unless ($big)
                    <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Listen back — not happy with it? Just record again.</p>
                @endunless
                <div class="mt-1">
                    <x-audio-player :url="is_string($file) ? $file : $file->temporaryUrl()" />
                </div>
            </div>
        @endif
    @endif
</div>
