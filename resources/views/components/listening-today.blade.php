@props(['listening'])

{{-- The daily listening habit, shown at the bottom of the Today box as one
     quiet row that opens /listening. The three picks, their levels, the
     transcript links and the "I listened" tick all live on that page — the
     box only points there and reflects whether today's tick has been made.
     Deliberately NOT styled like the mission steps (no check circles, no
     numbers, no current state) and kept out of the day's order: one pick,
     any time of day, never gates Continue. Named apart from the steps'
     own "Listen Again" so the two are never taken for each other. --}}
<a
    href="{{ route('listening.show') }}"
    wire:navigate
    class="mt-3 flex items-center gap-3 rounded-xl bg-surface-sunken px-3 py-2.5 transition-colors hover:opacity-90 dark:bg-surface-sunken-dark"
    aria-label="Extra listening"
>
    <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">
        @svg('heroicon-o-speaker-wave', 'h-4 w-4')
    </span>
    <span class="min-w-0 flex-1">
        <span class="block text-sm font-semibold text-ink dark:text-ink-dark">Extra listening</span>
        @unless ($listening['listenedToday'])
            <span class="block text-xs text-ink-faint dark:text-ink-faint-dark">Anytime · one is enough</span>
        @endunless
    </span>
    @if ($listening['listenedToday'])
        <span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-success-soft px-2 py-0.5 text-[11px] font-semibold text-success dark:bg-success-soft-dark dark:text-success-dark">
            @svg('heroicon-s-check', 'h-3 w-3')
            Listened today
            @if ($listening['listensToday'] > 0)
                · {{ $listening['listensToday'] }}×
            @endif
        </span>
    @endif
    @svg('heroicon-o-chevron-right', 'h-4 w-4 shrink-0 text-ink-faint dark:text-ink-faint-dark')
</a>
