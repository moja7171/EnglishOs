{{--
    The top row of anything that runs like a lesson — a mission step, the
    Daily Review, the placement test, a checkpoint: ✕ on the left, one
    progress bar, then a small "3/8" counter and/or the page's own icon
    button (the slot) on the right. A ‹ appears after ✕ while a lesson step
    has an earlier screen to go back to. Only the row; the page decides whether
    it is pinned (the mission step bar pins it with its day line).

    @param string|null $close Where ✕ goes.
    @param string|null $closeClick Raw Alpine expression run by ✕ instead
        of a link (e.g. "$wire.stopPractice()").
    @param float $progress 0–1, how far along.
    @param string|null $progressExpr Raw Alpine expression for a live 0–1
        share that takes over from $progress once it isn't null.
    @param string|null $counter e.g. "3/8".
--}}
@props(['close' => null, 'closeClick' => null, 'progress' => 0, 'progressExpr' => null, 'counter' => null])

@php
    $closeClass = 'inline-flex h-9 w-9 shrink-0 cursor-pointer items-center justify-center rounded-full text-ink-soft transition-colors hover:bg-surface-sunken hover:text-ink dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark dark:hover:text-ink-dark';
@endphp

<div {{ $attributes->class('flex items-center gap-3') }}>
    @if ($closeClick)
        <button type="button" x-on:click="{{ $closeClick }}" aria-label="Close" class="{{ $closeClass }}">@svg('heroicon-o-x-mark', 'h-6 w-6')</button>
    @else
        <a href="{{ $close }}" wire:navigate aria-label="Close" class="{{ $closeClass }}">@svg('heroicon-o-x-mark', 'h-6 w-6')</a>
    @endif

    {{-- ‹ shows only while a lesson step has an earlier screen (x-back-close.step in app.js) and goes there like the phone's Back. --}}
    <button type="button" x-data x-show="$store.stepBack.open" x-cloak x-on:click="history.back()" aria-label="Previous screen" class="{{ $closeClass }} -ml-2">@svg('heroicon-o-chevron-left', 'h-6 w-6')</button>

    <div class="h-2.5 min-w-0 flex-1 overflow-hidden rounded-full bg-line dark:bg-line-dark" aria-hidden="true">
        <div
            class="h-full rounded-full bg-success transition-[width] duration-300 dark:bg-success-dark"
            style="width: {{ round(max(0, min(1, $progress)) * 100, 1) }}%"
            @if ($progressExpr) x-effect="if (({{ $progressExpr }}) !== null) $el.style.width = Math.round(({{ $progressExpr }}) * 1000) / 10 + '%'" @endif
        ></div>
    </div>

    @if (filled($counter))
        <span class="shrink-0 text-sm font-extrabold text-ink-soft tabular-nums dark:text-ink-soft-dark">{{ $counter }}</span>
    @endif

    {{ $slot }}
</div>
