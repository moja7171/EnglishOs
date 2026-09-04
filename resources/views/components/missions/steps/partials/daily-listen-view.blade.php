{{--
    Shared markup for every Daily Listening step (⚡daily-listen-2/3/4.blade.php)
    — identical UI, only the phase key differs per file. Reuses Day 1's
    real Listening content: the same audio, the same transcript.

    @param \App\Models\MissionRun $run
    @param bool $readOnly
    @param bool $listened
    @param string $recall
--}}
@php $listening = $run->mission->stepContent('listening'); @endphp

<div
    class="space-y-6"
    x-data="{
        hasListened: {{ $listened ? 'true' : 'false' }},
        showTranscript: false,
        recall: @js($recall),
        targetPhrases: @js($this->targetPhrasesForRecall()),
        get matchesTarget() {
            const text = this.recall.toLowerCase();
            return this.recall.trim() !== '' && this.targetPhrases.some((p) => text.includes(p));
        },
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

    {{-- A tiny, ungraded recall prompt — turns passive re-listening into
         one small act of active retrieval. Anything counts; the only
         reaction is a quiet nod if it happens to match a real target
         phrase, never a "wrong answer" state. --}}
    <div x-show="hasListened || {{ $readOnly ? 'true' : 'false' }}" @unless($readOnly) x-cloak @endunless>
        <label class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">{{ $this->recallPrompt() }}</label>
        <div class="mt-1 flex items-center gap-2">
            <input
                type="text"
                wire:model="recall"
                x-on:input="recall = $el.value"
                placeholder="A word or phrase…"
                @readonly($readOnly)
                class="w-full rounded-lg border border-line bg-transparent px-2 py-1 text-sm text-ink disabled:opacity-50 dark:border-line-dark dark:text-ink-dark"
            >
        </div>
        <p x-show="matchesTarget" x-cloak class="mt-1.5 inline-flex items-center gap-1 text-xs font-semibold text-success dark:text-success-dark">
            @svg('heroicon-s-sparkles', 'h-3.5 w-3.5') That's one of the key phrases!
        </p>
        @error('recall')
            <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
        @enderror
    </div>

    @unless ($readOnly)
        <p x-show="!hasListened" class="text-xs text-ink-faint dark:text-ink-faint-dark">Listen at least once to continue.</p>
        <p x-show="hasListened && !recall.trim()" x-cloak class="text-xs text-ink-faint dark:text-ink-faint-dark">Write a word or phrase above to continue.</p>

        <x-sticky-bar ready-when="hasListened && recall.trim() !== ''">
            <button
                wire:click="save"
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
