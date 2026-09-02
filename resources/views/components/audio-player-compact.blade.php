{{--
    Minimal voice-note player for inside a chat bubble — a play button, a
    slim seek line, and a duration readout in one row. The full
    <x-audio-player> (speed control, ±10s skip, download) belongs on
    mission/lesson audio where scrubbing matters; a chat bubble just needs
    "tap to play", the way WhatsApp/Telegram voice messages read.

    @param string|null $url
    @param bool $mine Colors the player to read on the bubble it sits in —
        white-on-accent for the sender's own bubble, ink-on-surface for the
        other person's.
--}}
@props(['url', 'mine' => false])

@if (! empty($url))
    <div
        class="flex min-w-48 items-center gap-2"
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
                    if (! this.dragging) seek.value = audio.currentTime;
                });
                audio.addEventListener('play', () => this.playing = true);
                audio.addEventListener('pause', () => this.playing = false);
                audio.addEventListener('ended', () => this.playing = false);
                if (audio.readyState >= 1) this.duration = audio.duration;
            },
            togglePlay() { this.playing ? this.$refs.audio.pause() : this.$refs.audio.play() },
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

        <button
            type="button"
            x-on:click="togglePlay()"
            class="inline-flex h-8 w-8 shrink-0 cursor-pointer items-center justify-center rounded-full transition-colors
                {{ $mine
                    ? 'bg-white/20 text-white hover:bg-white/30'
                    : 'bg-accent-soft text-accent-ink hover:bg-accent-soft/70 dark:bg-accent-soft-dark dark:text-accent-ink-dark' }}"
        >
            <span x-show="!playing">@svg('heroicon-s-play', 'ml-0.5 h-3.5 w-3.5')</span>
            <span x-show="playing" x-cloak>@svg('heroicon-s-pause', 'h-3.5 w-3.5')</span>
        </button>

        <div class="relative flex h-6 flex-1 items-center">
            <div class="absolute inset-x-0 h-1 rounded-full {{ $mine ? 'bg-white/25' : 'bg-surface-sunken dark:bg-surface-sunken-dark' }}"></div>
            <div
                class="absolute h-1 rounded-full {{ $mine ? 'bg-white' : 'bg-accent dark:bg-accent-dark' }}"
                :style="`width: ${progressPercent}%`"
            ></div>
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
                class="relative h-6 w-full cursor-pointer appearance-none bg-transparent
                    [&::-moz-range-thumb]:h-2.5 [&::-moz-range-thumb]:w-2.5 [&::-moz-range-thumb]:appearance-none
                    [&::-moz-range-thumb]:rounded-full [&::-moz-range-thumb]:border-0
                    [&::-moz-range-track]:bg-transparent
                    [&::-webkit-slider-runnable-track]:bg-transparent
                    [&::-webkit-slider-thumb]:h-2.5 [&::-webkit-slider-thumb]:w-2.5 [&::-webkit-slider-thumb]:appearance-none
                    [&::-webkit-slider-thumb]:rounded-full
                    {{ $mine
                        ? '[&::-moz-range-thumb]:bg-white [&::-webkit-slider-thumb]:bg-white'
                        : '[&::-moz-range-thumb]:bg-accent [&::-moz-range-thumb]:dark:bg-accent-dark [&::-webkit-slider-thumb]:bg-accent [&::-webkit-slider-thumb]:dark:bg-accent-dark' }}"
            >
        </div>

        <span
            class="w-8 shrink-0 text-[10px] tabular-nums {{ $mine ? 'text-white/75' : 'text-ink-faint dark:text-ink-faint-dark' }}"
            x-text="formatTime(playing ? currentTime : duration)"
        ></span>
    </div>
@endif
