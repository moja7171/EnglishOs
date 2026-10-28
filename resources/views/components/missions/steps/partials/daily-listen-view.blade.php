{{--
    Shared markup for every Listen Again step (⚡daily-listen-2/3/4.blade.php)
    — identical UI, only the phase key (and its own small shadow-line
    pool) differs per file. Reuses Day 1's real Listening content for the
    audio; shadowing lines and their real pause timestamps are this day's
    own (see DailyListenStep::shadowLines()/shadowTimestamps()).

    One task per screen: the start, then the whole episode to LISTEN to first
    (with its synced text and the "Listens: N" count), then each shadow line
    on its own screen — "▶ Hear it" plays only that line out of the episode
    (its real Whisper timing), the big mic, the check from the bottom, and
    Continue after any take. The last line's button is Finish.

    @param \App\Models\MissionRun $run
    @param bool $readOnly
--}}
@php
    $listening = $run->mission->stepContent('listening');
    $shadowLines = $this->shadowLines();
    $shadowTimestamps = $this->shadowTimestamps();
    $lineCount = count($shadowLines);
    // Screens: 0 start · 1 the whole episode (listen first) · 2… one line each.
    $wholeTalkScreen = 1;
    $firstLineScreen = 2;
    $screenCount = $lineCount + 2;
    $listenParams = $preview ? null : ['mission_code' => $run->mission->code, 'source' => \App\Models\AudioListen::SOURCE_LISTENING, 'day_number' => $this->dayNumber(), 'manual_report' => true];
@endphp

@if ($readOnly)
    <div class="flex flex-col gap-4 pt-2">
        <x-step-intro kind="Listen Again" :title="$this->hook()" :image="$this->heroImageUrl" />
        @foreach ($shadowLines as $index => $line)
            <div class="flex flex-col gap-2 rounded-2xl bg-surface p-4 dark:bg-surface-dark">
                <p class="text-sm font-semibold text-ink dark:text-ink-dark">“<x-stress-marked-line :text="$line" />”</p>
                @if ($url = $savedShadowUrls[$index] ?? null)
                    <x-audio-player :url="$url" />
                @else
                    <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Not shadowed.</p>
                @endif
            </div>
        @endforeach
    </div>
@else
<div
    x-data="{
        screen: 0,
        clipEnd: null,
        points: {{ \Illuminate\Support\Js::from($shadowTimestamps) }},
        hear(index, line) {
            const point = this.points[index];
            const audio = this.$refs.clip;
            if (! point || ! audio) { window.eosVoice?.speak(line); return; }
            this.clipEnd = point.end;
            audio.currentTime = point.start;
            audio.play();
        },
    }"
    x-effect="$dispatch('step-progress', { share: (screen + 0.5) / {{ $screenCount }} }); if (screen !== {{ $wholeTalkScreen }}) $el.querySelector('[data-whole-talk] audio')?.pause()"
    x-back-close.step="{ open: screen > 0, close: () => screen-- }"
>
    {{-- Plays one line at a time out of the episode for "Hear it". --}}
    <audio x-ref="clip" preload="auto" class="hidden" wire:ignore x-on:timeupdate="if (clipEnd !== null && $el.currentTime >= clipEnd) { $el.pause(); clipEnd = null }">
        <source src="{{ $listening['audio_url'] ?? '' }}" type="audio/mpeg">
    </audio>

    <x-lesson-screen when="screen === 0">
        <x-step-intro kind="Listen Again" :minutes="$run->mission->stepDuration($this->phaseKey())" :title="$this->hook()" :image="$this->heroImageUrl" />

        <x-slot:actions>
            <x-continue-button on-click="screen = {{ $wholeTalkScreen }}" wire-target="save">Start</x-continue-button>
        </x-slot:actions>
    </x-lesson-screen>

    <x-lesson-screen when="screen === {{ $wholeTalkScreen }}">
        <span class="inline-flex w-fit rounded-full bg-accent-soft px-3 py-1 text-xs font-extrabold tracking-wide text-accent-ink uppercase dark:bg-accent-soft-dark dark:text-accent-ink-dark">Listen first</span>
        <div data-whole-talk>
            <x-audio-player
                podcast
                :url="$listening['audio_url'] ?? null"
                :listen="$listenParams"
                :segments="$this->listeningSegments()"
                :resume-key="$preview ? null : $this->draftPrefix().'position'"
            />
        </div>
        <x-slot:actions>
            <x-continue-button on-click="screen = {{ $firstLineScreen }}" wire-target="save" />
        </x-slot:actions>
    </x-lesson-screen>
    @foreach ($shadowLines as $index => $line)
        <x-shadow-line-screen
            when="screen === {{ $index + $firstLineScreen }}"
            :index="$index"
            :line="$line"
            kind="Say it · {{ $index + 1 }} of {{ $lineCount }}"
            hear="$refs.clip.pause(); hear({{ $index }}, {{ \Illuminate\Support\Js::from(strip_tags(str_replace('**', '', $line))) }})"
            :recording-url="$draftShadowUrls[$index] ?? null"
            file-name="{{ $this->phaseKey() }}-shadow-{{ $index }}.webm"
            :feedback="$feedback['shadow_'.$index] ?? null"
            :error="$checkErrors['shadow_'.$index] ?? null"
            next="$refs.clip.pause(); {{ $index === $lineCount - 1 ? '$wire.save()' : 'screen++' }}"
            :finish="$index === $lineCount - 1"
            wire-target="checkShadowLine,save"
        />
    @endforeach


    @error('shadowRecordings')
        <p class="text-sm text-danger-ink">{{ $message }}</p>
    @enderror
</div>
@endif
