<?php

use App\Models\NotebookEntry;
use App\Services\FixChecker;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    /** Each tab shows this many lines in full; the rest fold to one line each. */
    public const TOP_LINES = 3;

    public const PRACTICE_SIZE = 5;

    /**
     * The mistakes in the "Practice 5 now" round, in order — empty when no
     * round is on. Practice never touches the review schedule: it is extra
     * typing on the learner's own mistakes, not a review.
     *
     * @var list<int>
     */
    public array $practiceIds = [];

    public int $practiceAt = 0;

    public int $practiceRight = 0;

    public string $practiceAttempt = '';

    /** Null until the current card is checked or its fix shown. */
    public ?bool $practiceCorrect = null;

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
     * Lines Daily Review would show now, before its daily cap.
     */
    #[Computed]
    public function waitingCount(): int
    {
        return auth()->user()->notebookEntries()->inReview()->where('next_review_at', '<=', now())->count();
    }

    #[Computed]
    public function practiceEntry(): ?NotebookEntry
    {
        $entryId = $this->practiceIds[$this->practiceAt] ?? null;

        return $entryId ? auth()->user()->notebookEntries()->find($entryId) : null;
    }

    /**
     * Off the list and out of Daily Review. A mistake made again later
     * comes back by itself (see FeedbackNotebook).
     */
    public function markLearned(int $entryId): void
    {
        auth()->user()->notebookEntries()->findOrFail($entryId)->update(['learned_at' => now()]);

        unset($this->entries, $this->waitingCount);
    }

    public function bringBack(int $entryId): void
    {
        auth()->user()->notebookEntries()->findOrFail($entryId)->update(['learned_at' => null, 'next_review_at' => now()]);

        unset($this->entries, $this->waitingCount);
    }

    /**
     * A phrase swap goes to Daily Review only while starred — see
     * NotebookEntry::scopeInReview().
     */
    public function toggleStar(int $entryId): void
    {
        $entry = auth()->user()->notebookEntries()->findOrFail($entryId);

        if (! $entry->isPhraseSwap()) {
            return;
        }

        $entry->update(['starred_at' => $entry->starred_at ? null : now()]);

        unset($this->entries, $this->waitingCount);
    }

    /**
     * The most repeated mistakes the learner can retype (a line needs
     * their own sentence to fix).
     */
    public function startPractice(): void
    {
        $this->practiceIds = auth()->user()->notebookEntries()
            ->active()
            ->where('kind', NotebookEntry::KIND_MISTAKE)
            ->whereNotNull('original')
            ->orderByDesc('times_seen')
            ->orderByDesc('last_seen_at')
            ->limit(self::PRACTICE_SIZE)
            ->pluck('id')
            ->all();
        $this->practiceAt = 0;
        $this->practiceRight = 0;
        $this->resetPracticeCard();
    }

    public function checkPractice(): void
    {
        $entry = $this->practiceEntry;

        if (! $entry || $this->practiceCorrect !== null || trim($this->practiceAttempt) === '') {
            return;
        }

        $this->practiceCorrect = app(FixChecker::class)->accepts((string) $entry->original, $this->practiceAttempt, $entry->better);

        if ($this->practiceCorrect) {
            $this->practiceRight++;
        }
    }

    public function showPracticeFix(): void
    {
        if ($this->practiceEntry && $this->practiceCorrect === null) {
            $this->practiceCorrect = false;
        }
    }

    public function nextPractice(): void
    {
        if ($this->practiceCorrect === null) {
            return;
        }

        $this->practiceAt++;
        $this->resetPracticeCard();
    }

    public function stopPractice(): void
    {
        $this->practiceIds = [];
        $this->practiceAt = 0;
        $this->practiceRight = 0;
        $this->resetPracticeCard();
    }

    private function resetPracticeCard(): void
    {
        $this->practiceAttempt = '';
        $this->practiceCorrect = null;
        unset($this->practiceEntry);
    }
};
?>

