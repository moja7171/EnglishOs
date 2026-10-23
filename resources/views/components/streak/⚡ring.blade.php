<?php

use App\Services\StreakRing;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    /**
     * @return array{current: int, longest: int, last: int, keptToday: bool, todaySlices: int, freezes: int, frozenDates: list<string>, keptDates: list<string>, fullDaysToNextFreeze: int}
     */
    #[Computed]
    public function ring(): array
    {
        return auth()->user()->streakRing();
    }

    /**
     * Any slice recorded during another component's request (see
     * StreakRing::record()) refreshes the ring at once.
     */
    #[On('streak-ring-changed')]
    public function refreshRing(): void
    {
        unset($this->ring);
    }

    /**
     * The day the ring is building: today's number once kept, else the
     * day it would become (day 1 for a fresh start).
     */
    #[Computed]
    public function dayNumber(): int
    {
        return $this->ring['current'] + ($this->ring['keptToday'] ? 0 : 1);
    }

    /**
     * True for the one render after today's ring filled — it celebrates,
     * once a day.
     */
    #[Computed]
    public function celebrate(): bool
    {
        $learner = auth()->user();

        return $this->ring['todaySlices'] >= StreakRing::SLICES_FOR_FULL
            && Cache::add('streak-ring-celebrated:'.$learner->id.':'.$learner->localToday()->toDateString(), true, now()->addDays(2));
    }
};
?>

@php
    $ring = $this->ring;
    $slices = min(StreakRing::SLICES_FOR_FULL, $ring['todaySlices']);
    $isFull = $slices >= StreakRing::SLICES_FOR_FULL;
    $size = 34;
    $stroke = 3.5;
    $radius = ($size - $stroke) / 2;
    $circumference = 2 * M_PI * $radius;
    $part = $circumference / StreakRing::SLICES_FOR_FULL;
    $gap = 3;
@endphp

{{--
    The daily streak ring in the header: the day number inside, one
    segment per activity done today (StreakRing::SLICES_FOR_FULL fill it,
    SLICES_TO_KEEP keep the streak). Tapping it says how it works; filling
    it pops a short celebration once a day.
--}}
<div class="relative" x-data="{ open: false }" x-on:click.outside="open = false" wire:poll.60s.visible>
    <button
        type="button"
        x-on:click="open = !open"
        title="Daily streak"
        aria-label="Day {{ $this->dayNumber }} streak, {{ $slices }} of {{ StreakRing::SLICES_FOR_FULL }} today"
        class="relative inline-flex h-9 w-9 cursor-pointer items-center justify-center rounded-full transition-transform active:scale-95"
    >
        <svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 {{ $size }} {{ $size }}" class="-rotate-90" aria-hidden="true">
            @foreach (range(0, StreakRing::SLICES_FOR_FULL - 1) as $index)
                <circle
                    wire:key="ring-slice-{{ $index }}"
                    cx="{{ $size / 2 }}" cy="{{ $size / 2 }}" r="{{ $radius }}"
                    fill="none" stroke-width="{{ $stroke }}" stroke-linecap="round"
                    stroke-dasharray="{{ $part - $gap }} {{ $circumference - $part + $gap }}"
                    stroke-dashoffset="{{ -($index * $part + $gap / 2) }}"
                    @class([
                        'transition-colors duration-500',
                        'stroke-success dark:stroke-success-dark' => $isFull,
                        'stroke-accent dark:stroke-accent-dark' => ! $isFull && $index < $slices,
                        'stroke-surface-sunken dark:stroke-surface-sunken-dark' => $index >= $slices,
                    ])
                ></circle>
            @endforeach
        </svg>
        <span @class([
            'absolute inset-0 flex items-center justify-center text-[11px] font-extrabold tabular-nums',
            'text-ink dark:text-ink-dark' => $ring['keptToday'],
            'text-ink-faint dark:text-ink-faint-dark' => ! $ring['keptToday'],
        ])>{{ $this->dayNumber }}</span>
    </button>

    <x-menu-panel width="sm:w-72" aria-label="Daily streak">
        <div class="space-y-3 p-4">
            <div class="flex items-center gap-2">
                <x-streak-flame :streak="max(1, $ring['current'])" size="h-5 w-5" />
                <p class="font-display text-base font-bold text-ink dark:text-ink-dark">Day {{ $this->dayNumber }}</p>
                <span class="ml-auto text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">{{ $slices }}/{{ StreakRing::SLICES_FOR_FULL }} today</span>
            </div>

            <p class="text-sm font-semibold text-ink dark:text-ink-dark">
                @if ($isFull)
                    Ring full 🎉
                @elseif ($ring['keptToday'])
                    Streak kept ✓ · 1 more fills the ring
                @else
                    {{ StreakRing::SLICES_TO_KEEP - $slices }} more {{ StreakRing::SLICES_TO_KEEP - $slices === 1 ? 'activity keeps' : 'activities keep' }} your streak
                @endif
            </p>

            <p class="text-xs text-ink-soft dark:text-ink-soft-dark">A step, today's review, listening, Pi or pair practice = 1 slice.</p>

            <p class="flex items-center gap-1.5 border-t border-line pt-3 text-xs text-ink-soft dark:border-line-dark dark:text-ink-soft-dark">
                <span aria-hidden="true">🧊</span>
                <span>{{ $ring['freezes'] }} {{ Str::plural('freeze', $ring['freezes']) }} — saves a missed day · next in {{ $ring['fullDaysToNextFreeze'] }} full {{ Str::plural('day', $ring['fullDaysToNextFreeze']) }}</span>
            </p>
        </div>
    </x-menu-panel>

    @if ($this->celebrate)
        <div
            wire:key="ring-celebration"
            x-data="{ show: true }"
            x-init="window.eosConfetti?.burst(); navigator.vibrate?.([80, 60, 120]); setTimeout(() => show = false, 2200)"
            x-show="show"
            x-transition.opacity.duration.300ms
            x-on:click="show = false"
            role="status"
            class="fixed inset-0 z-[90] flex items-center justify-center bg-ground/80 p-6 dark:bg-ground-dark/80"
        >
            <div class="animate-trophy-pop text-center">
                <div class="mx-auto flex h-28 w-28 items-center justify-center rounded-full border-8 border-success bg-success-soft dark:border-success-dark dark:bg-success-soft-dark">
                    <span class="font-display text-4xl font-extrabold text-ink tabular-nums dark:text-ink-dark">{{ $this->dayNumber }}</span>
                </div>
                <p dir="rtl" class="mt-4 font-display text-3xl font-extrabold text-ink dark:text-ink-dark">ترکوندی! 🔥</p>
                <p class="mt-1 text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">Day {{ $this->dayNumber }} · ring full</p>
            </div>
        </div>
    @endif
</div>
