{{--
    Shared markup for every Daily Listening step (⚡daily-listen-2/3/4.blade.php)
    — identical UI, only the phase key (and its own small shadow-line
    pool) differs per file. Reuses Day 1's real Listening content for the
    audio/transcript; shadowing lines are this day's own (see
    DailyListenStep::shadowLines()).

    @param \App\Models\MissionRun $run
    @param bool $readOnly
    @param bool $listened
--}}
@php
    $listening = $run->mission->stepContent('listening');
    $shadowLines = $this->shadowLines();
@endphp

<div
    class="space-y-6"
    data-listened="{{ $listened ? '1' : '' }}"
    x-data="{
        hasListened: false,
        showTranscript: false,
        init() { this.hasListened = this.$el.dataset.listened === '1' },
    }"
    x-on:audio-ended="hasListened = true; $wire.markListened()"
>
    @if ($imageUrl = $this->heroImageUrl())
        <img src="{{ $imageUrl }}" alt="" class="h-32 w-full rounded-2xl object-cover">
    @endif

    <x-hook :text="$this->hook()" />

    <div>
        <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Daily Listening</p>
        <p class="text-xs text-ink-faint dark:text-ink-faint-dark">{{ $listening['source'] ?? 'Listening' }}</p>
        <div class="mt-2">
            <x-audio-player :url="$listening['audio_url'] ?? null" on-ended="$dispatch('audio-ended')" />
        </div>
    </div>

    @if (count($listening['transcript'] ?? []))
        <div>
            <button
                type="button"
                x-on:click="showTranscript = !showTranscript"
                class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-line px-3 py-1.5 text-xs font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
            >
                @svg('heroicon-o-document-text', 'h-3.5 w-3.5')
                <span x-show="!showTranscript">Show transcript</span>
                <span x-show="showTranscript" x-cloak>Hide transcript</span>
            </button>

            <div
                x-show="showTranscript"
                x-cloak
                x-transition.opacity.duration.200ms
                class="mt-2 max-h-72 space-y-2.5 overflow-y-auto rounded-2xl border border-line bg-surface-sunken p-4 dark:border-line-dark dark:bg-surface-sunken-dark"
            >
                @foreach ($listening['transcript'] as $index => $line)
                    @php $altSpeaker = $index % 2 === 1; @endphp
                    <div class="flex {{ $altSpeaker ? 'justify-end' : 'justify-start' }}">
                        <div class="max-w-[85%] rounded-2xl px-3 py-2 text-sm
                            {{ $altSpeaker
                                ? 'bg-accent-soft text-ink dark:bg-accent-soft-dark dark:text-ink-dark'
                                : 'border border-line bg-surface text-ink dark:border-line-dark dark:bg-surface-dark dark:text-ink-dark' }}">
                            <p class="text-[11px] font-semibold tracking-wide uppercase
                                {{ $altSpeaker ? 'text-accent-ink dark:text-accent-ink-dark' : 'text-ink-faint dark:text-ink-faint-dark' }}">
                                {{ $line['speaker'] ?? '' }}
                            </p>
                            <p class="mt-0.5">{{ $line['text'] ?? '' }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Shadowing — small (this day's own 2 lines), mandatory, leniently
         AI-graded (see DailyListenStep::checkShadowLine()). Only appears
         once the learner has actually heard the episode once today. --}}
    <div x-show="hasListened || {{ $readOnly ? 'true' : 'false' }}" @unless($readOnly) x-cloak @endunless class="space-y-3">
        <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Shadow these lines</p>

        @foreach ($shadowLines as $index => $line)
            @php $shadowKey = "shadow_{$index}"; $shadowFeedback = $feedback[$shadowKey] ?? null; @endphp
            <div class="rounded-xl border border-line p-3 dark:border-line-dark">
                <p class="text-sm text-ink dark:text-ink-dark">"<x-stress-marked-line :text="$line" />"</p>

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
                            :file="$shadowRecordings[$index] ?? null"
                            file-name="{{ $this->phaseKey() }}-shadow-{{ $index }}.webm"
                            on-recorded="checkShadowLine"
                            :on-recorded-param="$index"
                        />
                    </div>
                    <x-ai-thinking wire:loading wire:target="checkShadowLine({{ $index }})" class="mt-2" label="Listening to your recording…" />
                    @if ($shadowFeedback)
                        <p class="mt-2 text-xs {{ $shadowFeedback['severity'] === 'none' ? 'text-success dark:text-success-dark' : 'text-amber-600' }}">
                            @if ($shadowFeedback['severity'] === 'none')
                                @svg('heroicon-o-check-circle', 'inline h-3.5 w-3.5') Nice — that counts.
                            @else
                                {{ $shadowFeedback['hint'] }}
                            @endif
                        </p>
                    @endif
                    @if ($checkErrors[$shadowKey] ?? null)
                        <p class="mt-2 text-xs text-red-600">{{ $checkErrors[$shadowKey] }}</p>
                    @endif
                @endif
            </div>
        @endforeach

        @error('shadowRecordings')
            <p class="text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    @unless ($readOnly)
        <p x-show="!hasListened" class="text-xs text-ink-faint dark:text-ink-faint-dark">Listen at least once to continue.</p>

        @php $shadowedEnough = $this->shadowedCount() >= count($shadowLines); @endphp
        <x-sticky-bar ready-when="hasListened && {{ $shadowedEnough ? 'true' : 'false' }}" hint="Listen once, then shadow every line">
            <button
                type="button"
                wire:click="save"
                x-bind:disabled="! hasListened || {{ $shadowedEnough ? 'false' : 'true' }}"
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
