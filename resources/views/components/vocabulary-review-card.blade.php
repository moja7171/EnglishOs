{{--
    The recall card for one VocabularyWord, shared by My Words and Daily
    Review (both must define revealWord(bool $remembered) and
    gradeSelf(int $quality) — wire:click resolves to whichever Livewire
    component renders this).

    Flow: the word is already showing above this card. The learner says
    "I remember" or "Not sure — show me"; either way the card then opens
    with the meaning, part of speech, an example sentence and — when the
    word came from a mission step — their own sentence. Someone who said
    they remembered can now check themselves and grade honestly (Forgot /
    Remembered / Knew it instantly); someone who wasn't sure just moves on
    and the word comes back tomorrow. Every field a word doesn't have is
    simply left out.

    @param \App\Models\VocabularyWord $word
    @param bool $revealed  True once the card has been opened.
    @param bool $recalled  True when the learner said "I remember" before opening it.
--}}
@props(['word', 'revealed' => false, 'recalled' => false])

@if (! $revealed)
    <div class="flex flex-wrap gap-2">
        <button
            type="button"
            wire:click="revealWord(true)"
            class="inline-flex cursor-pointer items-center gap-1.5 rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
        >@svg('heroicon-o-check', 'h-4 w-4') I remember</button>
        <button
            type="button"
            wire:click="revealWord(false)"
            class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-line px-4 py-2 text-sm font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
        >@svg('heroicon-o-eye', 'h-4 w-4') Not sure — show me</button>
    </div>
@else
    <div class="space-y-3 card-sunken p-4">
        <div class="flex flex-wrap items-baseline gap-2">
            @if (filled($word->pos))
                <span class="rounded-full bg-accent/15 px-2 py-0.5 text-[11px] font-semibold text-accent-ink dark:bg-accent-dark/25 dark:text-accent-ink-dark">{{ $word->pos }}</span>
            @endif
            @if (filled($word->meaning))
                <p class="text-sm text-ink dark:text-ink-dark">{{ $word->meaning }}</p>
            @endif
        </div>

        @if (filled($word->example))
            <div>
                <p class="text-[11px] font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Example</p>
                <x-speak-word :word="$word->example" block class="text-sm text-ink-soft italic dark:text-ink-soft-dark" />
            </div>
        @endif

        @if (filled($word->user_sentence))
            <div>
                <p class="text-[11px] font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Your sentence</p>
                <x-speak-word :word="$word->user_sentence" block class="text-sm text-ink-soft dark:text-ink-soft-dark" />
            </div>
        @endif
    </div>

    @if ($recalled)
        <div>
            <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Now that you can see it — did you really remember it?</p>
            <div class="mt-2 flex flex-wrap gap-2">
                <button
                    type="button"
                    wire:click="gradeSelf(1)"
                    class="cursor-pointer rounded-full border border-line px-4 py-2 text-sm font-semibold text-ink-soft transition-colors hover:border-danger-line hover:bg-danger-soft hover:text-danger-ink dark:border-line-dark dark:text-ink-soft-dark"
                >Forgot it</button>
                <button
                    type="button"
                    wire:click="gradeSelf(4)"
                    class="cursor-pointer rounded-full border border-line px-4 py-2 text-sm font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                >Remembered it</button>
                <button
                    type="button"
                    wire:click="gradeSelf(5)"
                    class="cursor-pointer rounded-full border border-success/40 px-4 py-2 text-sm font-semibold text-success transition-colors hover:bg-success-soft dark:border-success-dark/40 dark:text-success-dark dark:hover:bg-success-soft-dark"
                >Knew it instantly</button>
            </div>
        </div>
    @else
        <button
            type="button"
            wire:click="gradeSelf(1)"
            class="inline-flex cursor-pointer items-center gap-1 rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
        >Next — I'll see it again soon @svg('heroicon-o-arrow-right', 'h-3.5 w-3.5')</button>
    @endif
@endif
