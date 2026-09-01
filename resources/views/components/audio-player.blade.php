{{--
    @param string|null $onEnded Raw Alpine statement(s) run whenever playback
        reaches the end — e.g. onEnded="$dispatch('audio-ended')" so a
        parent listening for that event (bubbles up the DOM) can count real
        completed listens, not just play clicks.
--}}
@props(['url', 'onEnded' => null])

@if (! empty($url))
    <div
        class="rounded-xl border border-line p-3 dark:border-line-dark"
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
        }"
    >
        <audio x-ref="audio" preload="auto" class="hidden">
            <source src="{{ $url }}" type="audio/mpeg">
        </audio>

        <div class="flex items-center gap-2">
            <button
                type="button"
                x-on:click="skip(-10)"
                class="inline-flex shrink-0 cursor-pointer items-center gap-0.5 rounded-full border border-line px-2 py-1 text-xs text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
            >@svg('heroicon-o-backward', 'h-3.5 w-3.5') 10s</button>

            <button
                type="button"
                x-on:click="togglePlay()"
                class="inline-flex shrink-0 cursor-pointer items-center gap-1 rounded-full bg-ink px-3 py-1 text-xs font-semibold text-ground transition-colors hover:opacity-85 dark:bg-ink-dark dark:text-ground-dark"
            >
                <span x-show="!playing" class="inline-flex items-center gap-1">@svg('heroicon-s-play', 'h-3.5 w-3.5') Play</span>
                <span x-show="playing" x-cloak class="inline-flex items-center gap-1">@svg('heroicon-s-pause', 'h-3.5 w-3.5') Pause</span>
            </button>

            <button
                type="button"
                x-on:click="skip(10)"
                class="inline-flex shrink-0 cursor-pointer items-center gap-0.5 rounded-full border border-line px-2 py-1 text-xs text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
            >10s @svg('heroicon-o-forward', 'h-3.5 w-3.5')</button>

            <span class="w-9 shrink-0 text-right text-xs text-ink-faint dark:text-ink-faint-dark" x-text="formatTime(currentTime)"></span>

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
                class="h-1.5 flex-1 cursor-pointer accent-accent"
            >

            <span class="w-9 shrink-0 text-xs text-ink-faint dark:text-ink-faint-dark" x-text="formatTime(duration)"></span>

            <button
                type="button"
                x-on:click="cycleSpeed()"
                title="Playback speed"
                class="inline-flex w-11 shrink-0 cursor-pointer items-center justify-center rounded-full border border-line px-2 py-1 text-xs font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                x-text="speed + 'x'"
            ></button>

            <a
                href="{{ $url }}"
                download
                class="inline-flex shrink-0 cursor-pointer items-center gap-1 rounded-full border border-line px-2 py-1 text-xs text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
            >@svg('heroicon-o-arrow-down-tray', 'h-3.5 w-3.5') Download</a>
        </div>
    </div>
@endif
