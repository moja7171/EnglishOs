{{--
    A small row of streak-milestone badges — one per tier (7/30/100 days)
    the learner's LONGEST streak has ever reached (see
    User::longestStreak()), not just their current one, so a badge earned
    once never disappears after a broken streak. Pure display, content-
    free — an achievement count, never a hint at what was answered.

    @param int $longestStreak
--}}
@props(['longestStreak' => 0])

@php
    $tiers = [
        ['days' => 100, 'label' => '100-day streak', 'icon' => 'heroicon-s-trophy', 'color' => 'text-amber-500'],
        ['days' => 30, 'label' => '30-day streak', 'icon' => 'heroicon-s-trophy', 'color' => 'text-slate-400'],
        ['days' => 7, 'label' => '7-day streak', 'icon' => 'heroicon-s-fire', 'color' => 'text-accent-ink dark:text-accent-ink-dark'],
    ];
    $earned = collect($tiers)->filter(fn ($tier) => $longestStreak >= $tier['days']);
@endphp

@if ($earned->isNotEmpty())
    <div {{ $attributes->class(['flex items-center gap-1.5']) }}>
        @foreach ($earned as $tier)
            <span
                title="{{ $tier['label'] }}"
                class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-surface-sunken dark:bg-surface-sunken-dark {{ $tier['color'] }}"
            >
                @svg($tier['icon'], 'h-4 w-4')
            </span>
        @endforeach
    </div>
@endif
