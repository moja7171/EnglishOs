{{--
    A self-hosted <video>-based player with custom overlay controls — built
    to replace <x-youtube-embed> where the source video is ours to host
    (public-domain or otherwise cleared content), not a third-party embed.

    wire:ignore + url-scoped wire:key protect just the <video> tag itself
    (see <x-audio-player> for why: an unrelated Livewire round-trip
    morphing it mid-playback aborts playback with a real AbortError) —
    narrower than the whole component, same reasoning as that component's
    own comment: the new shadow-pause UI below needs the rest of this
    wrapper to keep morphing normally, or its own Livewire-driven state
    (recording feedback, etc.) would never reach the DOM.

    @param string $url Video source (mp4).
    @param string|null $captionsUrl WebVTT captions track, if any. The CC
        toggle only renders when this is given — it shows real subtitles
        over the picture; the $segments panel below is the same text as a
        scrolling, tap-to-seek list. Three layouts, all just this same
        markup restyled (the <video> never moves, so playback carries on
        uninterrupted): "inline" on the page; "focus", entered automatically
        when playback starts and a text panel exists — the whole component
        full screen, the video in the top third and the text filling the
        rest; and "video", the arrows button (or f) — the video alone full
        screen, with the CC toggle still in its control bar.
    @param string|null $poster Poster frame shown before first play.
    @param string $title Accessible label for the video element.
    @param string|null $onEnded Raw Alpine statement(s) run on playback end
        — same convention as <x-audio-player>'s $onEnded.
    @param list<array{text: string, start: float, end: float}> $segments
    @param bool $textHidden Starts the text panel closed behind a "Show
        text" button; while it is closed, play does not enter "focus".
    @param list<string> $shadowLines
    @param list<array{start: float, end: float}|null> $shadowTimestamps
        See <x-audio-player> for the full behavior these three drive —
        identical mechanism, just against a <video> element.
    @param string $shadowActive Raw Alpine boolean expression (same
        convention as $onEnded) gating whether a shadow timestamp is
        allowed to auto-pause playback. Defaults to always-on; callers
        with their own sub-step UI (e.g. video-shadowing, whose recorder
        cards only exist on its shadowing sub-step) pass an expression
        tied to that state so the pause-and-prompt never fires on a
        sub-step where "find this line below" wouldn't be true yet.
    @param array{mission_code: string, source: string, day_number?: int, manual_report?: bool}|null $listen Counts
        real watches (at least 90% played through, never skipped to) and
        shows a "Listens: N" chip — see <x-audio-player>. null = no counting.
    @param string|null $resumeKey Comes back where the learner left the
        video — see <x-audio-player>'s $resumeKey, same behavior.
--}}
@props([
    'url',
    'listen' => null,
    'captionsUrl' => null,
    'poster' => null,
    'title' => 'Video',
    'onEnded' => null,
    'segments' => [],
    'textHidden' => false,
    'shadowLines' => [],
    'shadowTimestamps' => [],
    'shadowActive' => 'true',
    'resumeKey' => null,
])

