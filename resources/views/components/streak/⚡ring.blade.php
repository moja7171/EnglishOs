<?php

use App\Models\DayActivity;
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
     * What today's slices were, oldest first, as the learner would name
     * them (each mission step is its own slice).
     *
     * @return list<string>
     */
    #[Computed]
    public function doneToday(): array
    {
        $learner = auth()->user();

        return DayActivity::where('learner_id', $learner->id)
            ->whereDate('on_date', $learner->localToday())
            ->orderBy('id')
            ->pluck('activity')
            ->map(fn (string $activity) => match (true) {
                str_starts_with($activity, 'step:') => 'Lesson step',
                $activity === 'review' => 'Review',
                $activity === 'listening' => 'Listening',
                $activity === 'pi' => 'Daily talk',
                $activity === 'pair' => 'Pair practice',
                default => 'Activity',
            })
            ->all();
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
                        'stroke-surface-sunken dark:stroke-line-dark' => $index >= $slices,
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
        @php
            $done = $this->doneToday;
            $open = max(0, StreakRing::SLICES_FOR_FULL - count($done));
            $bigSize = 124;
            $bigStroke = 10;
            $bigRadius = ($bigSize - $bigStroke) / 2;
            $bigCircumference = 2 * M_PI * $bigRadius;
            $bigPart = $bigCircumference / StreakRing::SLICES_FOR_FULL;
        @endphp
        <div class="space-y-4 p-5 pt-2">
            <p class="text-center font-display text-lg font-bold text-ink dark:text-ink-dark">Day {{ $this->dayNumber }}</p>
            <div class="relative mx-auto h-[7.75rem] w-[7.75rem]">
                <svg viewBox="0 0 {{ $bigSize }} {{ $bigSize }}" class="h-full w-full -rotate-90" aria-hidden="true">
                    @foreach (range(0, StreakRing::SLICES_FOR_FULL - 1) as $index)
                        <circle
                            cx="{{ $bigSize / 2 }}" cy="{{ $bigSize / 2 }}" r="{{ $bigRadius }}"
                            fill="none" stroke-width="{{ $bigStroke }}" stroke-linecap="round"
                            stroke-dasharray="{{ $bigPart - 8 }} {{ $bigCircumference - $bigPart + 8 }}"
                            stroke-dashoffset="{{ -($index * $bigPart + 4) }}"
                            @class([
                                'stroke-success dark:stroke-success-dark' => $isFull,
                                'stroke-accent dark:stroke-accent-dark' => ! $isFull && $index < $slices,
                                'stroke-surface-sunken dark:stroke-line-dark' => $index >= $slices,
                            ])
                        ></circle>
                    @endforeach
                </svg>
                <div class="absolute inset-0 flex flex-col items-center justify-center">
                    <span class="text-3xl leading-none font-extrabold text-ink tabular-nums dark:text-ink-dark">{{ $slices }}/{{ StreakRing::SLICES_FOR_FULL }}</span>
                    <span class="mt-1 text-[10px] font-bold tracking-wider text-ink-faint uppercase dark:text-ink-faint-dark">today</span>
                </div>
            </div>
            <p @class([
                'text-center text-sm font-extrabold',
                'text-success dark:text-success-dark' => $ring['keptToday'],
                'text-accent-ink dark:text-accent-ink-dark' => ! $ring['keptToday'],
            ])>
                @if ($isFull)
                    Ring full 🎉
                @elseif ($ring['keptToday'])
                    Streak kept ✓ · 1 more fills the ring
                @else
                    {{ StreakRing::SLICES_TO_KEEP - $slices }} more {{ StreakRing::SLICES_TO_KEEP - $slices === 1 ? 'activity keeps' : 'activities keep' }} your streak
                @endif
            </p>
            <ul class="space-y-1.5">
                @foreach ($done as $label)
                    <li class="flex items-center gap-2.5 rounded-xl bg-surface-sunken px-3 py-2 text-sm font-bold text-ink-faint dark:bg-surface-sunken-dark dark:text-ink-faint-dark">
                        <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-success text-white dark:bg-success-dark">@svg('heroicon-s-check', 'h-3 w-3')</span>
                        {{ $label }}
                    </li>
                @endforeach
                @if ($open > 0)
                    <li class="flex items-center gap-2.5 rounded-xl bg-surface-sunken px-3 py-2 text-sm font-bold text-ink dark:bg-surface-sunken-dark dark:text-ink-dark">
                        <span class="inline-flex h-5 w-5 shrink-0 rounded-full border-2 border-line dark:border-line-dark"></span>
                        <span class="flex-1">Step, review, listening or Daily talk</span>
                        @if ($open > 1)
                            <span class="text-ink-faint dark:text-ink-faint-dark">×{{ $open }}</span>
                        @endif
                    </li>
                @endif
            </ul>
            <div class="flex items-center justify-between gap-3 text-xs font-bold text-ink-soft dark:text-ink-soft-dark">
                <span class="flex items-center gap-1.5">
                    <span aria-hidden="true">🧊</span>
                    {{ $ring['freezes'] }} {{ Str::plural('freeze', $ring['freezes']) }} · next in {{ $ring['fullDaysToNextFreeze'] }} {{ Str::plural('day', $ring['fullDaysToNextFreeze']) }}
                </span>
                <a href="{{ route('progress.index') }}" wire:navigate x-on:click="open = false" class="font-extrabold text-accent-ink hover:underline dark:text-accent-ink-dark">Progress ›</a>
            </div>
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
