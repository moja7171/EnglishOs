{{--
    The one "you can look, not do" marker: a slim pill with an eye, one short
    line, and — when it can take you somewhere — an arrow. Used on a mission
    that is not unlocked yet, on a step ahead of the learner and on the
    Listening / Daily talk pages of a day not reached.

    @param string $label e.g. "Preview only · unlocks after M02".
    @param string|null $href Where tapping it goes (the mission to finish, or
        the learner's own place); null = nothing to tap.
--}}
@props(['label' => 'Preview only', 'href' => null])

@php
    $pill = 'flex w-full items-center gap-2 rounded-full border border-line bg-surface px-4 py-2 text-sm font-bold text-ink-soft dark:border-line-dark dark:bg-surface-dark dark:text-ink-soft-dark';
@endphp

@if ($href)
    <a href="{{ $href }}" wire:navigate role="status" class="{{ $pill }} transition-colors hover:border-accent">
        @svg('heroicon-o-eye', 'h-4 w-4 shrink-0')
        <span class="flex-1">{{ $label }}</span>
        @svg('heroicon-o-chevron-right', 'h-4 w-4 shrink-0 text-accent-ink dark:text-accent-ink-dark')
    </a>
@else
    <p role="status" class="{{ $pill }}">
        @svg('heroicon-o-eye', 'h-4 w-4 shrink-0')
        <span class="flex-1">{{ $label }}</span>
    </p>
@endif
