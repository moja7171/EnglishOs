{{--
    A small row of streak-milestone badges — one per tier (7/30/100 days)
    the learner's LONGEST streak has ever reached (see
    User::longestStreak()), not just their current one, so a badge earned
    once never disappears after a broken streak. Pure display, content-
    free — an achievement count, never a hint at what was answered.

    Rendered as a hand-rolled medal (a disc + two ribbon tails, plain
    primitives only — same "no freehand bezier" rule as
    <x-illustrated-avatar>) with tier-appropriate color intensity, the same
    richer visual language as <x-streak-flame>'s tiered icon/color and
    <x-milestone-path>'s hand-built shapes — not a third, separate style.

    @param int $longestStreak
--}}
@props(['longestStreak' => 0])

@php
    $tiers = [
        ['days' => 100, 'label' => '100-day streak', 'color' => 'text-amber-500', 'ring' => 'ring-amber-300/60 dark:ring-amber-400/40'],
        ['days' => 30, 'label' => '30-day streak', 'color' => 'text-slate-400', 'ring' => 'ring-slate-300/60 dark:ring-slate-500/40'],
        ['days' => 7, 'label' => '7-day streak', 'color' => 'text-accent-ink dark:text-accent-ink-dark', 'ring' => 'ring-accent-soft dark:ring-accent-soft-dark'],
    ];
    $earned = collect($tiers)->filter(fn ($tier) => $longestStreak >= $tier['days']);
@endphp

@if ($earned->isNotEmpty())
    <div {{ $attributes->class(['flex items-center gap-2']) }}>
        @foreach ($earned as $tier)
            <span
                title="{{ $tier['label'] }}"
                @class([
                    'inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full ring-2 drop-shadow-sm',
                    $tier['color'],
                    $tier['ring'],
                ])
            >
                {{-- Medal: two ribbon tails behind a disc with an inner
                     shine ring — plain circles/polygons only. --}}
                <svg viewBox="0 0 24 28" class="h-full w-full" fill="currentColor" aria-hidden="true">
                    <polygon points="8,10 3.5,26 8,24 10.5,27.5 12,14" opacity="0.55" />
                    <polygon points="16,10 20.5,26 16,24 13.5,27.5 12,14" opacity="0.55" />
                    <circle cx="12" cy="11" r="9" />
                    <circle cx="12" cy="11" r="9" fill="none" stroke="white" stroke-opacity="0.35" stroke-width="1" />
                    <circle cx="12" cy="11" r="5" fill="none" stroke="white" stroke-opacity="0.5" stroke-width="1" />
                </svg>
            </span>
        @endforeach
    </div>
@endif
