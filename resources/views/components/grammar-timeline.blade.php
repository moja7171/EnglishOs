{{--
    A generic horizontal time-axis diagram for grammar points that genuinely
    need a "when did this happen relative to now" visual (e.g. Present
    Perfect vs Past Simple) — pure HTML/CSS, no image or external API. Not
    wired into M01 yet: M01's own grammar point (frequency adverbs) is
    already visualized as a frequency scale, not a timeline — this is for
    whichever future mission teaches a tense/aspect contrast. Content is
    fully author-driven from MissionSeeder, same pattern as Grammar in
    Context's existing frequency_scale pill row.
--}}
@props([
    'spans' => [],   // list of {label: string, start: int, end: int, color?: string} — start/end are 0-100 along the axis
    'markers' => [], // list of {label: string, position: int, color?: string} — position is 0-100
    'nowLabel' => 'Now',
])

<div {{ $attributes->class(['py-5']) }}>
    <div class="relative h-1.5 rounded-full bg-surface-sunken dark:bg-surface-sunken-dark">
        @foreach ($spans as $span)
            <div
                class="absolute top-0 h-1.5 rounded-full {{ $span['color'] ?? 'bg-accent dark:bg-accent-dark' }}"
                style="left: {{ $span['start'] }}%; width: {{ max($span['end'] - $span['start'], 2) }}%"
                title="{{ $span['label'] }}"
            ></div>
        @endforeach

        @foreach ($markers as $marker)
            <div
                class="absolute top-1/2 h-3 w-3 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-surface {{ $marker['color'] ?? 'bg-ink dark:bg-ink-dark' }} dark:border-surface-dark"
                style="left: {{ $marker['position'] }}%"
                title="{{ $marker['label'] }}"
            ></div>
        @endforeach

        <div class="absolute top-1/2 h-3 w-0.5 -translate-y-1/2 bg-ink dark:bg-ink-dark" style="left: 100%"></div>
    </div>

    <div class="relative mt-2 h-8 text-xs">
        @foreach ($spans as $span)
            <span
                class="absolute -translate-x-1/2 text-center font-semibold text-nowrap text-ink-soft dark:text-ink-soft-dark"
                style="left: {{ ($span['start'] + $span['end']) / 2 }}%"
            >{{ $span['label'] }}</span>
        @endforeach

        @foreach ($markers as $marker)
            <span
                class="absolute -translate-x-1/2 text-center text-nowrap text-ink-faint dark:text-ink-faint-dark"
                style="left: {{ $marker['position'] }}%"
            >{{ $marker['label'] }}</span>
        @endforeach

        <span class="absolute -translate-x-full text-right font-semibold text-nowrap text-ink dark:text-ink-dark" style="left: 100%">{{ $nowLabel }}</span>
    </div>
</div>
