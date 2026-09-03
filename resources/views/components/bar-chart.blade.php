{{--
    A minimal bar chart — plain flex/height bars, no SVG or library
    needed. Built for vocabulary growth-by-week (see
    User::vocabularyGrowthByWeek()) but generic enough for any small
    labeled-count series.

    @param list<array{label: string, count: int}> $data
--}}
@props(['data' => []])

@php
    $max = max(1, collect($data)->max('count'));
@endphp

<div {{ $attributes }}>
    <div class="flex items-end gap-1.5" style="height: 80px;">
        @foreach ($data as $point)
            <div class="flex flex-1 flex-col items-center gap-1">
                <div class="flex h-full w-full items-end">
                    <div
                        title="{{ $point['label'] }}: {{ $point['count'] }}"
                        class="w-full rounded-t-sm {{ $point['count'] > 0 ? 'bg-accent dark:bg-accent-dark' : 'bg-surface-sunken dark:bg-surface-sunken-dark' }}"
                        style="height: {{ max(2, $point['count'] / $max * 100) }}%"
                    ></div>
                </div>
                <span class="text-[9px] text-ink-faint dark:text-ink-faint-dark">{{ $point['label'] }}</span>
            </div>
        @endforeach
    </div>
</div>
