{{--
    The recall card for one VocabularyWord, shared by My Words and Daily
    Review. Whichever Livewire component renders it must define
    gradeWord(int $quality, bool $remembered).

    Front: the word (with a speaker), its part of speech and a five-dot
    strength meter, then "I remember" / "Not sure". The card flips in the
    browser (see Alpine.data('recallCard') in resources/js/app.js — flip,
    swipe, keyboard and the leave animation never touch the server). Back:
    meaning, example and the learner's own sentence (target word in bold),
    then the grade buttons, each printing the gap its grade would schedule
    ("Good · 6d"). Someone who wasn't sure gets one "Again" button instead.
    Every field a word doesn't have is simply left out.

    @param \App\Models\VocabularyWord $word
    @param int|null $position  Place in the session (Daily Review); null hides the counter and bar.
    @param int|null $total     Session length.
    @param int $remaining      Cards waiting behind this one.
    @param string|null $caption Right-hand header text used instead of the counter.
--}}
@props(['word', 'position' => null, 'total' => null, 'remaining' => 0, 'caption' => null])

@php
    $strength = $word->strengthLevel();
    $strengthLabel = $word->strengthLabel();
    $keyHint = 'hidden rounded border border-current/30 px-1 text-[10px] leading-4 font-semibold opacity-60 md:inline-block';
    $facePad = 'col-start-1 row-start-1 transition duration-150 motion-reduce:transition-none';
@endphp

<x-review-shell
    label="Word"
    icon="heroicon-o-book-open"
    :position="$position"
    :total="$total"
    :remaining="$remaining"
    :caption="$caption"
    class="animate-review-card-in overflow-hidden"
    x-data="recallCard"
    x-bind:class="{ 'select-none': dragging }"
    x-on:keydown.window="key($event)"
    x-on:pointerdown="pointerDown($event)"
    x-on:pointermove="pointerMove($event)"
    x-on:pointerup="pointerUp()"
    x-on:pointercancel="pointerCancel()"
    x-bind:style="cardStyle"
