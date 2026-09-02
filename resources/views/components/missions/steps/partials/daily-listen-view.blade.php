{{--
    Shared markup for every Daily Listening step (⚡daily-listen-2/3/4.blade.php)
    — identical UI, only the phase key differs per file. Reuses Day 1's
    real Listening content: the same audio, the same transcript.

    @param \App\Models\MissionRun $run
    @param bool $readOnly
    @param bool $listened
--}}
@php $listening = $run->mission->stepContent('listening'); @endphp

<div class="space-y-6" x-data="{ hasListened: {{ $listened ? 'true' : 'false' }} }" x-on:audio-ended="hasListened = true; $wire.markListened()">
    <x-hook :text="$this->hook()" />

    <div>
        <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Daily Listening</p>
        <p class="text-xs text-ink-faint dark:text-ink-faint-dark">{{ $listening['source'] ?? 'Listening' }}</p>
        <div class="mt-2">
            <x-audio-player :url="$listening['audio_url'] ?? null" on-ended="$dispatch('audio-ended')" />
        </div>
    </div>

    @if (count($listening['transcript'] ?? []))
        <div class="space-y-1.5 rounded-2xl border border-line bg-surface-sunken p-4 dark:border-line-dark dark:bg-surface-sunken-dark">
            @foreach ($listening['transcript'] as $line)
                <p class="text-sm text-ink-soft dark:text-ink-soft-dark">
                    <span class="font-semibold text-ink dark:text-ink-dark">{{ $line['speaker'] ?? '' }}:</span>
                    {{ $line['text'] ?? '' }}
                </p>
            @endforeach
        </div>
    @endif

    @unless ($readOnly)
        <div>
            <button
                wire:click="save"
                :disabled="!hasListened"
                class="cursor-pointer rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 disabled:pointer-events-none disabled:opacity-50 dark:bg-accent-dark"
            >
                Continue
            </button>
            <p x-show="!hasListened" x-cloak class="mt-1.5 text-xs text-ink-faint dark:text-ink-faint-dark">Listen at least once to continue.</p>
        </div>
    @endunless
</div>
