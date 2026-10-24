{{--
    Shared markup for every Listen Again step (⚡daily-listen-2/3/4.blade.php)
    — identical UI, only the phase key (and its own small shadow-line
    pool) differs per file. Reuses Day 1's real Listening content for the
    audio; shadowing lines and their real pause timestamps are this day's
    own (see DailyListenStep::shadowLines()/shadowTimestamps()).

    Pure shadowing now — no separate transcript toggle. The synced text
    panel inside <x-audio-player> (fed listeningSegments()) already shows
    what's being said as it's said; a second, static "show transcript"
    block would just be the same information twice.

    The recorder for each shadow line lives in its OWN always-visible
    list below the player, not inside the auto-pause overlay itself —
    the overlay (see <x-audio-player>) is a helpful nudge (it pauses
    there and shows a replay button), never the only way to reach a
    line. A line Whisper keeps mis-hearing must never trap the learner
    mid-episode with no way to move on (Epic H4's always-available-retry
    principle) — they can keep listening AND keep retrying a tricky line
    in the list at the same time.

    @param \App\Models\MissionRun $run
    @param bool $readOnly
--}}
@php
    $listening = $run->mission->stepContent('listening');
    $shadowLines = $this->shadowLines();
    $shadowTimestamps = $this->shadowTimestamps();
@endphp

<div class="space-y-6">
    @if ($imageUrl = $this->heroImageUrl())
        <img src="{{ $imageUrl }}" alt="" class="h-32 w-full rounded-2xl object-cover">
    @endif

    <x-hook :text="$this->hook()" />

    <div>
        <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Listen Again</p>
        <p class="text-xs text-ink-faint dark:text-ink-faint-dark">{{ $listening['source'] ?? 'Listening' }}</p>
        @unless ($readOnly)
            <p class="mt-1 text-xs text-ink-soft dark:text-ink-soft-dark">It pauses at each line — say it, then record it below.</p>
        @endunless
        <div class="mt-2">
            <x-audio-player
                text-hidden
                :url="$listening['audio_url'] ?? null"
                :listen="$preview ? null : ['mission_code' => $run->mission->code, 'source' => \App\Models\AudioListen::SOURCE_LISTENING, 'day_number' => $this->dayNumber(), 'manual_report' => true]"
                :segments="$this->listeningSegments()"
                :shadow-lines="$readOnly ? [] : $shadowLines"
                :shadow-timestamps="$readOnly ? [] : $shadowTimestamps"
                :resume-key="$readOnly || $preview ? null : $this->draftPrefix().'position'"
            />
        </div>
    </div>

    {{-- Shadowing — small (this day's own 2 lines), mandatory, leniently
         AI-graded (see ChecksShadowLines::checkShadowLine()). Never gated
         on having played the audio — the recordings themselves are the
         evidence, and a player that won't play must not trap anyone. --}}
    <div class="space-y-3">
        <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Shadow these lines</p>

        @foreach ($shadowLines as $index => $line)
            @php $shadowKey = "shadow_{$index}"; @endphp
            <div class="rounded-xl border border-line p-3 dark:border-line-dark">
                <p x-lookup class="text-sm text-ink dark:text-ink-dark">"<x-stress-marked-line :text="$line" />"</p>

                @if ($readOnly)
                    @if ($url = $savedShadowUrls[$index] ?? null)
                        <div class="mt-2"><x-audio-player :url="$url" /></div>
                    @else
                        <p class="mt-2 text-xs text-ink-faint dark:text-ink-faint-dark">Not shadowed.</p>
                    @endif
                @else
                    <div class="mt-2" wire:key="{{ $this->phaseKey() }}-shadow-recorder-{{ $index }}">
                        <x-voice-recorder
                            field="shadowRecordings.{{ $index }}"
                            :file="$draftShadowUrls[$index] ?? null"
                            file-name="{{ $this->phaseKey() }}-shadow-{{ $index }}.webm"
                            on-recorded="checkShadowLine"
                            :on-recorded-param="$index"
                        />
                    </div>
                    <x-shadow-line-check :index="$index" :feedback="$feedback[$shadowKey] ?? null" :error="$checkErrors[$shadowKey] ?? null" />
                @endif
            </div>
        @endforeach

        @error('shadowRecordings')
            <p class="text-sm text-danger-ink">{{ $message }}</p>
        @enderror
    </div>

    @unless ($readOnly)
        @php $shadowedEnough = $this->shadowedCount() >= count($shadowLines); @endphp
        <x-sticky-bar ready-when="{{ $shadowedEnough ? 'true' : 'false' }}" hint="Shadow every line to continue">
            <button
                type="button"
                wire:click="save"
                @disabled(! $shadowedEnough)
                wire:loading.attr="disabled"
                wire:target="save"
                class="cursor-pointer rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 dark:bg-accent-dark"
            >
                <span wire:loading.remove wire:target="save">Continue</span>
                <span wire:loading wire:target="save">Saving…</span>
            </button>
        </x-sticky-bar>
    @endunless
</div>
