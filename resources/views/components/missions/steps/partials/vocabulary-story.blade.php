{{-- Read-only highlighted story text for one day's Vocabulary Builder —
     no click, no selection (that whole mechanic was removed; today's
     words are already fixed by the mission's own seeded content). Each
     highlighted word's meaning is shown inline right next to it, never
     behind a hover/title tooltip, which never worked on touch devices. --}}
<p class="text-sm leading-loose text-ink dark:text-ink-dark">
    @foreach ($segments as $segment)
        @if ($segment['type'] === 'text')
            {{ $segment['value'] }}
        @else
            <span class="inline whitespace-nowrap">
                <mark class="rounded bg-accent/15 px-1 font-semibold text-accent-ink dark:bg-accent-dark/25 dark:text-accent-ink-dark">{{ $segment['value'] }}</mark>
                <span class="text-xs text-ink-faint dark:text-ink-faint-dark">({{ $segment['meaning'] }})</span>
            </span>
        @endif
    @endforeach
</p>
