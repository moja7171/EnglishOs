{{--
    One mission day as a small circle ringed in three parts — Lesson,
    Listening, Daily talk — each filling green once done; all three turn
    the middle into a green tick. Today's day has an accent middle. Shown on
    the Missions cards (over the photo) and on a mission's page (on a card).
    Purely visual: the caller wraps it in the link or button and gives that
    the label.

    @param int $number
    @param bool $lesson
    @param bool $listened
    @param bool $talked
    @param bool $today
    @param bool $onPhoto White strokes for a dark photo behind it.
--}}
@props(['number', 'lesson' => false, 'listened' => false, 'talked' => false, 'today' => false, 'onPhoto' => false])

@php
    $parts = [$lesson, $listened, $talked];
    $allDone = ! in_array(false, $parts, true);
    // One third of the ring (r = 12) less a small gap on each side.
    $circumference = 2 * M_PI * 12;
    $segment = $circumference / 3 - 3;
@endphp

<span {{ $attributes->class('relative inline-flex h-8 w-8 shrink-0 items-center justify-center') }}>
    <svg viewBox="0 0 32 32" class="absolute inset-0 h-full w-full -rotate-90" aria-hidden="true">
        @foreach ($parts as $index => $isDone)
            <circle
                cx="16" cy="16" r="12" fill="none" stroke-width="3" stroke-linecap="round"
                stroke-dasharray="{{ round($segment, 2) }} {{ round($circumference - $segment, 2) }}"
                stroke-dashoffset="{{ round(-($index * $circumference / 3 + 1.5), 2) }}"
                @class([
                    'stroke-success dark:stroke-success-dark' => $isDone,
                    'stroke-white/35' => ! $isDone && $onPhoto,
                    'stroke-line dark:stroke-line-dark' => ! $isDone && ! $onPhoto,
                ])
            />
        @endforeach
    </svg>
    <span @class([
        'relative inline-flex h-5 w-5 items-center justify-center rounded-full text-[11px] font-bold',
        'bg-success text-white dark:bg-success-dark' => $allDone,
        'bg-accent text-white dark:bg-accent-dark' => ! $allDone && $today,
        'bg-black/30 text-white' => ! $allDone && ! $today && $onPhoto,
        'bg-surface-sunken text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark' => ! $allDone && ! $today && ! $onPhoto,
    ]) aria-hidden="true">
        @if ($allDone)
            @svg('heroicon-s-check', 'h-3.5 w-3.5')
        @else
            {{ $number }}
        @endif
    </span>
</span>
