{{--
    @param string|null $onEnded Raw Alpine statement(s) run whenever playback
        reaches the end — e.g. onEnded="$dispatch('audio-ended')" so a
        parent listening for that event (bubbles up the DOM) can count real
        completed listens, not just play clicks.
    @param list<array{text: string, start: float, end: float}> $segments Real,
        timed chunks of what's being said (see
        missions:cache-shadow-timestamps) — when given, a synced text panel
        below the controls highlights whichever chunk is playing right now,
        auto-scrolling into view. [] renders the player exactly as before,
        with no panel at all.
    @param list<string> $shadowLines Shadowing targets (may carry **bold**
        stress markers), parallel to $shadowTimestamps by index. Given
        together with it, playback auto-pauses the moment it reaches each
        line's own real start time and shows a "now you say it" prompt
        with a replay-this-line button. Deliberately NOT a hard gate —
        recording itself lives in the caller's own persistent, always-
        reachable list below the player (see daily-listen-view.blade.php)
        so a line Whisper keeps mis-hearing can never trap the learner
        mid-episode with no way to move forward (Epic H4's "retry is
        always available, never a dead end" principle). "Continue
        listening" here just resumes playback.
    @param list<array{start: float, end: float}|null> $shadowTimestamps
--}}
@props([
    'url',
    'onEnded' => null,
    'segments' => [],
    'shadowLines' => [],
    'shadowTimestamps' => [],
])

