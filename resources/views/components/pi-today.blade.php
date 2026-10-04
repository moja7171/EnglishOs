@props(['practice'])

{{-- The daily voice practice with Pi, shown inside the Today box as one
     quiet row that opens /pi. Same family as <x-listening-today>, but it sits
     BELOW the day's steps: it is meant as the last 10 minutes of the day.
     Like the listening row it is NOT one of the steps (no check circles, no
     numbers, never gates Continue, always open). The prompt, the links and
     the "I practiced" tick all live on that page — the box only points there
     and reflects whether today's tick has been made. --}}
<a
    href="{{ route('pi.practice') }}"
    wire:navigate
    class="mt-3 flex items-center gap-3 rounded-xl bg-surface-sunken px-3 py-2.5 transition-colors hover:opacity-90 dark:bg-surface-sunken-dark"
    aria-label="Daily voice practice"
>
    <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">
        @svg('heroicon-o-microphone', 'h-4 w-4')
    </span>
    <span class="min-w-0 flex-1">
        <span class="block text-sm font-semibold text-ink dark:text-ink-dark">Voice practice · {{ $practice['title'] }}</span>
        @unless ($practice['practicedToday'])
            <span class="block text-xs text-ink-faint dark:text-ink-faint-dark">Last 10 minutes of your day · not a step</span>
        @endunless
    </span>
    @if ($practice['practicedToday'])
        <span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-success-soft px-2 py-0.5 text-[11px] font-semibold text-success dark:bg-success-soft-dark dark:text-success-dark">
            @svg('heroicon-s-check', 'h-3 w-3') Practiced today
        </span>
    @endif
    @svg('heroicon-o-chevron-right', 'h-4 w-4 shrink-0 text-ink-faint dark:text-ink-faint-dark')
</a>
