{{--
    The last twelve weeks as one calendar (Progress page): a column per week
    (Saturday to Friday), a cell per day coloured by how many kinds of thing
    the learner did that day, today framed, the running streak ringed, future
    days dashed, and under each week a dot that turns green when that week's
    goal of days was met. A tap on a day names what was done. This week is
    the last column: there is no separate "this week" card. The goal chip
    opens the weekly-goal picker of the page around it (the `picker` flag).

    @param array{weeks: list<array<string, mixed>>, total: int, todayIndex: int|null, thisWeekDays: int} $calendar see App\Services\ActivityCalendar
    @param int|null $goal Days a week the learner aims for.
--}}
@props(['calendar', 'goal' => null, 'milestone' => null])

@php
    $letters = ['S', 'S', 'M', 'T', 'W', 'T', 'F'];
    $flat = collect($calendar['weeks'])->flatMap(fn (array $week) => $week['days'])->values();
    $levelClass = [
        0 => 'bg-surface-sunken dark:bg-surface-sunken-dark',
        1 => 'bg-accent/30 dark:bg-accent-dark/30',
        2 => 'bg-accent/65 dark:bg-accent-dark/65',
        3 => 'bg-accent shadow-[0_0_0_3px] shadow-accent/20 dark:bg-accent-dark dark:shadow-accent-dark/25',
    ];
@endphp

<div
    {{ $attributes->class('space-y-3') }}
    x-data="{ sel: {{ $calendar['todayIndex'] ?? 0 }}, days: {{ \Illuminate\Support\Js::from($flat->map(fn (array $day) => ['label' => $day['label'], 'kinds' => $day['kinds'], 'future' => $day['future']])) }} }"
>
    <div class="flex items-start justify-between gap-3">
        <div>
            <p class="font-display text-4xl leading-none font-semibold tracking-tight whitespace-nowrap text-ink tabular-nums dark:text-ink-dark">{{ $calendar['total'] }} {{ Str::plural('day', $calendar['total']) }}</p>
            <p class="mt-1 text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">practiced in the last 12 weeks</p>
        </div>
        <button type="button" x-on:click="picker = true" class="inline-flex shrink-0 cursor-pointer items-center gap-1 rounded-full bg-accent-soft px-3 py-1 text-xs font-bold text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">
            {{ $goal ? 'Goal '.$goal.'/week' : 'Set a goal' }} @svg('heroicon-o-chevron-down', 'h-3 w-3')
        </button>
    </div>

    <div class="grid grid-cols-[14px_repeat(12,minmax(0,1fr))] items-center gap-x-1 gap-y-1" data-testid="activity-calendar">
        <span></span>
        @foreach ($calendar['weeks'] as $week)
            <span class="h-3 text-[9.5px] font-extrabold tracking-wide whitespace-nowrap text-ink-faint uppercase dark:text-ink-faint-dark">{{ $week['month'] }}</span>
        @endforeach

        @foreach ($letters as $row => $letter)
            <span class="text-center text-[9.5px] font-extrabold text-ink-faint dark:text-ink-faint-dark">{{ $letter }}</span>
            @foreach ($calendar['weeks'] as $weekIndex => $week)
                @php
                    $day = $week['days'][$row];
                    $index = $weekIndex * 7 + $row;
                @endphp
                @if ($day['future'])
                    <span class="aspect-square w-full rounded-[7px] border-[1.5px] border-dashed border-line dark:border-line-dark" aria-hidden="true"></span>
                @else
                    <button
                        type="button"
                        x-on:click="sel = {{ $index }}"
                        aria-label="{{ $day['label'] }}: {{ $day['level'] > 0 ? implode(', ', $day['kinds']) : 'no practice' }}"
                        @class([
                            'aspect-square w-full cursor-pointer rounded-[7px]',
                            $levelClass[$day['level']],
                            'outline-2 outline-offset-[1.5px] outline-ink dark:outline-ink-dark' => $day['today'],
                            'ring-2 ring-accent-ink dark:ring-accent-ink-dark' => $day['chain'],
                        ])
                        x-bind:class="sel === {{ $index }} && 'outline-2 outline-offset-2 outline-ink dark:outline-ink-dark'"
                    ></button>
                @endif
            @endforeach
        @endforeach

        <span></span>
        @foreach ($calendar['weeks'] as $week)
            <span
                @class([
                    'mx-auto h-[7px] w-[7px] rounded-full',
                    'bg-success dark:bg-success-dark' => $week['met'],
                    'border-[1.5px] border-dashed border-success dark:border-success-dark' => ! $week['met'] && $week['current'] && $goal,
                    'bg-line dark:bg-line-dark' => ! $week['met'] && ! ($week['current'] && $goal),
                ])
                title="{{ $week['count'] }} {{ Str::plural('day', $week['count']) }}"
            ></span>
        @endforeach
    </div>

    <div class="flex min-h-10 flex-col justify-center rounded-xl bg-surface-sunken px-3 py-2 text-sm dark:bg-surface-sunken-dark" aria-live="polite">
        <b class="text-ink dark:text-ink-dark" x-text="days[sel].label"></b>
        <span class="text-xs text-ink-soft dark:text-ink-soft-dark" x-text="days[sel].kinds.length ? days[sel].kinds.join(' · ') : 'No practice'"></span>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-ink-soft dark:text-ink-soft-dark">
        <span>
            @if ($goal)
                <b class="text-ink dark:text-ink-dark">{{ $calendar['thisWeekDays'] }} of {{ $goal }}</b> {{ Str::plural('day', $goal) }} this week
            @else
                <b class="text-ink dark:text-ink-dark">{{ $calendar['thisWeekDays'] }}</b> {{ Str::plural('day', $calendar['thisWeekDays']) }} this week
            @endif
            @if ($milestone)
                · {{ $milestone }}
            @endif
        </span>
        <span class="inline-flex items-center gap-1">Less
            <i class="h-2.5 w-2.5 rounded-[3px] bg-surface-sunken dark:bg-surface-sunken-dark"></i>
            <i class="h-2.5 w-2.5 rounded-[3px] bg-accent/30 dark:bg-accent-dark/30"></i>
            <i class="h-2.5 w-2.5 rounded-[3px] bg-accent/65 dark:bg-accent-dark/65"></i>
            <i class="h-2.5 w-2.5 rounded-[3px] bg-accent dark:bg-accent-dark"></i>
            More
        </span>
    </div>
</div>
