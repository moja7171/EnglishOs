{{-- A word card's meaning: the word and its type, English, Persian, the example with the word in bold. --}}
<span class="flex flex-col items-center gap-2 text-center">
    <x-speak-word :word="$word->word" class="font-display text-xl font-semibold text-ink dark:text-ink-dark" />
    @if (filled($word->pos))
        <span class="rounded-full bg-accent-soft px-3 py-0.5 text-xs font-extrabold text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">{{ $word->pos }}</span>
    @endif
    @if (filled($word->meaning))
        <span class="text-base font-semibold text-ink dark:text-ink-dark">{{ $word->meaning }}</span>
    @endif
    @if (filled($word->meaning_fa))
        <span dir="rtl" class="font-fa text-base font-semibold text-accent-ink dark:text-accent-ink-dark">{{ $word->meaning_fa }}</span>
    @endif
    @if (filled($word->example))
        <span class="text-sm text-ink-soft italic dark:text-ink-soft-dark">{{ $word->highlightIn($word->example) }}</span>
    @endif
</span>
