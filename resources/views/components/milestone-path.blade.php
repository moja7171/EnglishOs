{{--
    A horizontal path toward the next streak badge — <x-streak-badges>
    shows what's already earned; this shows what's still ahead, a nearer-
    term goal than a bare badge collection. See User::nextStreakMilestone()
    / daysUntilNextMilestone().

    @param int $currentStreak
--}}
@props(['currentStreak' => 0])

@php
    $tiers = [7, 30, 100];
    $max = end($tiers);
    $progressPercent = min(100, $currentStreak / $max * 100);
    $next = collect($tiers)->first(fn ($tier) => $tier > $currentStreak);
@endphp

<div {{ $attributes }}>
    <div class="relative mt-2 mb-5 h-2 rounded-full bg-surface-sunken dark:bg-surface-sunken-dark">
        <div
            class="h-full rounded-full bg-accent transition-all duration-500 dark:bg-accent-dark"
            style="width: {{ $progressPercent }}%"
        ></div>
        @foreach ($tiers as $tier)
            @php
                $position = min(100, $tier / $max * 100);
                $reached = $currentStreak >= $tier;
            @endphp
            <div class="absolute top-1/2 -translate-y-1/2" style="left: {{ $position }}%">
                <span
                    @class([
                        'block h-3.5 w-3.5 -translate-x-1/2 rounded-full border-2',
                        'border-accent bg-accent dark:border-accent-dark dark:bg-accent-dark' => $reached,
                        'border-line bg-ground dark:border-line-dark dark:bg-ground-dark' => ! $reached,
                    ])
                ></span>
                <span class="absolute top-4 left-1/2 -translate-x-1/2 text-[10px] whitespace-nowrap text-ink-faint dark:text-ink-faint-dark">{{ $tier }}d</span>
            </div>
        @endforeach
    </div>

    @if ($next)
        <p class="text-xs text-ink-faint dark:text-ink-faint-dark">{{ $next - $currentStreak }} {{ Str::plural('day', $next - $currentStreak) }} to your {{ $next }}-day badge</p>
    @else
        <p class="text-xs text-success dark:text-success-dark">You've earned every streak badge — incredible.</p>
    @endif
</div>
