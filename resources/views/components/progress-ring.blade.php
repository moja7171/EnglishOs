{{--
    A small circular percentage indicator — reusable anywhere a plain
    progress bar feels too flat (see friends/⚡board.blade.php's per-
    friend mission-progress ring). Pure SVG, no JS, animates via CSS
    transition on stroke-dashoffset when $percent changes, and fills from
    empty the first time it appears.

    @param int $percent 0-100.
    @param int $size Outer diameter in pixels.
    @param int $strokeWidth Ring thickness in pixels.
--}}
@props(['percent' => 0, 'size' => 44, 'strokeWidth' => 4])

@php
    $clamped = max(0, min(100, (int) $percent));
    $radius = ($size - $strokeWidth) / 2;
    $circumference = 2 * M_PI * $radius;
    $offset = $circumference * (1 - $clamped / 100);
@endphp

<span
    x-data="{ filled: false }"
    x-init="requestAnimationFrame(() => requestAnimationFrame(() => filled = true))"
    {{ $attributes->class(['relative inline-flex shrink-0 items-center justify-center']) }}
    style="width: {{ $size }}px; height: {{ $size }}px;"
>
    <svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 {{ $size }} {{ $size }}" class="-rotate-90">
        <circle
            cx="{{ $size / 2 }}" cy="{{ $size / 2 }}" r="{{ $radius }}"
            fill="none" stroke-width="{{ $strokeWidth }}"
            class="stroke-surface-sunken dark:stroke-surface-sunken-dark"
        ></circle>
        <circle
            cx="{{ $size / 2 }}" cy="{{ $size / 2 }}" r="{{ $radius }}"
            fill="none" stroke-width="{{ $strokeWidth }}"
            stroke-linecap="round"
            stroke-dasharray="{{ $circumference }}"
            stroke-dashoffset="{{ $offset }}"
            x-bind:stroke-dashoffset="filled ? {{ $offset }} : {{ $circumference }}"
            x-bind:class="filled && 'transition-[stroke-dashoffset] duration-700 ease-out motion-reduce:transition-none'"
            class="stroke-accent dark:stroke-accent-dark"
        ></circle>
    </svg>
    <span class="absolute text-[11px] font-bold text-ink dark:text-ink-dark">{{ $clamped }}%</span>
</span>