@if (! empty($url))
    <div
        :class="mode === 'focus' ? 'fixed inset-0 z-50 flex flex-col overflow-hidden bg-ground p-3 sm:p-6 landscape:flex-row landscape:gap-4 dark:bg-ground-dark' : ''"
        x-on:keydown.escape.window="closeExpanded()"
        x-back-close="{ open: mode !== 'inline', close: () => closeExpanded() }"
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
            mode: 'inline',
            returnMode: 'inline',
            hasSegments: {{ count($segments) ? 'true' : 'false' }},
            showText: {{ $textHidden ? 'false' : 'true' }},
            segments: {{ Illuminate\Support\Js::from($segments) }},
            shadowTimestamps: {{ Illuminate\Support\Js::from($shadowTimestamps) }},
            shadowSeen: [],
            activeShadowIndex: null,
            replayEndTime: null,
            lastShadowCheck: null,
            shadowFrame: null,
            resumedFrom: null,
            stopResume: null,
            init() {
                const video = this.$refs.video;
                const seek = this.$refs.seek;
                video.addEventListener('loadedmetadata', () => this.duration = video.duration);
                video.addEventListener('timeupdate', () => {
                    this.currentTime = video.currentTime;
                    if (! this.dragging) seek.value = video.currentTime;

                    this.checkShadowPoints(video);
                });
                video.addEventListener('seeking', () => this.lastShadowCheck = null);
                video.addEventListener('play', () => {
                    this.playing = true;
                    this.resumedFrom = null;
                    this.watchShadowPoints(video);
                    this.scheduleHide();
                    if (this.mode === 'inline' && this.hasSegments && this.showText) this.mode = 'focus';
                });
                video.addEventListener('pause', () => { this.playing = false; this.showControls() });
                video.addEventListener('ended', () => {
                    this.playing = false;
                    this.showControls();
                    // The full-screen layouts cover the step's Continue; the end of the video brings the page back.
                    if (this.mode !== 'inline') this.mode = 'inline';
                    {{ $onEnded }}
                });
                if (video.readyState >= 1) this.duration = video.duration;

                @if ($listen)
                    window.eosListenTracker(video, (duration) => {
                        Livewire.dispatchTo('listen-counter', 'listen-completed', {
                            missionCode: {{ Illuminate\Support\Js::from($listen['mission_code']) }},
                            source: {{ Illuminate\Support\Js::from($listen['source']) }},
                            pickKey: {{ Illuminate\Support\Js::from($listen['pick_key'] ?? null) }},
                            duration: duration,
                        });
                    }, 0.9, null, 0.6);
                @endif

                if (this.$refs.track) {
                    this.$refs.track.track.mode = this.captionsOn ? 'showing' : 'hidden';
                }

                @if ($resumeKey)
                    this.stopResume = window.eosResume(video, {{ Illuminate\Support\Js::from($resumeKey) }}, {
                        snapTo: (time) => this.lineStartAt(time),
                        onRestored: (time) => { this.resumedFrom = time; this.currentTime = time; seek.value = time; },
                    });
                @endif

                // Shadowing is never a setting — it is simply what the
                // second watch is. The moment the caller's shadow-active
                // turns on, the video restarts from the top with every
                // line still to come, so the pauses cover the whole video.
                this.$watch(() => Boolean({{ $shadowActive }}), (on) => {
                    if (! on || ! this.shadowTimestamps.length) return;
                    video.pause();
                    video.currentTime = 0;
                    this.resumedFrom = null;
                    this.shadowSeen = [];
                    this.activeShadowIndex = null;
                    this.replayEndTime = null;
                });

                // Scrolls only the text panel, never the page — an element's
                // scrollIntoView also scrolls every scrollable ancestor,
                // which pushed the video itself off screen on each new line.
                this.$watch('activeSegmentIndex', (index) => {
                    this.$nextTick(() => this.centerSegment(index));
                });

                this.$watch('mode', (mode) => {
                    document.documentElement.classList.toggle('overflow-hidden', mode !== 'inline');
                    this.$nextTick(() => this.centerSegment(this.activeSegmentIndex));
                });
            },
            destroy() {
                if (this.mode !== 'inline') document.documentElement.classList.remove('overflow-hidden');
                this.stopResume?.();
            },
            centerSegment(index) {
                const panel = this.$refs.segmentsPanel;
                const segment = this.$refs['segment-' + index];
                if (! panel || ! segment) return;
                panel.scrollTo({top: segment.offsetTop - (panel.clientHeight - segment.clientHeight) / 2, behavior: 'smooth'});
            },
            get activeSegmentIndex() {
                for (let i = this.segments.length - 1; i >= 0; i--) {
                    if (this.currentTime >= this.segments[i].start) return i;
                }
                return -1;
            },
            // Pauses right as a line has been heard through to its own
            // real end (so the learner repeats what they just heard) —
            // checked on every animation frame while playing, not just on
            // 'timeupdate', which only fires about every 250ms and let the
            // pause land a word or two into the next sentence. Only a
            // genuine play-through counts: a seek across a line's end
            // (lastShadowCheck reset on 'seeking') never fires its pause.
            checkShadowPoints(media) {
                const now = media.currentTime;
                const previous = this.lastShadowCheck;
                this.lastShadowCheck = now;

                if (this.replayEndTime !== null) {
                    if (now >= this.replayEndTime) {
                        media.pause();
                        this.replayEndTime = null;
                    }
                    return;
                }

                if (previous === null || this.activeShadowIndex !== null || ! ({{ $shadowActive }})) return;

                for (let i = 0; i < this.shadowTimestamps.length; i++) {
                    const point = this.shadowTimestamps[i];
                    if (! point || this.shadowSeen.includes(i)) continue;
                    if (previous < point.end && now >= point.end && now - point.end < 1) {
                        media.pause();
                        this.activeShadowIndex = i;
                        this.shadowSeen.push(i);
                        this.showControls();
                        break;
                    }
                }
            },
            watchShadowPoints(media) {
                if (! this.shadowTimestamps.length || this.shadowFrame) return;
                const tick = () => {
                    this.shadowFrame = null;
                    if (media.paused || media.ended) return;
                    this.checkShadowPoints(media);
                    this.shadowFrame = requestAnimationFrame(tick);
                };
                this.shadowFrame = requestAnimationFrame(tick);
            },
            // The start of the shadow line playing at `time`, so a resumed
            // watch never drops the learner into the middle of one.
            lineStartAt(time) {
                const line = this.shadowTimestamps.find((point) => point && point.start <= time && time < point.end);
                return line ? line.start : time;
            },
            replayShadowLine(index) {
                const point = this.shadowTimestamps[index];
                if (! point) return;
                this.$refs.video.currentTime = point.start;
                this.replayEndTime = point.end;
                this.$refs.video.play();
            },
            resumeAfterShadow() {
                this.activeShadowIndex = null;
                this.$refs.video.play();
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
            toggleVideoFullscreen() {
                if (this.mode === 'video') {
                    this.mode = this.returnMode;
                    return;
                }
                this.returnMode = this.mode;
                this.mode = 'video';
            },
            closeExpanded() {
                if (this.mode === 'video') this.mode = this.returnMode;
                else this.mode = 'inline';
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
                if (key === 'f') { this.toggleVideoFullscreen(); return; }
                if (key === 'c' && {{ $captionsUrl ? 'true' : 'false' }}) { this.toggleCaptions(); return; }
            },
        }"
    >
    @if ($listen)
        <div x-show="mode === 'inline'" class="mb-2 flex justify-end">
            <livewire:listen-counter
                :mission-code="$listen['mission_code']"
                :source="$listen['source']"
                :day-number="$listen['day_number'] ?? null"
                :pick-key="$listen['pick_key'] ?? null"
                :allow-manual-report="$listen['manual_report'] ?? false"
                :key="'listen-counter-'.$listen['mission_code'].'-'.$listen['source']"
            />
        </div>
    @endif

    <div
        tabindex="0"
        x-on:keydown="onKeydown($event)"
        x-on:mousemove="showControls()"
        x-on:mouseleave="if (playing) controlsVisible = false"
        :class="{
            'fixed! inset-0 z-50 aspect-auto! rounded-none!': mode === 'video',
            'h-[33dvh] shrink-0 aspect-auto! landscape:h-auto landscape:w-1/2 landscape:self-stretch': mode === 'focus',
        }"
        class="group relative aspect-video w-full overflow-hidden rounded-2xl bg-black outline-none focus-visible:ring-2 focus-visible:ring-accent dark:focus-visible:ring-accent-dark"
    >
        <video
            wire:ignore
            wire:key="video-el-{{ md5($url) }}"
            x-ref="video"
            class="h-full w-full cursor-pointer"
            @if ($poster) poster="{{ $poster }}" @endif
            preload="metadata"
            playsinline
            x-on:click="togglePlay()"
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
            x-show="!playing"
            x-on:click="togglePlay()"
            class="absolute inset-0 flex flex-col items-center justify-center gap-2 bg-black/20 transition-opacity"
        >
            <span class="inline-flex h-16 w-16 items-center justify-center rounded-full bg-accent/90 text-white shadow-lg transition-transform hover:scale-105 dark:bg-accent-dark/90">
                @svg('heroicon-s-play', 'ml-1 h-7 w-7')
            </span>
            @if ($resumeKey)
                <span x-show="resumedFrom !== null" x-cloak class="rounded-full bg-black/60 px-2.5 py-1 text-xs text-white">
                    Continue from <span x-text="formatTime(resumedFrom)"></span>
                </span>
            @endif
        </button>

        {{-- Bottom control bar — fades with the video, not independently,
             so it never lingers as a translucent strip over black bars on
             a differently-shaped video. --}}
        <div
            x-show="controlsVisible || !playing"
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

                <button type="button" x-on:click="toggleVideoFullscreen()" class="cursor-pointer" :title="mode === 'video' ? 'Exit full screen (f)' : 'Video full screen (f)'">
                    <span x-show="mode !== 'video'">@svg('heroicon-o-arrows-pointing-out', 'h-4.5 w-4.5')</span>
                    <span x-show="mode === 'video'" x-cloak>@svg('heroicon-o-arrows-pointing-in', 'h-4.5 w-4.5')</span>
                </button>
            </div>
        </div>

        <button
            type="button"
            x-show="mode !== 'inline'"
            x-cloak
            x-on:click="closeExpanded()"
            title="Close full screen (Esc)"
            class="absolute top-2 right-2 inline-flex h-9 w-9 cursor-pointer items-center justify-center rounded-full bg-black/60 text-white transition-colors hover:bg-black/80"
        >
            <span class="sr-only">Close full screen</span>
            @svg('heroicon-o-x-mark', 'h-5 w-5')
        </button>
    </div>

    {{-- Everything under the video. Normally just part of the page; in focus
         it becomes the area filling the rest of the screen beside/below the
         video, so the text panel and the shadow prompt stay on screen with
         it. --}}
    <div :class="mode === 'focus' ? 'flex min-h-0 flex-1 flex-col overflow-y-auto' : ''">
    @if (count($segments))
        <button
            type="button"
            x-show="mode === 'inline'"
            x-on:click="showText = ! showText"
            x-bind:aria-expanded="showText"
            class="mt-3 inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-line px-3 py-1.5 text-xs font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
        >
            @svg('heroicon-o-document-text', 'h-4 w-4')
            <span x-text="showText ? 'Hide text' : 'Show text'">{{ $textHidden ? 'Show text' : 'Hide text' }}</span>
        </button>

        {{-- Synced text panel, same idea as <x-audio-player>'s — inline
             while showText is on, and always in focus. --}}
        <div
            x-lookup
            x-ref="segmentsPanel"
            x-show="showText || mode === 'focus'"
            @if ($textHidden) x-cloak @endif
            :class="mode === 'focus' ? 'min-h-32 flex-1 text-base' : 'max-h-40 text-sm'"
            class="relative mt-3 space-y-1.5 overflow-y-auto card-sunken p-3"
        >
            @foreach ($segments as $index => $segment)
                <p
                    x-ref="segment-{{ $index }}"
                    x-on:click="seekTo({{ (float) $segment['start'] }}); $refs.video.currentTime = {{ (float) $segment['start'] }}"
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
            class="mt-3 rounded-2xl border border-accent/30 bg-accent/5 p-4 dark:border-accent-dark/30 dark:bg-accent-dark/10"
        >
            <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Now you say it</p>

            @foreach ($shadowLines as $index => $line)
                <div x-show="activeShadowIndex === {{ $index }}" x-cloak>
                    <div class="mt-1 flex items-start justify-between gap-2">
                        <p x-lookup class="text-sm text-ink dark:text-ink-dark">"<x-stress-marked-line :text="$line" />"</p>

                        <button
                            type="button"
                            x-on:click="replayShadowLine({{ $index }})"
                            title="Hear this line again"
                            class="inline-flex h-8 w-8 shrink-0 cursor-pointer items-center justify-center rounded-full border border-line text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-dark"
                        >@svg('heroicon-o-arrow-path', 'h-4 w-4')</button>
                    </div>
                </div>
            @endforeach

            <p class="mt-1 text-xs text-ink-soft dark:text-ink-soft-dark">
                <span x-show="mode === 'inline'">Repeat it out loud, then record it below.</span>
                <span x-show="mode !== 'inline'" x-cloak>Repeat it out loud, then close full screen (✕) to record it.</span>
            </p>

            <button
                type="button"
                x-on:click="resumeAfterShadow()"
                class="mt-3 inline-flex cursor-pointer items-center gap-1.5 rounded-full bg-accent px-4 py-2 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
            >
                @svg('heroicon-o-play', 'h-4 w-4') Continue watching
            </button>
        </div>
    @endif
    </div>
    </div>
@endif
