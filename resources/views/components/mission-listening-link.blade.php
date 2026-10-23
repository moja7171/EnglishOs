@props(['mission', 'day' => 1])

{{-- Entry point to a mission's own listening picks (/listening/M03/1) on
     its day overview, opening the learner's day today. The page itself
     decides how far the learner has got — later days stay locked there. A
     finished mission's "done" card has <x-practice-again> instead. --}}
<a
    href="{{ route('listening.show', [$mission->code, $day]) }}"
    wire:navigate
    class="flex items-center gap-3 rounded-xl px-3.5 py-2.5 transition-colors hover:bg-surface-sunken dark:hover:bg-surface-sunken-dark"
>
    <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">
        @svg('heroicon-o-speaker-wave', 'h-4 w-4')
    </span>
    <span class="flex-1">
        <span class="block text-sm font-semibold text-ink dark:text-ink-dark">Listening</span>
        <span class="block text-xs text-ink-faint dark:text-ink-faint-dark">Anytime · one is enough.</span>
    </span>
    @svg('heroicon-o-chevron-right', 'h-4 w-4 shrink-0 text-ink-faint dark:text-ink-faint-dark')
</a>
