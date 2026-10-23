{{--
    One notebook line's body on /notebook — the heading and source, the
    learner's sentence, the better English with a speaker, the Persian why,
    and its buttons: ⭐ (phrase swaps only: star = into Daily Review) and
    Learned. Used for the top cards and inside each folded row.

    @param \App\Models\NotebookEntry $entry
    @param bool $showHeading  False inside a folded row, whose summary already shows it.
--}}
@props(['entry', 'showHeading' => true])

@php
    use App\Services\FeedbackNotebook;
@endphp

<div {{ $attributes->class('space-y-1.5') }}>
    @if ($showHeading)
        <div class="flex items-start justify-between gap-2">
            <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">
                {{ $entry->heading() }}
                · {{ FeedbackNotebook::SOURCES[$entry->source] ?? $entry->source }}
            </p>
            @if ($entry->times_seen > 1)
                <span class="shrink-0 rounded-full bg-warning-soft px-2 py-0.5 text-xs font-semibold text-warning-ink">{{ $entry->times_seen }}×</span>
            @endif
        </div>
    @endif
    @if ($entry->original)
        <p class="text-sm {{ $entry->isMistake() ? 'text-danger-ink line-through decoration-danger' : 'text-ink-soft dark:text-ink-soft-dark' }}">{{ $entry->original }}</p>
    @endif
    <p class="flex items-center gap-2 text-sm font-semibold text-success dark:text-success-dark">{{ $entry->better }} <x-speak-button :text="$entry->better" /></p>
    @if ($entry->note)
        <p class="font-fa text-sm text-ink-soft dark:text-ink-soft-dark" dir="rtl">{{ $entry->note }}</p>
    @endif
    <div class="flex justify-end gap-2 pt-1">
        @if ($entry->isPhraseSwap())
            <button
                type="button"
                wire:click="toggleStar({{ $entry->id }})"
                title="{{ $entry->starred_at ? 'In Daily Review' : 'Add to Daily Review' }}"
                aria-pressed="{{ $entry->starred_at ? 'true' : 'false' }}"
                class="inline-flex cursor-pointer items-center gap-1 rounded-full border px-3 py-1 text-xs font-semibold transition-colors {{ $entry->starred_at ? 'border-warning-line bg-warning-soft text-warning-ink' : 'border-line text-ink-soft hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark' }}"
            >@svg($entry->starred_at ? 'heroicon-s-star' : 'heroicon-o-star', 'h-3.5 w-3.5') Review</button>
        @endif
        <button
            type="button"
            wire:click="markLearned({{ $entry->id }})"
            class="inline-flex cursor-pointer items-center gap-1 rounded-full border border-line px-3 py-1 text-xs font-semibold text-ink-soft transition-colors hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
        >@svg('heroicon-o-check', 'h-3.5 w-3.5') Learned</button>
    </div>
</div>
