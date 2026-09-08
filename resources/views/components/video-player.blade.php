{{--
    A self-hosted <video>-based player with custom overlay controls — built
    to replace <x-youtube-embed> where the source video is ours to host
    (public-domain or otherwise cleared content), not a third-party embed.

    Same wire:ignore + url-scoped wire:key protection as <x-audio-player>
    (see that component for why: an unrelated Livewire round-trip morphing
    this subtree mid-playback aborts playback with a real AbortError).

    @param string $url Video source (mp4).
    @param string|null $captionsUrl WebVTT captions track, if any. The CC
        toggle only renders when this is given.
    @param string|null $poster Poster frame shown before first play.
    @param string $title Accessible label for the video element.
    @param string|null $onEnded Raw Alpine statement(s) run on playback end
        — same convention as <x-audio-player>'s $onEnded.
--}}
@props(['url', 'captionsUrl' => null, 'poster' => null, 'title' => 'Video', 'onEnded' => null])

@if (! empty($url))
    <div
        wire:ignore
        wire:key="video-player-{{ md5($url) }}"
        tabindex="0"
        x-data="{
            playing: false,
            currentTime: 0,
            duration: 0,
            dragging: false,
            speed: 1,
            muted: false,
            captionsOn: {{ $captionsUrl ? 'true' : 'false' }},
            controlsVisible: true,
            hideTimer: null,
            fullscreen: false,
            init() {
                const video = this.$refs.video;
                const seek = this.$refs.seek;
                video.addEventListener('loadedmetadata', () => this.duration = video.duration);
                video.addEventListener('timeupdate', () => {
                    this.currentTime = video.currentTime;
                    if (! this.dragging) seek.value = video.currentTime;
                });
                video.addEventListener('play', () => { this.playing = true; this.scheduleHide() });
                video.addEventListener('pause', () => { this.playing = false; this.showControls() });
                video.addEventListener('ended', () => { this.playing = false; this.showControls(); {{ $onEnded }} });
                if (video.readyState >= 1) this.duration = video.duration;

                if (this.$refs.track) {
                    this.$refs.track.track.mode = this.captionsOn ? 'showing' : 'hidden';
                }

                document.addEventListener('fullscreenchange', () => {
                    this.fullscreen = document.fullscreenElement === video;
                });
                // iOS's non-standard fullscreen path fires its own events
                // instead of the standard fullscreenchange above.
                video.addEventListener('webkitbeginfullscreen', () => this.fullscreen = true);
                video.addEventListener('webkitendfullscreen', () => this.fullscreen = false);
            },
            togglePlay() { this.playing ? this.$refs.video.pause() : this.$refs.video.play() },
            cycleSpeed() {
                const speeds = [0.5, 0.75, 1];
                this.speed = speeds[(speeds.indexOf(this.speed) + 1) % speeds.length];
                this.$refs.video.playbackRate = this.speed;
            },
            toggleMute() {
                this.muted = ! this.muted;
                this.$refs.video.muted = this.muted;
            },
            toggleCaptions() {
                this.captionsOn = ! this.captionsOn;
                if (this.$refs.track) this.$refs.track.track.mode = this.captionsOn ? 'showing' : 'hidden';
            },
            toggleFullscreen() {
                // Fullscreens the <video> element itself, not this wrapper
                // — tried fullscreening the wrapper first (to keep the
                // custom overlay visible), but Android Chrome has a real,
                // separate reliability problem there: a <video> inside an
                // arbitrary fullscreened element sometimes never
                // composites its hardware decode surface at all, showing
                // solid black regardless of any CSS on the wrapper (this
                // was tried — overflow-hidden wasn't actually the cause).
                // Fullscreening the <video> directly is the standard,
                // hardware-optimized path every browser gets right; the
                // real cost is losing the custom overlay for the OS's own
                // native video controls during fullscreen (see the
                // :controls binding below) — reliability over polish.
                const video = this.$refs.video;

                if (document.fullscreenElement === video || document.webkitFullscreenElement === video) {
                    (document.exitFullscreen || document.webkitExitFullscreen)?.call(document);
                    return;
                }

                if (video.requestFullscreen) {
                    video.requestFullscreen();
                } else if (video.webkitEnterFullscreen) {
                    // iOS Safari: the only fullscreen path it has at all.
                    video.webkitEnterFullscreen();
                } else if (video.webkitRequestFullscreen) {
                    video.webkitRequestFullscreen();
                }
            },
            skip(seconds) {
                const video = this.$refs.video;
                const max = video.duration || this.duration || Infinity;
                const time = Math.min(Math.max(video.currentTime + seconds, 0), max);
                video.currentTime = time;
                this.currentTime = time;
                this.$refs.seek.value = time;
            },
            seekTo(value) {
                this.currentTime = value;
                this.$refs.video.currentTime = value;
            },
            formatTime(t) {
                if (!t || isNaN(t)) return '0:00';
                const m = Math.floor(t / 60);
                const s = Math.floor(t % 60).toString().padStart(2, '0');
                return m + ':' + s;
            },
            get progressPercent() { return this.duration ? (this.currentTime / this.duration * 100) : 0 },
            showControls() {
                this.controlsVisible = true;
                this.scheduleHide();
            },
            scheduleHide() {
                clearTimeout(this.hideTimer);
                if (! this.playing) return;
                this.hideTimer = setTimeout(() => { if (this.playing) this.controlsVisible = false }, 2500);
            },
            onKeydown(e) {
                const key = e.key;
                if (key === ' ' || key === 'k') { e.preventDefault(); this.togglePlay(); this.showControls(); return; }
                if (key === 'ArrowRight') { e.preventDefault(); this.skip(5); this.showControls(); return; }
                if (key === 'ArrowLeft') { e.preventDefault(); this.skip(-5); this.showControls(); return; }
                if (key === 'm') { this.toggleMute(); return; }
                if (key === 'f') { this.toggleFullscreen(); return; }
                if (key === 'c' && {{ $captionsUrl ? 'true' : 'false' }}) { this.toggleCaptions(); return; }
            },
        }"
        x-on:keydown="onKeydown($event)"
        x-on:mousemove="showControls()"
        x-on:mouseleave="if (playing) controlsVisible = false"
        class="group relative aspect-video w-full overflow-hidden rounded-2xl bg-black outline-none focus-visible:ring-2 focus-visible:ring-accent dark:focus-visible:ring-accent-dark"
    >
        <video
            x-ref="video"
            class="h-full w-full cursor-pointer"
            @if ($poster) poster="{{ $poster }}" @endif
            preload="metadata"
            playsinline
            crossorigin="anonymous"
            :controls="fullscreen"
            x-on:click="if (!fullscreen) togglePlay()"
        >
            <source src="{{ $url }}" type="video/mp4">
            @if ($captionsUrl)
                <track x-ref="track" kind="captions" src="{{ $captionsUrl }}" srclang="en" label="English" default>
            @endif
        </video>

        {{-- Center play button — shown whenever paused, standard
             video-player convention rather than requiring the tiny
             bottom-bar button to be found first. --}}
        <button
            type="button"
            x-show="!playing && !fullscreen"
            x-on:click="togglePlay()"
            class="absolute inset-0 flex items-center justify-center bg-black/20 transition-opacity"
        >
            <span class="inline-flex h-16 w-16 items-center justify-center rounded-full bg-accent/90 text-white shadow-lg transition-transform hover:scale-105 dark:bg-accent-dark/90">
                @svg('heroicon-s-play', 'ml-1 h-7 w-7')
            </span>
        </button>

        {{-- Bottom control bar — fades with the video, not independently,
             so it never lingers as a translucent strip over black bars on
             a differently-shaped video. --}}
        <div
            x-show="(controlsVisible || !playing) && !fullscreen"
            x-transition.opacity.duration.200ms
            class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/85 via-black/40 to-transparent pt-8 pb-2"
        >
            <div class="relative flex h-3 items-center px-3">
                <div class="absolute inset-x-3 h-1 rounded-full bg-white/25"></div>
                <div class="absolute h-1 rounded-full bg-accent dark:bg-accent-dark" :style="`width: calc(${progressPercent}% * (100% - 24px) / 100%)`"></div>
                <input
                    type="range"
                    x-ref="seek"
                    min="0"
                    step="0.1"
                    :max="duration || 0"
                    value="0"
                    x-on:pointerdown="dragging = true"
                    x-on:pointerup="dragging = false"
                    x-on:input="seekTo($event.target.valueAsNumber)"
                    class="relative h-3 w-full cursor-pointer appearance-none bg-transparent
                        [&::-moz-range-thumb]:h-3 [&::-moz-range-thumb]:w-3 [&::-moz-range-thumb]:appearance-none
                        [&::-moz-range-thumb]:rounded-full [&::-moz-range-thumb]:border-0 [&::-moz-range-thumb]:bg-accent
                        [&::-moz-range-track]:bg-transparent
                        [&::-webkit-slider-runnable-track]:bg-transparent
                        [&::-webkit-slider-thumb]:h-3 [&::-webkit-slider-thumb]:w-3 [&::-webkit-slider-thumb]:appearance-none
                        [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:bg-accent"
                >
            </div>

            <div class="mt-1 flex items-center gap-3 px-3 pb-1 text-white">
                <button type="button" x-on:click="togglePlay()" class="cursor-pointer">
                    <span x-show="!playing">@svg('heroicon-s-play', 'h-5 w-5')</span>
                    <span x-show="playing" x-cloak>@svg('heroicon-s-pause', 'h-5 w-5')</span>
                </button>

                <button type="button" x-on:click="toggleMute()" class="cursor-pointer" title="Mute (m)">
                    <span x-show="!muted">@svg('heroicon-o-speaker-wave', 'h-5 w-5')</span>
                    <span x-show="muted" x-cloak>@svg('heroicon-o-speaker-x-mark', 'h-5 w-5')</span>
                </button>

                <span class="text-xs tabular-nums text-white/90">
                    <span x-text="formatTime(currentTime)"></span> / <span x-text="formatTime(duration)"></span>
                </span>

                <div class="flex-1"></div>

                @if ($captionsUrl)
                    <button
                        type="button"
                        x-on:click="toggleCaptions()"
                        title="Captions (c)"
                        class="cursor-pointer rounded px-1.5 py-0.5 text-[11px] font-bold"
                        :class="captionsOn ? 'bg-white text-black' : 'border border-white/60 text-white/80'"
                    >CC</button>
                @endif

                <button
                    type="button"
                    x-on:click="cycleSpeed()"
                    title="Playback speed"
                    class="cursor-pointer text-xs font-semibold tabular-nums"
                    x-text="speed + 'x'"
                ></button>

                <button type="button" x-on:click="toggleFullscreen()" class="cursor-pointer" title="Fullscreen (f)">
                    @svg('heroicon-o-arrows-pointing-out', 'h-4.5 w-4.5')
                </button>
            </div>
        </div>
    </div>
@endif
