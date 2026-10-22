<?php

use App\Models\NotebookEntry;
use App\Services\FeedbackNotebook;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    /**
     * Every line of both notebooks, the most often repeated first — a
     * mistake made five times is the one worth looking at.
     *
     * @return Collection<int, NotebookEntry>
     */
    #[Computed]
    public function entries(): Collection
    {
        return auth()->user()->notebookEntries()
            ->orderByDesc('times_seen')
            ->orderByDesc('last_seen_at')
            ->get();
    }

    /**
     * Off the list and out of Daily Review. A mistake made again later
     * comes back by itself (see FeedbackNotebook).
     */
    public function markLearned(int $entryId): void
    {
        auth()->user()->notebookEntries()->findOrFail($entryId)->update(['learned_at' => now()]);

        unset($this->entries);
    }

    public function bringBack(int $entryId): void
    {
        auth()->user()->notebookEntries()->findOrFail($entryId)->update(['learned_at' => null, 'next_review_at' => now()]);

        unset($this->entries);
    }
};
?>

@php
    $byKind = $this->entries->groupBy('kind');
@endphp

<div class="mx-auto max-w-2xl space-y-6 p-4 sm:p-6" x-data="{ notebookTab: 'mistakes' }">
    <a href="{{ route('home') }}" class="inline-flex cursor-pointer items-center gap-1 rounded-full border border-line px-3 py-1.5 text-xs leading-none font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">
        @svg('heroicon-o-chevron-left', 'h-3.5 w-3.5')
        All missions
    </a>

    <header class="flex items-center gap-3 card p-4">
        <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">
            @svg('heroicon-o-book-open', 'h-5 w-5')
        </span>
        <div>
            <h1 class="font-display text-2xl font-extrabold text-ink dark:text-ink-dark">Notebook</h1>
            <p class="mt-0.5 text-sm text-ink-soft dark:text-ink-soft-dark">Saved from your feedback. Reviewed in Daily Review.</p>
        </div>
    </header>

    <x-tab-nav
        tab-var="notebookTab"
        :tabs="[
            'mistakes' => 'Mistakes ('.($byKind->get(NotebookEntry::KIND_MISTAKE)?->whereNull('learned_at')->count() ?? 0).')',
            'tips' => 'Tips ('.($byKind->get(NotebookEntry::KIND_TIP)?->whereNull('learned_at')->count() ?? 0).')',
        ]"
    />

    @foreach (['mistakes' => NotebookEntry::KIND_MISTAKE, 'tips' => NotebookEntry::KIND_TIP] as $tab => $kind)
        @php
            [$active, $learned] = ($byKind->get($kind) ?? collect())->partition(fn (NotebookEntry $entry) => $entry->learned_at === null);
        @endphp
        <div x-show="notebookTab === '{{ $tab }}'" @if ($tab !== 'mistakes') x-cloak @endif class="space-y-3">
            @forelse ($active as $entry)
                <div wire:key="notebook-{{ $entry->id }}" class="space-y-1.5 card p-4">
                    <div class="flex items-start justify-between gap-2">
                        <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">
                            {{ $entry->techniqueName() ?? $entry->label ?? 'Say it like this' }}
                            · {{ FeedbackNotebook::SOURCES[$entry->source] ?? $entry->source }}
                        </p>
                        @if ($entry->times_seen > 1)
                            <span class="shrink-0 rounded-full bg-warning-soft px-2 py-0.5 text-xs font-semibold text-warning-ink">{{ $entry->times_seen }}×</span>
                        @endif
                    </div>
                    @if ($entry->original)
                        <p class="text-sm {{ $entry->isMistake() ? 'text-danger-ink line-through decoration-danger' : 'text-ink-soft dark:text-ink-soft-dark' }}">{{ $entry->original }}</p>
                    @endif
                    <p class="flex items-center gap-2 text-sm font-semibold text-success dark:text-success-dark">{{ $entry->better }} <x-speak-button :text="$entry->better" /></p>
                    @if ($entry->note)
                        <p class="font-fa text-sm text-ink-soft dark:text-ink-soft-dark" dir="rtl">{{ $entry->note }}</p>
                    @endif
                    <div class="flex justify-end pt-1">
                        <button
                            type="button"
                            wire:click="markLearned({{ $entry->id }})"
                            class="inline-flex cursor-pointer items-center gap-1 rounded-full border border-line px-3 py-1 text-xs font-semibold text-ink-soft transition-colors hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                        >@svg('heroicon-o-check', 'h-3.5 w-3.5') Learned</button>
                    </div>
                </div>
            @empty
                <div class="card p-6 text-center text-sm text-ink-faint dark:text-ink-faint-dark">
                    {{ $tab === 'mistakes' ? 'No mistakes saved yet.' : 'No tips saved yet.' }}
                </div>
            @endforelse

            @if ($learned->isNotEmpty())
                <details class="card p-4">
                    <summary class="cursor-pointer text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">Learned ({{ $learned->count() }})</summary>
                    <div class="mt-3 space-y-2">
                        @foreach ($learned as $entry)
                            <div wire:key="notebook-learned-{{ $entry->id }}" class="flex items-center justify-between gap-2">
                                <p class="min-w-0 text-sm text-ink-faint dark:text-ink-faint-dark">{{ $entry->better }}</p>
                                <button
                                    type="button"
                                    wire:click="bringBack({{ $entry->id }})"
                                    class="shrink-0 cursor-pointer text-xs font-semibold text-accent-ink hover:underline dark:text-accent-ink-dark"
                                >Bring back</button>
                            </div>
                        @endforeach
                    </div>
                </details>
            @endif
        </div>
    @endforeach
</div>
