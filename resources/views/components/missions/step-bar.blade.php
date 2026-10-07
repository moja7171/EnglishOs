{{--
    The slim "you are here" bar pinned to the top of a mission step once the
    day's step list has scrolled out of sight — the day, the step and how far
    through the day the learner is, so a long step never loses its place. Tap
    it to jump back to the top.

    It is opaque and flush with the top edge on purpose: a see-through strip
    that slid about behind the content is what got the old sticky Continue bar
    removed (see <x-sticky-bar>). Only the thin progress line moves.

    It must sit inside an element with x-data="missionStepBar" that also holds
    the step list marked x-ref="anchor" — see missions/⚡runner.blade.php.

    @param int $dayNumber 1-based day the step belongs to.
    @param string $dayLabel That day's name, e.g. "Build".
    @param string $stepLabel The active step's name.
    @param string $icon Heroicons name for the step, from stepIcon().
    @param list<'done'|'active'|'ahead'> $states One entry per step of the day.
--}}
@props(['dayNumber', 'dayLabel', 'stepLabel', 'icon', 'states'])

@php
    $position = array_search('active', $states, true) + 1;
@endphp

<div
    x-show="visible"
    x-cloak
    x-transition:enter="transition duration-200 ease-out motion-reduce:duration-0"
    x-transition:enter-start="-translate-y-full opacity-0"
    x-transition:enter-end="translate-y-0 opacity-100"
    x-transition:leave="transition duration-150 ease-in motion-reduce:duration-0"
    x-transition:leave-start="translate-y-0 opacity-100"
    x-transition:leave-end="-translate-y-full opacity-0"
    class="fixed inset-x-0 top-0 z-30 border-b border-line bg-ground shadow-sm dark:border-line-dark dark:bg-ground-dark"
>
    <button
        type="button"
        x-on:click="toTop()"
        aria-label="Back to the top of {{ $stepLabel }}"
        class="mx-auto flex w-full max-w-2xl cursor-pointer items-center gap-3 px-6 pt-2 pb-1.5 text-left"
    >
        @svg($icon, 'h-4 w-4 shrink-0 text-accent-ink dark:text-accent-ink-dark')
        <span class="min-w-0 flex-1">
            <span class="block truncate text-[10px] leading-tight font-bold tracking-widest text-ink-faint uppercase dark:text-ink-faint-dark">Day {{ $dayNumber }} · {{ $dayLabel }}</span>
            <span class="block truncate text-sm leading-tight font-semibold text-ink dark:text-ink-dark">{{ $stepLabel }}</span>
        </span>
        <span class="shrink-0 text-xs font-semibold text-ink-faint tabular-nums dark:text-ink-faint-dark">{{ $position }}/{{ count($states) }}</span>
    </button>

    <div class="mx-auto flex max-w-2xl gap-1 px-6 pb-2" aria-hidden="true">
        @foreach ($states as $state)
            <span class="h-[3px] flex-1 overflow-hidden rounded-full {{ $state === 'done' ? 'bg-success dark:bg-success-dark' : 'bg-line dark:bg-line-dark' }}">
                @if ($state === 'active')
                    <span class="block h-full origin-left rounded-full bg-accent dark:bg-accent-dark" x-bind:style="`transform: scaleX(${progress})`"></span>
                @endif
            </span>
        @endforeach
    </div>
</div>
