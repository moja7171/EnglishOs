{{--
    A real single-month calendar grid — the "look back at one specific
    month" companion to <x-activity-heatmap>'s 12-week strip, fed by the
    same generic User::activityForMonth(). Purely presentational; month
    navigation (prev/next) lives in the caller (see progress/⚡index.blade.php)
    since that needs Livewire state.

    @param list<array{date: string, day: int, active: bool, future: bool, inMonth: bool, isToday: bool}> $days
--}}
@props(['days' => []])

<div {{ $attributes }}>
    <div class="grid grid-cols-7 gap-1 text-center text-[10px] font-semibold text-ink-faint uppercase dark:text-ink-faint-dark">
        <span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span>
    </div>
    <div class="mt-1 grid grid-cols-7 gap-1">
        @foreach ($days as $day)
            <div
                title="{{ $day['date'] }}{{ $day['active'] ? ' — practiced' : '' }}"
                @class([
                    'flex aspect-square items-center justify-center rounded-lg text-xs font-semibold',
                    'text-ink-faint/40 dark:text-ink-faint-dark/40' => ! $day['inMonth'],
                    'text-ink-faint dark:text-ink-faint-dark' => $day['inMonth'] && ! $day['active'] && ! $day['isToday'],
                    'bg-accent text-white dark:bg-accent-dark' => $day['active'],
                    'ring-2 ring-accent dark:ring-accent-dark' => $day['isToday'] && ! $day['active'],
                ])
            >{{ $day['day'] }}</div>
        @endforeach
    </div>
</div>
