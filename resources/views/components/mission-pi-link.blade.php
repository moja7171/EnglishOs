@props(['mission'])

{{-- Entry point to a mission's own daily voice practice (/pi/M03/1). Shown
     on the mission's day overview and on its "done" card, next to the
     listening link, so a learner can come back to a finished mission's
     practice. The page itself decides how far the learner has got — later
     days stay locked there. --}}
<a
    href="{{ route('pi.practice', [$mission->code, 1]) }}"
    wire:navigate
    class="flex items-center gap-3 rounded-xl px-3.5 py-2.5 transition-colors hover:bg-surface-sunken dark:hover:bg-surface-sunken-dark"
>
    <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">
        @svg('heroicon-o-microphone', 'h-4 w-4')
    </span>
    <span class="flex-1">
        <span class="block text-sm font-semibold text-ink dark:text-ink-dark">Voice practice for this mission</span>
        <span class="block text-xs text-ink-faint dark:text-ink-faint-dark">Ten minutes a day with a voice AI of your choice, outside the app. Come back to it whenever you like.</span>
    </span>
    @svg('heroicon-o-chevron-right', 'h-4 w-4 shrink-0 text-ink-faint dark:text-ink-faint-dark')
</a>
