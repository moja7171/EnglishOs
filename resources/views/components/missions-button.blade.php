@props(['for' => null])

{{-- The way to every mission and every day, next to the title of Home,
     Listening and Daily talk: opens the Missions page (/missions), whose
     days open back on the page it came from.

     @param string|null $for listening or talk; null for home --}}
<a
    href="{{ route('missions.index', array_filter(['for' => $for])) }}"
    wire:navigate
    {{ $attributes->merge(['class' => 'inline-flex shrink-0 cursor-pointer items-center gap-1.5 rounded-full border border-line bg-surface px-3 py-1.5 text-xs font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:text-ink dark:border-line-dark dark:bg-surface-dark dark:text-ink-soft-dark dark:hover:text-ink-dark']) }}
>
    @svg('heroicon-o-squares-2x2', 'h-4 w-4') Missions
</a>
