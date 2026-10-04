{{--
    The frame every review card sits in — Daily Review's word, speaking,
    grammar and error cards, and My Words' word card — so the whole session
    reads as one thing: a type label, "3 / 8" with a thin progress bar when
    the session has a known length, and a couple of paper edges peeking out
    below while more cards are waiting.

    Any extra attributes (x-data, pointer/keyboard handlers, a transform
    style, ...) land on the card element itself, which is how the word
    card makes the whole thing swipeable.

    @param string $label       Type label shown top-left, e.g. "Word".
    @param string $icon        Heroicon name for the label.
    @param int|null $position  1-based place in the session; hides the counter and bar when null.
    @param int|null $total     Session length.
    @param int $remaining      Cards still waiting behind this one (drives the stacked look).
    @param string|null $caption Right-hand text used instead of "3 / 8" (My Words: "5 due").
--}}
@props(['label', 'icon', 'position' => null, 'total' => null, 'remaining' => 0, 'caption' => null])

<div {{ $attributes->class(['card relative space-y-4 p-5 touch-pan-y', 'card-stack' => $remaining > 0]) }}>
    <div class="flex items-center justify-between gap-3">
        <p class="inline-flex items-center gap-1.5 text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">
            @svg($icon, 'h-3.5 w-3.5') {{ $label }}
        </p>
        @if ($caption)
            <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">{{ $caption }}</p>
        @elseif ($position !== null && $total)
            <p class="text-xs font-semibold text-ink-soft tabular-nums dark:text-ink-soft-dark">{{ $position }} / {{ $total }}</p>
        @endif
    </div>

    @if ($position !== null && $total)
        <x-progress-bar>
            <div
                class="h-full rounded-full bg-accent transition-all duration-300 dark:bg-accent-dark"
                style="width: {{ min(100, max(0, ($position - 1) / $total * 100)) }}%"
            ></div>
        </x-progress-bar>
    @endif

    {{ $slot }}
</div>
