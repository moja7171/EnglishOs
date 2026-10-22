@props(['href', 'title', 'hint' => null, 'done' => false, 'tick' => null, 'tickLabel' => null, 'label' => null])

{{-- One of the Today box's extra rows (Daily Review, Voice practice, Extra
     listening). The circle on the left is its checklist tick, the same
     family as the steps' circles: empty until done, green with a check once
     done. Where the learner reports it themselves ($tick = the Livewire
     method) the circle is a button, so it can be ticked without leaving
     home; the rest of the row opens the page. Not a step: never gates
     Continue. --}}
<div class="mt-3 flex items-center rounded-xl bg-surface-sunken pe-2 dark:bg-surface-sunken-dark">
    @if ($done)
        <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center">
            <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-success text-white dark:bg-success-dark">@svg('heroicon-s-check', 'h-3.5 w-3.5')</span>
        </span>
    @elseif ($tick)
        <button
            type="button"
            wire:click="{{ $tick }}"
            wire:loading.attr="disabled"
            aria-label="{{ $tickLabel }}"
            class="group inline-flex h-11 w-11 shrink-0 cursor-pointer items-center justify-center"
        >
            <span class="inline-flex h-6 w-6 items-center justify-center rounded-full border-2 border-line text-transparent transition-colors group-hover:border-accent group-hover:text-accent group-active:bg-accent-soft dark:border-line-dark dark:group-hover:border-accent-dark dark:group-hover:text-accent-dark dark:group-active:bg-accent-soft-dark">@svg('heroicon-s-check', 'h-3.5 w-3.5')</span>
        </button>
    @else
        <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center">
            <span class="inline-flex h-6 w-6 rounded-full border-2 border-line dark:border-line-dark"></span>
        </span>
    @endif
    <a
        href="{{ $href }}"
        wire:navigate
        class="flex min-w-0 flex-1 items-center gap-2 py-2.5 transition-opacity hover:opacity-80"
        @if ($label) aria-label="{{ $label }}" @endif
    >
        <span class="min-w-0 flex-1">
            <span class="block text-sm font-semibold text-ink dark:text-ink-dark">{{ $title }}</span>
            @if ($hint)
                <span @class([
                    'block text-xs',
                    'font-semibold text-success dark:text-success-dark' => $done,
                    'text-ink-faint dark:text-ink-faint-dark' => ! $done,
                ])>{{ $hint }}</span>
            @endif
        </span>
        @svg('heroicon-o-chevron-right', 'h-4 w-4 shrink-0 text-ink-faint dark:text-ink-faint-dark')
    </a>
</div>
