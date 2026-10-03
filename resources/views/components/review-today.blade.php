@props(['review'])

{{-- Today's Daily Review, shown inside the Today box as one quiet row that
     opens /review — same pattern as <x-listening-today>: not one of the
     numbered steps, never gates Continue. The count is today's batch (see
     User::dailyReviewItems()), not the whole backlog, so it stays a
     finishable "a couple of minutes". Once the batch is done the row
     stays, with a "Reviewed today" tick. --}}
@php
    $remaining = $review['remaining'];
    $breakdown = $review['breakdown'];
    $parts = collect([
        $breakdown['word'] ? $breakdown['word'].' '.Str::plural('word', $breakdown['word']) : null,
        $breakdown['grammar'] ? $breakdown['grammar'].' grammar' : null,
        $breakdown['speaking'] ? $breakdown['speaking'].' speaking' : null,
    ])->filter()->push('~'.max(1, (int) ceil($remaining / 4)).' min')->implode(' · ');
@endphp
<a
    href="{{ route('review.index') }}"
    wire:navigate
    class="mt-3 flex items-center gap-3 rounded-xl bg-surface-sunken px-3 py-2.5 transition-colors hover:opacity-90 dark:bg-surface-sunken-dark"
    aria-label="Daily Review"
>
    <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">
        @svg('heroicon-o-bolt', 'h-4 w-4')
    </span>
    <span class="min-w-0 flex-1">
        @if ($remaining > 0)
            <span class="block text-sm font-semibold text-ink dark:text-ink-dark">{{ $remaining }} {{ Str::plural('item', $remaining) }} ready for Daily Review</span>
            <span class="block text-xs text-ink-faint dark:text-ink-faint-dark">{{ $parts }}</span>
        @else
            <span class="block text-sm font-semibold text-ink dark:text-ink-dark">Daily Review</span>
        @endif
    </span>
    @if ($remaining === 0)
        <span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-success-soft px-2 py-0.5 text-[11px] font-semibold text-success dark:bg-success-soft-dark dark:text-success-dark">
            @svg('heroicon-s-check', 'h-3 w-3') Reviewed today
        </span>
    @endif
    @svg('heroicon-o-chevron-right', 'h-4 w-4 shrink-0 text-ink-faint dark:text-ink-faint-dark')
</a>
