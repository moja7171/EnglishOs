{{--
    A horizontal path toward the next streak badge — <x-streak-badges>
    shows what's already earned; this shows what's still ahead, a nearer-
    term goal than a bare badge collection. See User::nextStreakMilestone()
    / daysUntilNextMilestone().

    One equal-width segment per tier (0→7, 7→30, 30→100) instead of a
    single 0→100 bar: a linear bar left the 7-day tier crammed against
    the start and pushed the last marker/label past the card's edge. Each
    segment fills on its own, and its marker sits INSIDE its right edge.

    @param int $currentStreak
--}}
@props(['currentStreak' => 0])

@php
    $tiers = [7, 30, 100];
    $next = collect($tiers)->first(fn ($tier) => $tier > $currentStreak);
@endphp

<div {{ $attributes }}>
    <div class="mt-2 mb-6 flex">
        @foreach ($tiers as $index => $tier)
            @php
                $edgeRounding = ($index === 0 ? 'rounded-l-full ' : '').($index === count($tiers) - 1 ? 'rounded-r-full' : '');
                $previousTier = $index === 0 ? 0 : $tiers[$index - 1];
                $fillPercent = max(0, min(100, ($currentStreak - $previousTier) / ($tier - $previousTier) * 100));
                $reached = $currentStreak >= $tier;
            @endphp
            <div class="relative h-2 flex-1 bg-surface-sunken dark:bg-surface-sunken-dark {{ $edgeRounding }}">
                <div
                    class="h-full bg-accent transition-all duration-500 dark:bg-accent-dark {{ $edgeRounding }}"
                    style="width: {{ $fillPercent }}%"
                ></div>
                <span
                    @class([
                        'absolute top-1/2 right-0 inline-flex h-4 w-4 -translate-y-1/2 items-center justify-center rounded-full border-2',
                        'border-accent bg-accent text-white dark:border-accent-dark dark:bg-accent-dark' => $reached,
                        'border-line bg-ground dark:border-line-dark dark:bg-ground-dark' => ! $reached,
                    ])
                >
                    @if ($reached)
                        @svg('heroicon-s-check', 'h-2.5 w-2.5')
                    @endif
                </span>
                <span class="absolute top-4 right-0 text-[10px] whitespace-nowrap text-ink-faint dark:text-ink-faint-dark">{{ $tier }}d</span>
            </div>
        @endforeach
    </div>

    @if ($next)
        <p class="text-xs text-ink-faint dark:text-ink-faint-dark">{{ $next - $currentStreak }} {{ Str::plural('day', $next - $currentStreak) }} to your {{ $next }}-day badge</p>
    @else
        <p class="text-xs text-success dark:text-success-dark">You've earned every streak badge — incredible.</p>
    @endif
</div>