>
    {{-- Swipe feedback: a faint green/red wash and a label that appears once the card is dragged far enough. --}}
    <div aria-hidden="true" class="pointer-events-none absolute inset-0" x-bind:style="tintStyle"></div>
    <div
        aria-hidden="true"
        x-cloak
        x-show="hint"
        x-text="hintLabel"
        class="pointer-events-none absolute top-3 left-1/2 z-10 -translate-x-1/2 rounded-full border-2 bg-surface px-3 py-1 text-xs font-extrabold tracking-wide uppercase dark:bg-surface-dark"
        x-bind:class="hint === 'right' ? 'border-success text-success dark:border-success-dark dark:text-success-dark' : 'border-danger text-danger dark:border-danger dark:text-danger'"
    ></div>

    <div class="flex items-center gap-2" title="{{ $strengthLabel }}">
        <span class="flex gap-1" aria-hidden="true">
            @for ($dot = 1; $dot <= 5; $dot++)
                <span class="h-1.5 w-4 rounded-full {{ $dot <= $strength ? 'bg-accent dark:bg-accent-dark' : 'bg-surface-sunken dark:bg-surface-sunken-dark' }}"></span>
            @endfor
        </span>
        <span class="text-[11px] font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">{{ $strengthLabel }}</span>
    </div>

    {{-- Both faces share one grid cell, so the card keeps a constant height as it flips. --}}
    <div class="grid [perspective:1000px]">
        <div
            class="{{ $facePad }} flex flex-col items-center justify-center gap-6 py-4 text-center ease-in"
            x-bind:style="flipped ? 'transform: rotateY(90deg); opacity: 0; pointer-events: none;' : ''"
            x-bind:inert="flipped"
        >
            <div class="space-y-2">
                <x-speak-word :word="$word->word" class="font-display text-3xl font-extrabold text-ink dark:text-ink-dark" />
                @if (filled($word->pos))
                    <p>
                        <span class="rounded-full bg-accent/15 px-2 py-0.5 text-[11px] font-semibold text-accent-ink dark:bg-accent-dark/25 dark:text-accent-ink-dark">{{ $word->pos }}</span>
                    </p>
                @endif
            </div>

            <p class="text-sm text-ink-soft dark:text-ink-soft-dark">Do you remember it?</p>

            <div class="grid w-full grid-cols-2 gap-2">
                <button
                    type="button"
                    x-on:click="flip(false)"
                    class="inline-flex cursor-pointer items-center justify-center gap-2 rounded-xl border border-line px-4 py-3 text-sm font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                >Not sure <kbd class="{{ $keyHint }}">←</kbd></button>
                <button
                    type="button"
                    x-on:click="flip(true)"
                    class="inline-flex cursor-pointer items-center justify-center gap-2 rounded-xl bg-accent px-4 py-3 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
                >@svg('heroicon-o-check', 'h-4 w-4') I remember <kbd class="{{ $keyHint }}">Space</kbd></button>
            </div>
        </div>

        <div
            class="{{ $facePad }} space-y-4 ease-out"
            style="opacity: 0; transform: rotateY(-90deg); pointer-events: none;"
            inert
            x-bind:style="flipped ? 'transform: rotateY(0); opacity: 1; transition-delay: .12s;' : 'transform: rotateY(-90deg); opacity: 0; pointer-events: none;'"
            x-bind:inert="!flipped"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <x-speak-word :word="$word->word" class="font-display text-xl font-extrabold text-ink dark:text-ink-dark" />
                @if (filled($word->pos))
                    <span class="rounded-full bg-accent/15 px-2 py-0.5 text-[11px] font-semibold text-accent-ink dark:bg-accent-dark/25 dark:text-accent-ink-dark">{{ $word->pos }}</span>
                @endif
            </div>

            @if (filled($word->meaning))
                <p class="text-lg leading-snug font-semibold text-ink dark:text-ink-dark">{{ $word->meaning }}</p>
            @endif

            @if (filled($word->example))
                <div>
                    <p class="text-[11px] font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Example</p>
                    <x-speak-word :word="$word->example" block top class="mt-0.5 text-sm text-ink-soft dark:text-ink-soft-dark">{{ $word->highlightIn($word->example) }}</x-speak-word>
                </div>
            @endif

            @if (filled($word->user_sentence))
                <div class="rounded-2xl rounded-tl-sm bg-accent-soft px-3.5 py-2.5 dark:bg-accent-soft-dark">
                    <p class="text-[11px] font-semibold tracking-wide text-accent-ink uppercase dark:text-accent-ink-dark">You wrote</p>
                    <x-speak-word :word="$word->user_sentence" block top class="mt-0.5 text-sm text-ink dark:text-ink-dark">{{ $word->highlightIn($word->user_sentence) }}</x-speak-word>
                </div>
            @endif

            <div x-show="recalled" class="grid grid-cols-3 gap-2">
                <button
                    type="button"
                    x-on:click="grade(1)"
                    x-bind:disabled="busy"
                    class="flex cursor-pointer flex-col items-center gap-0.5 rounded-xl border border-danger-line bg-danger-soft px-2 py-2.5 text-danger-ink transition-colors hover:opacity-90 disabled:opacity-50"
                >
                    <span class="text-sm font-bold">Again</span>
                    <span class="text-[11px] font-semibold opacity-70">{{ $word->nextIntervalLabel(1) }}</span>
                    <kbd class="{{ $keyHint }}">1</kbd>
                </button>
                <button
                    type="button"
                    x-on:click="grade(4)"
                    x-bind:disabled="busy"
                    class="flex cursor-pointer flex-col items-center gap-0.5 rounded-xl border border-line px-2 py-2.5 text-ink transition-colors hover:border-ink-faint hover:bg-surface-sunken disabled:opacity-50 dark:border-line-dark dark:text-ink-dark dark:hover:bg-surface-sunken-dark"
                >
                    <span class="text-sm font-bold">Good</span>
                    <span class="text-[11px] font-semibold opacity-70">{{ $word->nextIntervalLabel(4) }}</span>
                    <kbd class="{{ $keyHint }}">2</kbd>
                </button>
                <button
                    type="button"
                    x-on:click="grade(5)"
                    x-bind:disabled="busy"
                    class="flex cursor-pointer flex-col items-center gap-0.5 rounded-xl border border-success/40 bg-success-soft px-2 py-2.5 text-success transition-colors hover:opacity-90 disabled:opacity-50 dark:border-success-dark/40 dark:bg-success-soft-dark dark:text-success-dark"
                >
                    <span class="text-sm font-bold">Easy</span>
                    <span class="text-[11px] font-semibold opacity-70">{{ $word->nextIntervalLabel(5) }}</span>
                    <kbd class="{{ $keyHint }}">3</kbd>
                </button>
            </div>

            <div x-show="! recalled" x-cloak>
                <button
                    type="button"
                    x-on:click="grade(1)"
                    x-bind:disabled="busy"
                    class="flex w-full cursor-pointer items-center justify-center gap-2 rounded-xl bg-accent px-4 py-3 text-sm font-semibold text-white transition-colors hover:opacity-90 disabled:opacity-50 dark:bg-accent-dark"
                >Next — see it again in {{ $word->nextIntervalLabel(1) }} @svg('heroicon-o-arrow-right', 'h-3.5 w-3.5') <kbd class="{{ $keyHint }}">Space</kbd></button>
            </div>
        </div>
    </div>
</x-review-shell>
