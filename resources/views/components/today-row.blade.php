@props(['title', 'href' => null, 'minutes' => null, 'done' => false, 'current' => false, 'tick' => null, 'tickLabel' => null, 'label' => null, 'muted' => false])

{{-- One line of the Today box: the day's steps, Daily Review and voice
     practice all share it (Extra listening too, $muted), so the whole day
     reads as one checklist — circle · name · minutes. The circle is the
     tick: green once done, a play mark on the row the main button points
     at. Where the learner reports it themselves ($tick = the Livewire
     method) the circle is a button, so it can be ticked without leaving
     home. Without $href the row is plain text (a step further ahead). --}}
@php
    $textClass = match (true) {
        $done => 'text-ink-faint line-through dark:text-ink-faint-dark',
        $current => 'font-semibold text-ink dark:text-ink-dark',
        $muted || ! $href => 'text-ink-faint dark:text-ink-faint-dark',
        default => 'text-ink-soft dark:text-ink-soft-dark',
    };
@endphp
<li @class(['flex items-center rounded-xl pe-2.5 transition-colors', $muted ? 'text-xs' : 'text-sm', 'hover:bg-surface-sunken dark:hover:bg-surface-sunken-dark' => $href])>
    @if ($done)
        <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center">
            <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-success text-white dark:bg-success-dark">@svg('heroicon-s-check', 'h-3 w-3')</span>
        </span>
    @elseif ($tick)
        <button
            type="button"
            wire:click="{{ $tick }}"
            wire:loading.attr="disabled"
            aria-label="{{ $tickLabel }}"
            class="group inline-flex h-10 w-10 shrink-0 cursor-pointer items-center justify-center"
        >
            <span class="inline-flex h-5 w-5 items-center justify-center rounded-full border-2 border-line text-transparent transition-colors group-hover:border-accent group-hover:text-accent group-active:bg-accent-soft dark:border-line-dark dark:group-hover:border-accent-dark dark:group-hover:text-accent-dark dark:group-active:bg-accent-soft-dark">@svg('heroicon-s-check', 'h-3 w-3')</span>
        </button>
    @elseif ($current)
        <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center">
            <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-accent text-white dark:bg-accent-dark">@svg('heroicon-s-play', 'h-2.5 w-2.5')</span>
        </span>
    @else
        <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center">
            <span class="inline-flex h-5 w-5 rounded-full border border-line dark:border-line-dark"></span>
        </span>
    @endif

    @if ($href)
        <a
            href="{{ $href }}"
            wire:navigate
            @if ($label) aria-label="{{ $label }}" @endif
            class="flex min-w-0 flex-1 items-center gap-2 py-2 {{ $textClass }}"
        >
            <span class="min-w-0 flex-1 truncate">{{ $title }}</span>
            @if ($minutes && ! $done)
                <span class="shrink-0 text-xs font-normal text-ink-faint dark:text-ink-faint-dark">{{ $minutes }} min</span>
            @endif
        </a>
    @else
        <span class="flex min-w-0 flex-1 items-center gap-2 py-2 {{ $textClass }}">
            <span class="min-w-0 flex-1 truncate">{{ $title }}</span>
            @if ($minutes && ! $done)
                <span class="shrink-0 text-xs">{{ $minutes }} min</span>
            @endif
        </span>
    @endif
</li>
