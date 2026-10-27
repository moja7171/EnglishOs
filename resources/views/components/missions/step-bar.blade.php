{{--
    The header of a mission step, pinned to the top while the step scrolls,
    in the shape of a lesson in a learning app: <x-lesson-header> (✕ back
    to the day overview, a progress bar, and the ask-instructor icon when
    given). Under them one
    small line, "Day N · Step name · 4/5 ▾", opens the day's step list as a
    sheet (<x-menu-panel>), headed by the mission's code and title.

    The bar shows the open step's own progress once the step reports it —
    a 'step-progress' window event carrying { share: 0–1 } — and how much
    of the day is done until then.

    It is opaque on purpose, and has no backdrop blur or transform: either
    would make the sheet's fixed positioning relative to this bar.

    @param string $backUrl Where ✕ goes (the day overview).
    @param string $missionCode The mission's code, e.g. "M01".
    @param string $missionTitle The mission's title, e.g. "My Daily Life".
    @param int $dayNumber 1-based day the step belongs to.
    @param string $dayHeading That day's heading for the sheet, e.g. "Day 2 · Build".
    @param string $stepLabel The active step's name.
    @param list<array{label: string, icon: string, url: string, state: 'done'|'active'|'ahead', finished: bool, here: bool, minutes: ?int}> $steps One entry per step of the day; finished also counts the open step once it's done.
    @param bool $askInstructor Shows the icon that opens <livewire:missions.ask-instructor>.
--}}
@props(['backUrl', 'missionCode', 'missionTitle', 'dayNumber', 'dayHeading', 'stepLabel', 'steps', 'askInstructor' => false])

@php
    $stepPosition = collect($steps)->search(fn (array $step): bool => $step['state'] === 'active') + 1;
    $doneShare = count($steps) > 0 ? collect($steps)->where('finished', true)->count() / count($steps) * 100 : 0;
@endphp

<div
    x-data="{ stepShare: null }"
    x-on:step-progress.window="stepShare = $event.detail.share"
    class="sticky top-0 z-30 -mx-4 bg-ground px-3 pt-2 pb-1 sm:-mx-6 sm:px-5 dark:bg-ground-dark"
    data-step-header
>
    <x-lesson-header :close="$backUrl" :progress="$doneShare / 100" progress-expr="stepShare">
        @if ($askInstructor)
            <button
                type="button"
                x-data
                x-on:click="$dispatch('ask-instructor-toggle')"
                data-ask-instructor-trigger
                aria-label="Ask your AI instructor"
                class="inline-flex h-9 w-9 shrink-0 cursor-pointer items-center justify-center rounded-full bg-accent-soft text-accent-ink transition-opacity hover:opacity-80 dark:bg-accent-soft-dark dark:text-accent-ink-dark"
            >@svg('heroicon-o-sparkles', 'h-5 w-5')</button>
        @endif
    </x-lesson-header>

    <div class="relative mt-1 flex justify-center" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false" x-back-close="{ open: open, close: () => open = false }">
        <button
            type="button"
            x-on:click="open = ! open"
            aria-haspopup="menu"
            class="inline-flex min-w-0 cursor-pointer items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-bold text-ink-faint transition-colors hover:bg-surface-sunken hover:text-ink-soft dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark dark:hover:text-ink-soft-dark"
        >
            <span class="truncate">Day {{ $dayNumber }} · {{ $stepLabel }}</span>
            <span class="shrink-0 tabular-nums" aria-label="Step {{ $stepPosition }} of {{ count($steps) }}">· {{ $stepPosition }}/{{ count($steps) }}</span>
            <span class="shrink-0 transition-transform" x-bind:class="open && 'rotate-180'">@svg('heroicon-o-chevron-down', 'h-3.5 w-3.5')</span>
        </button>

        <x-menu-panel role="menu" aria-label="Steps of this day" width="sm:w-80" class="sm:py-1">
            <p class="px-4 pt-2 text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">{{ $missionCode }} · {{ $missionTitle }}</p>
        <p class="px-4 pt-0.5 pb-1 text-xs font-bold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">{{ $dayHeading }}</p>
            <nav class="space-y-1 px-2 pb-2">
                @foreach ($steps as $step)
                    <a
                        href="{{ $step['url'] }}"
                        wire:navigate
                        wire:key="step-sheet-{{ $loop->index }}"
                        @if ($step['state'] === 'active') aria-current="step" @endif
                        @class([
                            'flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition-colors',
                            'bg-accent font-semibold text-white dark:bg-accent-dark' => $step['state'] === 'active',
                            'font-semibold text-success hover:bg-surface-sunken dark:text-success-dark dark:hover:bg-surface-sunken-dark' => $step['state'] === 'done',
                            'text-ink-faint hover:bg-surface-sunken dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark' => $step['state'] === 'ahead',
                        ])
                    >
                        @svg($step['icon'], 'h-4 w-4 shrink-0')
                        <span class="flex-1">{{ $step['label'] }}</span>
                        @if ($step['here'])
                            <span class="shrink-0 rounded-full bg-accent px-2 py-0.5 text-xs font-bold text-white dark:bg-accent-dark">You are here</span>
                        @endif
                        @if ($step['minutes'])
                            <span class="shrink-0 text-xs opacity-70">~{{ \App\Models\Mission::formatDuration($step['minutes']) }}</span>
                        @endif
                        @if ($step['state'] === 'done')
                            @svg('heroicon-s-check-circle', 'h-4 w-4 shrink-0')
                        @elseif ($step['state'] === 'ahead')
                            @svg('heroicon-o-eye', 'h-3.5 w-3.5 shrink-0')
                        @endif
                    </a>
                @endforeach
            </nav>
            <a href="{{ $backUrl }}" wire:navigate class="flex items-center gap-2 border-t border-line px-4 py-3 text-sm font-semibold text-ink-soft transition-colors hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">
                @svg('heroicon-o-map', 'h-4 w-4') All days
            </a>
        </x-menu-panel>
    </div>
</div>
