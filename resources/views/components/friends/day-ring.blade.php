@props(['slices' => 0, 'tone' => 'success'])

{{-- The day's streak ring around a friend's photo (see StreakRing): three
     arcs, one per thing finished today, filled as they happen — empty
     (grey) when nothing yet, all three when the ring is full. The photo
     goes in the slot. --}}
@php
    $radius = 25;
    $circumference = 2 * M_PI * $radius;
    $dash = $circumference / 3 - 8;
    $filled = $tone === 'accent' ? 'stroke-accent dark:stroke-accent-dark' : 'stroke-success dark:stroke-success-dark';
@endphp
<span class="relative grid h-[54px] w-[54px] shrink-0 place-items-center">
    <svg viewBox="0 0 56 56" class="absolute inset-0 h-full w-full" aria-hidden="true">
        @for ($arc = 0; $arc < 3; $arc++)
            <circle
                cx="28" cy="28" r="{{ $radius }}" fill="none" stroke-width="3.2" stroke-linecap="round"
                stroke-dasharray="{{ round($dash, 1) }} {{ round($circumference - $dash, 1) }}"
                transform="rotate({{ round(-90 + $arc * 120 + 4 / $circumference * 360, 1) }} 28 28)"
                @class([$filled => $arc < $slices, 'stroke-line dark:stroke-line-dark' => $arc >= $slices])
            />
        @endfor
    </svg>
    {{ $slot }}
</span>
