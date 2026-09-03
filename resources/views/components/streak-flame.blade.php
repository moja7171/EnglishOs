{{--
    A streak icon that visibly grows more intense with the streak itself
    — a faint outline ember below 7 days, up through a bold gold flame at
    100+ — so the same "day count" carries more emotional weight than a
    flat, unchanging icon next to a number. Replaces a bare
    heroicon-s-fire wherever an actual daily-streak count is shown (My
    Progress, Missions Overview, Mission Result, Friends Board) — NOT
    used for unrelated "fire" icons elsewhere (Quick Round's in-session
    correct-streak, chat reactions).

    @param int $streak
    @param string $size Tailwind size classes for the icon, e.g. "h-4 w-4".
--}}
@props(['streak' => 0, 'size' => 'h-3.5 w-3.5'])

@php
    $tier = match (true) {
        $streak >= 100 => ['icon' => 'heroicon-s-fire', 'color' => 'text-amber-500'],
        $streak >= 30 => ['icon' => 'heroicon-s-fire', 'color' => 'text-orange-500'],
        $streak >= 7 => ['icon' => 'heroicon-s-fire', 'color' => 'text-accent-ink dark:text-accent-ink-dark'],
        $streak > 0 => ['icon' => 'heroicon-o-fire', 'color' => 'text-ink-faint dark:text-ink-faint-dark'],
        default => null,
    };
@endphp

@if ($tier)
    <span {{ $attributes->class(["inline-flex items-center {$tier['color']}"]) }}>
        @svg($tier['icon'], $size)
    </span>
@endif