@php
    $byKind = $this->entries->groupBy('kind');
    $practising = $practiceIds !== [];
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
            <p class="mt-0.5 text-sm text-ink-soft dark:text-ink-soft-dark">Saved from your feedback.</p>
            @if ($this->waitingCount > 0)
                <p class="mt-0.5 text-xs font-semibold text-accent-ink dark:text-accent-ink-dark">{{ $this->waitingCount }} waiting for review</p>
            @endif
        </div>
    </header>

    @if ($practising)
        @php $practiceEntry = $this->practiceEntry; @endphp
        @if ($practiceEntry)
            <div wire:key="practice-{{ $practiceEntry->id }}">
                <x-review-shell label="Practice" icon="heroicon-o-pencil" :position="$practiceAt + 1" :total="count($practiceIds)" :remaining="count($practiceIds) - $practiceAt - 1">
                    <p class="text-sm text-ink-soft dark:text-ink-soft-dark">
                        You said: <span class="text-danger-ink line-through decoration-danger">{{ $practiceEntry->original }}</span>
                    </p>
                    @if ($practiceCorrect === null)
                        <form wire:submit="checkPractice" class="flex items-center gap-2">
                            <input
                                type="text"
                                wire:model="practiceAttempt"
                                aria-label="Write the sentence correctly"
                                placeholder="Write it correctly…"
                                class="w-full rounded-lg border border-line bg-transparent px-2 py-2 text-sm text-ink dark:border-line-dark dark:text-ink-dark"
                            >
                            <button
                                type="submit"
                                title="Check"
                                class="inline-flex h-10 w-10 shrink-0 cursor-pointer items-center justify-center rounded-full bg-accent text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
                            ><span class="sr-only">Check</span>@svg('heroicon-o-check', 'h-5 w-5')</button>
                        </form>
                        <button
                            type="button"
                            wire:click="showPracticeFix"
                            class="inline-flex cursor-pointer items-center gap-1 text-xs font-semibold text-ink-faint transition-colors hover:text-ink hover:underline dark:text-ink-faint-dark dark:hover:text-ink-dark"
                        >@svg('heroicon-o-eye', 'h-3.5 w-3.5') Just show the fix</button>
                    @else
                        @if ($practiceCorrect)
                            <p class="inline-flex items-center gap-1 text-sm font-semibold text-success dark:text-success-dark">
                                @svg('heroicon-o-check-circle', 'h-4 w-4') Spot on!
                            </p>
                        @elseif (trim($practiceAttempt) !== '')
                            <p class="text-sm text-ink-soft dark:text-ink-soft-dark">You wrote: {{ $practiceAttempt }}</p>
                        @endif
                        <p class="flex items-center gap-2 text-sm text-success dark:text-success-dark">{{ $practiceEntry->better }} <x-speak-button :text="$practiceEntry->better" /></p>
                        @if ($practiceEntry->note)
                            <p class="font-fa text-sm text-ink-soft dark:text-ink-soft-dark" dir="rtl">{{ $practiceEntry->note }}</p>
                        @endif
                        <button
                            type="button"
                            wire:click="nextPractice"
                            title="Next"
                            class="flex w-full cursor-pointer items-center justify-center rounded-full bg-accent px-5 py-3 text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
                        ><span class="sr-only">Next</span>@svg('heroicon-o-arrow-right', 'h-5 w-5')</button>
                    @endif
                </x-review-shell>
            </div>
            <div class="text-center">
                <button
                    type="button"
                    wire:click="stopPractice"
                    class="inline-flex cursor-pointer items-center gap-1 text-xs font-semibold text-ink-faint transition-colors hover:text-ink hover:underline dark:text-ink-faint-dark dark:hover:text-ink-dark"
                >@svg('heroicon-o-x-mark', 'h-3.5 w-3.5') Stop</button>
            </div>
        @else
            <div class="flex flex-col items-center gap-3 card p-8 text-center">
                @svg('heroicon-o-check-badge', 'h-8 w-8 text-success dark:text-success-dark')
                <p class="font-display text-xl font-extrabold text-ink dark:text-ink-dark">{{ $practiceRight }} / {{ count($practiceIds) }} right</p>
                <button
                    type="button"
                    wire:click="stopPractice"
                    class="inline-flex cursor-pointer items-center rounded-full bg-accent px-6 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
                >Done</button>
            </div>
        @endif
    @else
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
                $canPractise = $tab === 'mistakes' && $active->whereNotNull('original')->isNotEmpty();
            @endphp
            <div x-show="notebookTab === '{{ $tab }}'" @if ($tab !== 'mistakes') x-cloak @endif class="space-y-3">
                @if ($canPractise)
                    <button
                        type="button"
                        wire:click="startPractice"
                        class="flex w-full cursor-pointer items-center justify-center gap-2 rounded-full bg-accent px-5 py-3 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
                    >@svg('heroicon-o-pencil', 'h-4 w-4') Practice {{ min($this::PRACTICE_SIZE, $active->whereNotNull('original')->count()) }} now</button>
                @endif

                @forelse ($active->take($this::TOP_LINES) as $entry)
                    <x-notebook-entry :entry="$entry" wire:key="notebook-{{ $entry->id }}" class="card p-4" />
                @empty
                    <div class="card p-6 text-center text-sm text-ink-faint dark:text-ink-faint-dark">
                        {{ $tab === 'mistakes' ? 'No mistakes saved yet.' : 'No tips saved yet.' }}
                    </div>
                @endforelse

                @if ($active->count() > $this::TOP_LINES)
                    <div class="card divide-y divide-line px-4 dark:divide-line-dark">
                        @foreach ($active->slice($this::TOP_LINES) as $entry)
                            <details wire:key="notebook-row-{{ $entry->id }}" class="group py-3">
                                <summary class="flex cursor-pointer list-none items-center gap-2 text-sm">
                                    <span class="min-w-0 flex-1 truncate text-ink dark:text-ink-dark">{{ $entry->label !== null ? $entry->heading() : $entry->better }}</span>
                                    @if ($entry->starred_at)
                                        @svg('heroicon-s-star', 'h-3.5 w-3.5 shrink-0 text-warning')
                                    @endif
                                    @if ($entry->times_seen > 1)
                                        <span class="shrink-0 rounded-full bg-warning-soft px-2 py-0.5 text-xs font-semibold text-warning-ink">{{ $entry->times_seen }}×</span>
                                    @endif
                                    @svg('heroicon-o-chevron-down', 'h-4 w-4 shrink-0 text-ink-faint transition-transform group-open:rotate-180 dark:text-ink-faint-dark')
                                </summary>
                                <x-notebook-entry :entry="$entry" :show-heading="false" class="pt-2" />
                            </details>
                        @endforeach
                    </div>
                @endif

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
    @endif
</div>
