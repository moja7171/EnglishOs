<?php

use App\Livewire\Concerns\PractisesNotebookLines;
use App\Models\NotebookEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use PractisesNotebookLines;
    use WithFileUploads;

    /** Each tab shows this many lines in full; the rest fold to one line each. */
    public const TOP_LINES = 3;

    public const PRACTICE_SIZE = 5;

    /**
     * The lines in the "Practice 5 now" round, in order — empty when no
     * round is on. A line answered wrong comes back once at the end (past
     * $practiceSize). Practice moves a line's rung (see
     * PractisesNotebookLines) but never its review schedule.
     *
     * @var list<int>
     */
    public array $practiceIds = [];

    /** How many lines the round started with — the rest are second goes. */
    public int $practiceSize = 0;

    public int $practiceAt = 0;

    public int $practiceRight = 0;

    /** NotebookEntry::KIND_MISTAKE or KIND_TIP — which tab the round is from. */
    public string $practiceKind = NotebookEntry::KIND_MISTAKE;

    /**
     * Every line practised on this visit, so "5 more" brings new ones.
     *
     * @var list<int>
     */
    public array $practisedIds = [];

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
     * The most repeated lines of one tab not yet practised on this visit.
     */
    public function startPractice(string $kind = NotebookEntry::KIND_MISTAKE): void
    {
        if (! in_array($kind, [NotebookEntry::KIND_MISTAKE, NotebookEntry::KIND_TIP], true)) {
            return;
        }

        $this->practiceKind = $kind;
        $this->practiceIds = $this->practicable($kind)
            ->whereNotIn('id', $this->practisedIds)
            ->limit(self::PRACTICE_SIZE)
            ->pluck('id')
            ->all();
        $this->practisedIds = [...$this->practisedIds, ...$this->practiceIds];
        $this->practiceSize = count($this->practiceIds);
        $this->practiceAt = 0;
        $this->practiceRight = 0;
        $this->resetDrill();
        unset($this->practiceEntry, $this->morePractice);
    }

    /**
     * Lines of the round's tab still left for a "5 more".
     */
    #[Computed]
    public function morePractice(): int
    {
        return $this->practicable($this->practiceKind)->whereNotIn('id', $this->practisedIds)->count();
    }

    public function stopPractice(): void
    {
        $this->practiceIds = [];
        $this->practiceSize = 0;
        $this->practiceAt = 0;
        $this->practiceRight = 0;
        $this->resetDrill();
        unset($this->practiceEntry);
    }

    protected function drillEntry(): ?NotebookEntry
    {
        return $this->practiceEntry;
    }

    /**
     * Only a first go counts toward "N / 5 right"; a wrong first go comes
     * back once at the end of the round — unless it was the last card, so
     * the same card never shows twice in a row.
     */
    protected function afterDrill(NotebookEntry $entry, bool $correct): void
    {
        $isFirstGo = $this->practiceAt < $this->practiceSize;

        if ($isFirstGo && $correct) {
            $this->practiceRight++;
        }

        if ($isFirstGo && ! $correct && $this->practiceAt < count($this->practiceIds) - 1) {
            $this->practiceIds[] = $entry->id;
        }

        $this->practiceAt++;
        unset($this->practiceEntry);
    }

    /**
     * A tab's active lines, the most repeated first. A mistake needs the
     * learner's own sentence to practise.
     *
     * @return Builder<NotebookEntry>
     */
    private function practicable(string $kind): Builder
    {
        return auth()->user()->notebookEntries()->getQuery()
            ->active()
            ->where('kind', $kind)
            ->when($kind === NotebookEntry::KIND_MISTAKE, fn (Builder $query) => $query->whereNotNull('original'))
            ->orderByDesc('times_seen')
            ->orderByDesc('last_seen_at');
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
            <div wire:key="practice-{{ $practiceAt }}-{{ $practiceEntry->id }}">
                <x-review-shell
                    label="Practice"
                    :icon="$practiceEntry->isMistake() ? 'heroicon-o-pencil' : 'heroicon-o-light-bulb'"
                    :position="$practiceAt + 1"
                    :total="count($practiceIds)"
                    :remaining="count($practiceIds) - $practiceAt - 1"
                >
                    <x-notebook-drill :entry="$practiceEntry" :answer="$drillAnswer" :correct="$drillCorrect" :typing="$drillTyping" :heard="$drillHeard" :missed-audio="$drillMissedAudio" :recording="$drillRecording" />
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
                <p class="font-display text-xl font-extrabold text-ink dark:text-ink-dark">{{ $practiceRight }} / {{ $practiceSize }} right</p>
                <div class="flex flex-wrap justify-center gap-2">
                    @if ($this->morePractice > 0)
                        <button
                            type="button"
                            wire:click="startPractice('{{ $practiceKind }}')"
                            class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-line px-6 py-2.5 text-sm font-semibold text-ink-soft transition-colors hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                        >@svg('heroicon-o-arrow-path', 'h-4 w-4') {{ min($this::PRACTICE_SIZE, $this->morePractice) }} more</button>
                    @endif
                    <button
                        type="button"
                        wire:click="stopPractice"
                        class="inline-flex cursor-pointer items-center rounded-full bg-accent px-6 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
                    >Done</button>
                </div>
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
                $practicable = $kind === NotebookEntry::KIND_MISTAKE ? $active->whereNotNull('original') : $active;
            @endphp
            <div x-show="notebookTab === '{{ $tab }}'" @if ($tab !== 'mistakes') x-cloak @endif class="space-y-3">
                @if ($practicable->isNotEmpty())
                    <button
                        type="button"
                        wire:click="startPractice('{{ $kind }}')"
                        class="flex w-full cursor-pointer items-center justify-center gap-2 rounded-full bg-accent px-5 py-3 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
                    >@svg('heroicon-o-pencil', 'h-4 w-4') Practice {{ min($this::PRACTICE_SIZE, $practicable->count()) }} now</button>
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
