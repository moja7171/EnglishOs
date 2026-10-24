{{--
    The Day 1–4 switcher inside <x-mission-banner>: reached days link to
    the same page for that day, the one on screen is filled, today is
    marked, a done day gets a ✓, and days not reached yet are dashed.

    @param string $missionCode
    @param string $routeName Takes [missionCode, day], e.g. "listening.show".
    @param list<array{number: int, state: string, viewing: bool, done: bool, title?: string}> $days
--}}
@props(['missionCode', 'routeName', 'days'])

<nav class="mt-3 flex flex-wrap items-center gap-1.5" aria-label="Days of {{ $missionCode }}">
    @foreach ($days as $dayInfo)
        @php
            $pill = 'inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold';
        @endphp
        @if ($dayInfo['state'] === 'future')
            <span
                class="{{ $pill }} cursor-not-allowed border-dashed border-white/40 text-white/50"
                title="Not reached yet"
                aria-label="Day {{ $dayInfo['number'] }}, not reached yet"
            >Day {{ $dayInfo['number'] }}</span>
        @else
            <a
                href="{{ route($routeName, [$missionCode, $dayInfo['number']]) }}"
                wire:navigate
                @isset($dayInfo['title']) title="{{ $dayInfo['title'] }}" @endisset
                @if ($dayInfo['viewing']) aria-current="page" @endif
                @class([
                    $pill,
                    'border-white bg-white text-ink' => $dayInfo['viewing'],
                    'border-accent bg-accent/30 text-white' => ! $dayInfo['viewing'] && $dayInfo['state'] === 'today',
                    'border-white/50 text-white hover:bg-white/15' => ! $dayInfo['viewing'] && $dayInfo['state'] !== 'today',
                ])
            >
                Day {{ $dayInfo['number'] }}{{ $dayInfo['state'] === 'today' ? ' · today' : '' }}
                @if ($dayInfo['done'])
                    @svg('heroicon-s-check-circle', 'ms-1 h-3.5 w-3.5 text-success dark:text-success-dark')
                    <span class="sr-only">, done</span>
                @endif
            </a>
        @endif
    @endforeach
</nav>
