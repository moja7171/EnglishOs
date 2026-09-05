{{--
    @param string|null $onEnded Raw Alpine statement(s) run whenever playback
        reaches the end — e.g. onEnded="$dispatch('audio-ended')" so a
        parent listening for that event (bubbles up the DOM) can count real
        completed listens, not just play clicks.
--}}
@props(['url', 'onEnded' => null])

@if (! empty($url))
    {{--
        wire:ignore (+ a wire:key scoped to the URL itself) so a Livewire
        re-render triggered by ANYTHING ELSE in the same component — an AI
        check, a wire:model sync, any action call — never touches this
        subtree while audio is mid-playback. Without it, Livewire's morph
        can tear down and recreate the <audio> element on every unrelated
        round-trip, aborting an in-flight play() with
        "AbortError: The play() request was interrupted by a call to
        pause()" — a real bug hit in production, not a hypothetical one.
        The wire:key still lets Livewire fully replace this element (new
        DOM node, ignore doesn't block that) whenever $url itself actually
        changes, e.g. voice-recorder's re-record-then-preview-again flow.
    --}}
    <div
        wire:ignore
        wire:key="audio-player-{{ md5($url) }}"
        class="rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark"
        x-data="{
            playing: false,
            currentTime: 0,
            duration: 0,
            dragging: false,
            speed: 1,
            init() {
                const audio = this.$refs.audio;
                const seek = this.$refs.seek;
                audio.addEventListener('loadedmetadata', () => this.duration = audio.duration);
                audio.addEventListener('timeupdate', () => {
                    this.currentTime = audio.currentTime;
                    // Imperative, not an Alpine :value binding — a reactive
                    // binding fighting the browser's own drag position is
                    // what caused seeking to snap back to 0.
                    if (! this.dragging) seek.value = audio.currentTime;
                });
                audio.addEventListener('play', () => this.playing = true);
                audio.addEventListener('pause', () => this.playing = false);
                audio.addEventListener('ended', () => { this.playing = false; {{ $onEnded }} });
                // Metadata may already have loaded before this listener was attached.
                if (audio.readyState >= 1) this.duration = audio.duration;
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
        }"
    >
        <audio x-ref="audio" preload="auto" class="hidden">
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
    </div>
@endif