@if (! empty($url))
    <div
        class="rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark"
        x-data="{
            playing: false,
            currentTime: 0,
            duration: 0,
            dragging: false,
            speed: 1,
            segments: {{ Illuminate\Support\Js::from($segments) }},
            shadowTimestamps: {{ Illuminate\Support\Js::from($shadowTimestamps) }},
            shadowSeen: [],
            activeShadowIndex: null,
            replayEndTime: null,
            init() {
                const audio = this.$refs.audio;
                const seek = this.$refs.seek;
                // MediaRecorder-produced webm (every voice recording in this
                // app) commonly reports duration as Infinity/NaN right after
                // loadedmetadata — the container has no real duration in its
                // header, only the actual byte length. The browser only
                // learns the true duration once it's scanned the whole file,
                // which a bare seek to a huge time forces immediately
                // (fires 'durationchange' with the real value once known)
                // instead of waiting for the learner to play it through once.
                // Without this, the seek bar tracks currentTime against that
                // wrong Infinity/placeholder duration and never reads back
                // as finished even though the audio itself plays fine.
                const resolveDuration = () => {
                    if (audio.duration === Infinity || isNaN(audio.duration)) {
                        audio.currentTime = 1e101;
                        audio.addEventListener('timeupdate', () => { audio.currentTime = 0; }, {once: true});
                    } else {
                        this.duration = audio.duration;
                    }
                };
                audio.addEventListener('durationchange', () => {
                    if (audio.duration !== Infinity && ! isNaN(audio.duration)) this.duration = audio.duration;
                });
                audio.addEventListener('loadedmetadata', resolveDuration);
                audio.addEventListener('timeupdate', () => {
                    this.currentTime = audio.currentTime;
                    // Imperative, not an Alpine :value binding — a reactive
                    // binding fighting the browser's own drag position is
                    // what caused seeking to snap back to 0.
                    if (! this.dragging) seek.value = audio.currentTime;

                    if (this.replayEndTime !== null && audio.currentTime >= this.replayEndTime) {
                        audio.pause();
                        this.replayEndTime = null;
                    }

                    if (this.activeShadowIndex === null) {
                        for (let i = 0; i < this.shadowTimestamps.length; i++) {
                            const point = this.shadowTimestamps[i];
                            if (! point || this.shadowSeen.includes(i)) continue;
                            if (audio.currentTime >= point.start && audio.currentTime < point.end) {
                                audio.pause();
                                this.activeShadowIndex = i;
                                this.shadowSeen.push(i);
                                break;
                            }
                        }
                    }
                });
                audio.addEventListener('play', () => this.playing = true);
                audio.addEventListener('pause', () => this.playing = false);
                audio.addEventListener('ended', () => { this.playing = false; {{ $onEnded }} });
                // Metadata may already have loaded before these listeners
                // were attached.
                if (audio.readyState >= 1) resolveDuration();

                this.$watch('activeSegmentIndex', (index) => {
                    this.$nextTick(() => this.$refs['segment-' + index]?.scrollIntoView({block: 'center'}));
                });
            },
            togglePlay() { this.playing ? this.$refs.audio.pause() : this.$refs.audio.play() },
            cycleSpeed() {
                const speeds = [0.75, 1, 1.25];
                this.speed = speeds[(speeds.indexOf(this.speed) + 1) % speeds.length];
                this.$refs.audio.playbackRate = this.speed;
            },
            skip(seconds) {
                const audio = this.$refs.audio;
                const max = audio.duration || this.duration || Infinity;
                const time = Math.min(Math.max(audio.currentTime + seconds, 0), max);
                audio.currentTime = time;
                this.currentTime = time;
                this.$refs.seek.value = time;
            },
            seekTo(value) {
                this.currentTime = value;
                this.$refs.audio.currentTime = value;
            },
            formatTime(t) {
                if (!t || isNaN(t)) return '0:00';
                const m = Math.floor(t / 60);
                const s = Math.floor(t % 60).toString().padStart(2, '0');
                return m + ':' + s;
            },
            get progressPercent() { return this.duration ? (this.currentTime / this.duration * 100) : 0 },
            get activeSegmentIndex() {
                for (let i = this.segments.length - 1; i >= 0; i--) {
                    if (this.currentTime >= this.segments[i].start) return i;
                }
                return -1;
            },
            replayShadowLine(index) {
                const point = this.shadowTimestamps[index];
                if (! point) return;
                this.$refs.audio.currentTime = point.start;
                this.replayEndTime = point.end;
                this.$refs.audio.play();
            },
            resumeAfterShadow() {
                this.activeShadowIndex = null;
                this.$refs.audio.play();
            },
        }"
    >
        <audio wire:ignore wire:key="audio-el-{{ md5($url) }}" x-ref="audio" preload="auto" class="hidden">
            <source src="{{ $url }}" type="audio/mpeg">
        </audio>

        {{-- Seek bar — a real filled progress track under the native range
             input (transparent, custom thumb only) rather than a bare
             unstyled OS slider. --}}
        <div class="flex items-center gap-2">
            <span class="w-9 shrink-0 text-right text-xs text-ink-faint tabular-nums dark:text-ink-faint-dark" x-text="formatTime(currentTime)"></span>

            <div class="relative flex h-4 flex-1 items-center">
                <div class="absolute inset-x-0 h-1.5 rounded-full bg-surface-sunken dark:bg-surface-sunken-dark"></div>
                <div class="absolute h-1.5 rounded-full bg-accent dark:bg-accent-dark" :style="`width: ${progressPercent}%`"></div>
                <input
                    type="range"
                    x-ref="seek"
                    min="0"
                    step="1"
                    :max="duration || 0"
                    value="0"
                    x-on:pointerdown="dragging = true"
                    x-on:pointerup="dragging = false"
                    x-on:input="seekTo($event.target.valueAsNumber)"
                    class="relative h-4 w-full cursor-pointer appearance-none bg-transparent
                        [&::-moz-range-thumb]:h-3.5 [&::-moz-range-thumb]:w-3.5 [&::-moz-range-thumb]:appearance-none
                        [&::-moz-range-thumb]:rounded-full [&::-moz-range-thumb]:border-0 [&::-moz-range-thumb]:bg-accent
                        [&::-moz-range-thumb]:shadow-sm [&::-moz-range-thumb]:dark:bg-accent-dark
                        [&::-moz-range-track]:bg-transparent
                        [&::-webkit-slider-runnable-track]:bg-transparent
                        [&::-webkit-slider-thumb]:h-3.5 [&::-webkit-slider-thumb]:w-3.5 [&::-webkit-slider-thumb]:appearance-none
                        [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:bg-accent [&::-webkit-slider-thumb]:shadow-sm
                        [&::-webkit-slider-thumb]:dark:bg-accent-dark"
                >
            </div>

            <span class="w-9 shrink-0 text-xs text-ink-faint tabular-nums dark:text-ink-faint-dark" x-text="formatTime(duration)"></span>
        </div>

        {{-- Controls — a real player layout: speed/download as secondary
             actions at the edges, transport controls centered around one
             prominent circular play/pause button. --}}
        <div class="mt-3 flex items-center justify-between">
            <button
                type="button"
                x-on:click="cycleSpeed()"
                title="Playback speed"
                class="inline-flex w-11 shrink-0 cursor-pointer items-center justify-center rounded-full border border-line py-1 text-xs font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                x-text="speed + 'x'"
            ></button>

            <div class="flex items-center gap-3">
                <button
                    type="button"
                    x-on:click="skip(-10)"
                    title="Back 10 seconds"
                    class="inline-flex shrink-0 cursor-pointer flex-col items-center gap-0.5 text-ink-soft transition-colors hover:text-ink dark:text-ink-soft-dark dark:hover:text-ink-dark"
                >
                    @svg('heroicon-o-backward', 'h-5 w-5')
                    <span class="text-[10px] leading-none font-semibold">10s</span>
                </button>

                <button
                    type="button"
                    x-on:click="togglePlay()"
                    class="inline-flex h-12 w-12 shrink-0 cursor-pointer items-center justify-center rounded-full bg-accent text-white shadow-sm transition-transform hover:scale-105 active:scale-95 dark:bg-accent-dark"
                >
                    <span x-show="!playing">@svg('heroicon-s-play', 'ml-0.5 h-5 w-5')</span>
                    <span x-show="playing" x-cloak>@svg('heroicon-s-pause', 'h-5 w-5')</span>
                </button>

                <button
                    type="button"
                    x-on:click="skip(10)"
                    title="Forward 10 seconds"
                    class="inline-flex shrink-0 cursor-pointer flex-col items-center gap-0.5 text-ink-soft transition-colors hover:text-ink dark:text-ink-soft-dark dark:hover:text-ink-dark"
                >
                    @svg('heroicon-o-forward', 'h-5 w-5')
                    <span class="text-[10px] leading-none font-semibold">10s</span>
                </button>
            </div>

            <a
                href="{{ $url }}"
                download
                title="Download"
                class="inline-flex w-11 shrink-0 cursor-pointer items-center justify-center rounded-full border border-line py-1.5 text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
            >@svg('heroicon-o-arrow-down-tray', 'h-3.5 w-3.5')</a>
        </div>

        @if (count($segments))
            {{-- The synced text panel — every real chunk of speech,
                 highlighted the moment playback reaches it. Never a
                 substitute for the caller's own curated shadow_lines
                 display below (if any); this is just "what's being said,
                 right now", the same idea as karaoke captions. --}}
            <div class="mt-4 max-h-56 space-y-1.5 overflow-y-auto rounded-2xl border border-line bg-surface-sunken p-3 text-sm dark:border-line-dark dark:bg-surface-sunken-dark">
                @foreach ($segments as $index => $segment)
                    <p
                        x-ref="segment-{{ $index }}"
                        x-on:click="seekTo({{ (float) $segment['start'] }}); $refs.audio.currentTime = {{ (float) $segment['start'] }}"
                        class="cursor-pointer rounded-lg px-1.5 py-0.5 transition-colors"
                        :class="activeSegmentIndex === {{ $index }}
                            ? 'bg-accent/15 font-semibold text-accent-ink dark:bg-accent-dark/25 dark:text-accent-ink-dark'
                            : 'text-ink-soft hover:text-ink dark:text-ink-soft-dark dark:hover:text-ink-dark'"
                    >{{ $segment['text'] }}</p>
                @endforeach
            </div>
        @endif

        @if (count($shadowLines))
            <div
                x-show="activeShadowIndex !== null"
                x-cloak
                x-transition.opacity.duration.200ms
                class="mt-4 rounded-2xl border border-accent/30 bg-accent/5 p-4 dark:border-accent-dark/30 dark:bg-accent-dark/10"
            >
                <p class="text-xs font-semibold tracking-wide text-accent-ink uppercase dark:text-accent-ink-dark">Now you say it</p>

                @foreach ($shadowLines as $index => $line)
                    <div x-show="activeShadowIndex === {{ $index }}" x-cloak>
                        <div class="mt-1 flex items-start justify-between gap-2">
                            <p class="text-sm text-ink dark:text-ink-dark">"<x-stress-marked-line :text="$line" />"</p>

                            <button
                                type="button"
                                x-on:click="replayShadowLine({{ $index }})"
                                title="Hear this line again"
                                class="inline-flex h-8 w-8 shrink-0 cursor-pointer items-center justify-center rounded-full border border-line text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-dark"
                            >@svg('heroicon-o-arrow-path', 'h-4 w-4')</button>
                        </div>
                    </div>
                @endforeach

                <p class="mt-1 text-xs text-ink-soft dark:text-ink-soft-dark">Try saying it out loud, then find this line below to record yourself.</p>

                <button
                    type="button"
                    x-on:click="resumeAfterShadow()"
                    class="mt-3 inline-flex cursor-pointer items-center gap-1.5 rounded-full bg-accent px-4 py-2 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
                >
                    @svg('heroicon-o-play', 'h-4 w-4') Continue listening
                </button>
            </div>
        @endif
    </div>
@endif
