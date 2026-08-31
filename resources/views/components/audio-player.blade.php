@props(['url'])

@if (! empty($url))
    <div
        class="rounded-lg border border-neutral-300 p-3 dark:border-neutral-700"
        x-data="{
            playing: false,
            currentTime: 0,
            duration: 0,
            dragging: false,
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
                audio.addEventListener('ended', () => this.playing = false);
                // Metadata may already have loaded before this listener was attached.
                if (audio.readyState >= 1) this.duration = audio.duration;
            },
            togglePlay() { this.playing ? this.$refs.audio.pause() : this.$refs.audio.play() },
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
                class="shrink-0 cursor-pointer rounded border border-neutral-300 px-2 py-1 text-xs text-neutral-600 transition-colors hover:border-neutral-400 hover:bg-neutral-100 dark:border-neutral-700 dark:text-neutral-400 dark:hover:bg-neutral-800"
            >&#8249;&#8249; 10s</button>

            <button
                type="button"
                x-on:click="togglePlay()"
                class="shrink-0 cursor-pointer rounded bg-neutral-900 px-3 py-1 text-xs font-semibold text-white transition-colors hover:bg-neutral-700 dark:bg-white dark:text-neutral-900 dark:hover:bg-neutral-200"
            >
                <span x-show="!playing">Play</span>
                <span x-show="playing" x-cloak>Pause</span>
            </button>

            <button
                type="button"
                x-on:click="skip(10)"
                class="shrink-0 cursor-pointer rounded border border-neutral-300 px-2 py-1 text-xs text-neutral-600 transition-colors hover:border-neutral-400 hover:bg-neutral-100 dark:border-neutral-700 dark:text-neutral-400 dark:hover:bg-neutral-800"
            >10s &#8250;&#8250;</button>

            <span class="w-9 shrink-0 text-right text-xs text-neutral-500" x-text="formatTime(currentTime)"></span>

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
                class="h-1.5 flex-1 cursor-pointer accent-neutral-900 dark:accent-white"
            >

            <span class="w-9 shrink-0 text-xs text-neutral-500" x-text="formatTime(duration)"></span>

            <a
                href="{{ $url }}"
                download
                class="shrink-0 rounded border border-neutral-300 px-2 py-1 text-xs text-neutral-600 transition-colors hover:border-neutral-400 hover:bg-neutral-100 dark:border-neutral-700 dark:text-neutral-400 dark:hover:bg-neutral-800"
            >Download</a>
        </div>
    </div>
@endif
