@props(['done', 'action', 'label', 'doneLabel'])

{{-- The one big, obvious tick at the end of a self-reported habit page
     (/listening): a full-width row whose circle fills green once made.
     One-way, like the home rows' circles. --}}
<div>
    @if ($done)
        <div class="flex items-center gap-3 rounded-2xl bg-success-soft px-4 py-3.5 text-sm font-semibold text-success dark:bg-success-soft-dark dark:text-success-dark">
            <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-success text-white dark:bg-success-dark">@svg('heroicon-s-check', 'h-4 w-4')</span>
            {{ $doneLabel }}
        </div>
    @else
        <button
            type="button"
            wire:click="{{ $action }}"
            wire:loading.attr="disabled"
            class="group flex w-full cursor-pointer items-center gap-3 rounded-2xl border-2 border-line px-4 py-3.5 text-start transition-colors hover:border-accent disabled:opacity-60 dark:border-line-dark dark:hover:border-accent-dark"
        >
            <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full border-2 border-line text-transparent transition-colors group-hover:border-accent group-hover:text-accent dark:border-line-dark dark:group-hover:border-accent-dark dark:group-hover:text-accent-dark">@svg('heroicon-s-check', 'h-4 w-4')</span>
            <span class="min-w-0 flex-1 text-sm font-semibold text-ink dark:text-ink-dark">{{ $label }}</span>
        </button>
    @endif
</div>
