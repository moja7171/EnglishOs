{{--
    The Again / Good / Easy row under every self-graded review card (the
    word card's back, Daily Review's speaking and grammar cards). Each grade
    is an icon — ↻ Again, 👍 Good, 🚀 Easy — over the gap it would schedule
    ("6d"); the word itself stays as the accessible name and the tooltip.

    @param mixed $item     Anything with nextIntervalLabel(int $quality).
    @param bool $alpine    true = the word card's client-side grade(n) (disabled while busy);
                           false = Livewire's gradeSelf(n).
    @param list<int> $grades Which of 1 (Again), 4 (Good), 5 (Easy) to offer.
    @param string|null $keyHint Classes for the 1/2/3 keyboard hints; null hides them.
--}}
@props(['item', 'alpine' => false, 'grades' => [1, 4, 5], 'keyHint' => null])

@php
    $styles = [
        1 => ['label' => 'Again', 'icon' => 'heroicon-o-arrow-path', 'class' => 'border-danger-line bg-danger-soft text-danger-ink'],
        4 => ['label' => 'Good', 'icon' => 'heroicon-o-hand-thumb-up', 'class' => 'border-line text-ink hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-dark dark:hover:bg-surface-sunken-dark'],
        5 => ['label' => 'Easy', 'icon' => 'heroicon-o-rocket-launch', 'class' => 'border-success/40 bg-success-soft text-success dark:border-success-dark/40 dark:bg-success-soft-dark dark:text-success-dark'],
    ];
@endphp

<div @class(['grid gap-2', 'grid-cols-2' => count($grades) === 2, 'grid-cols-3' => count($grades) !== 2])>
    @foreach ($grades as $grade)
        @php $style = $styles[$grade]; @endphp
        <button
            type="button"
            @if ($alpine)
                x-on:click="grade({{ $grade }})"
                x-bind:disabled="busy"
            @else
                wire:click="gradeSelf({{ $grade }})"
            @endif
            title="{{ $style['label'] }} — next in {{ $item->nextIntervalLabel($grade) }}"
            class="flex cursor-pointer flex-col items-center gap-1 rounded-xl border px-2 py-2.5 transition-colors hover:opacity-90 disabled:opacity-50 {{ $style['class'] }}"
        >
            <span class="sr-only">{{ $style['label'] }}</span>
            @svg($style['icon'], 'h-6 w-6')
            <span class="text-xs font-semibold tabular-nums opacity-80">{{ $item->nextIntervalLabel($grade) }}</span>
            @if ($keyHint)
                <kbd class="{{ $keyHint }}">{{ $loop->iteration }}</kbd>
            @endif
        </button>
    @endforeach
</div>
