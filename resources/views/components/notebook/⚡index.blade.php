<?php

use App\Livewire\Concerns\PractisesNotebookLines;
use App\Models\Mission;
use App\Models\NotebookEntry;
use App\Models\VocabularyWord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * A6 + A10: one Notebook with three tabs — Mistakes, Tips and Words (My
 * Words lives here now). A practice card on top of Mistakes/Tips, one
 * uniform list (tap a line = a bottom sheet), practice full-screen in the
 * review frame. Words is for finding a word and its meaning; it is
 * practised in Daily Review.
 */
new class extends Component
{
    use PractisesNotebookLines;
    use WithFileUploads;

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
     * Every saved word, newest mission first, with the mission it came
     * from — the Words tab.
     *
     * @return Collection<int, VocabularyWord>
     */
    #[Computed]
    public function words(): Collection
    {
        return auth()->user()->vocabularyWords()
            ->with('sourceMissionRun.mission')
            ->orderByDesc('created_at')
            ->orderBy('word')
            ->get();
    }

    /**
     * Authored Persian meanings by lowercased phrase — the fallback for a
     * word saved before its own meaning_fa was stored.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function courseMeaningsFa(): array
    {
        return Mission::persianMeanings();
    }

    public function removeWord(int $wordId): void
    {
        auth()->user()->vocabularyWords()->findOrFail($wordId)->delete();

        unset($this->words);
    }

    /** "Practice this one", from a line's sheet. */
    public function practiceOne(int $entryId): void
    {
        $entry = auth()->user()->notebookEntries()->findOrFail($entryId);

        $this->practiceKind = $entry->kind;
        $this->practiceIds = [$entry->id];
        $this->practisedIds = array_values(array_unique([...$this->practisedIds, $entry->id]));
        $this->practiceSize = 1;
        $this->practiceAt = 0;
        $this->practiceRight = 0;
        $this->resetDrill();
        unset($this->practiceEntry, $this->morePractice);
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

        unset($this->entries);
    }

    public function bringBack(int $entryId): void
    {
        auth()->user()->notebookEntries()->findOrFail($entryId)->update(['learned_at' => null, 'next_review_at' => now()]);

        unset($this->entries);
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

        unset($this->entries);
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
     * A tab's active lines: the ones not practised since feedback saved
     * them first (they light the Notebook tab's dot), then the most
     * repeated. A mistake needs the learner's own sentence to practise.
     *
     * @return Builder<NotebookEntry>
     */
    private function practicable(string $kind): Builder
    {
        return auth()->user()->notebookEntries()->getQuery()
            ->active()
            ->where('kind', $kind)
            ->when($kind === NotebookEntry::KIND_MISTAKE, fn (Builder $query) => $query->whereNotNull('original'))
            ->orderByRaw('case when last_practised_at is null or last_seen_at > last_practised_at then 0 else 1 end')
            ->orderByDesc('times_seen')
            ->orderByDesc('last_seen_at');
    }
};
?>

@php
    $byKind = $this->entries->groupBy('kind');
    $practiceEntry = $practiceIds !== [] ? $this->practiceEntry : null;
    $roundDone = $practiceIds !== [] && ! $practiceEntry;
    $tabs = ['mistakes' => NotebookEntry::KIND_MISTAKE, 'tips' => NotebookEntry::KIND_TIP];
    $activeCount = fn (string $kind) => ($byKind->get($kind) ?? collect())->whereNull('learned_at')->count();
    $pill = 'flex-1 cursor-pointer rounded-full py-2 text-center text-sm font-extrabold transition-colors';
@endphp

@if ($practiceEntry)
    {{-- Practice runs full-screen in the review frame: ✕ ends it. The top bar is
         hidden here, so the page is a fixed-height column (the screen minus the
         bottom nav) and a tall card scrolls inside itself. --}}
    <div class="mx-auto flex h-[calc(100dvh-6rem)] max-w-2xl flex-col gap-4 p-4 sm:p-6">
        <x-lesson-header data-step-header close-click="$wire.stopPractice()" :progress="$practiceAt / max(count($practiceIds), 1)" :counter="($practiceAt + 1).'/'.count($practiceIds)" />
        <div wire:key="practice-{{ $practiceAt }}-{{ $practiceEntry->id }}" class="animate-review-card-in flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto overscroll-contain rounded-[1.75rem] bg-surface p-5 dark:bg-surface-dark">
            <x-notebook-drill :entry="$practiceEntry" :answer="$drillAnswer" :correct="$drillCorrect" :typing="$drillTyping" :heard="$drillHeard" :missed-audio="$drillMissedAudio" :recording="$drillRecording" />
        </div>
    </div>
@else
<div
    class="mx-auto max-w-2xl space-y-4 p-4 sm:p-6"
    x-data="{ notebookTab: @js($practiceKind === NotebookEntry::KIND_TIP && $roundDone ? 'tips' : (in_array(request()->query('tab'), ['tips', 'words'], true) ? request()->query('tab') : 'mistakes')), sheet: false, learned: false, search: '' }"
>
    <h1 class="font-display text-3xl font-semibold text-ink dark:text-ink-dark">Notebook</h1>

    {{-- The practice card (or, after a round, today's score) for the open tab. --}}
    @foreach ($tabs as $tab => $kind)
        @php
            $active = ($byKind->get($kind) ?? collect())->whereNull('learned_at');
            $practicable = $kind === NotebookEntry::KIND_MISTAKE ? $active->whereNotNull('original') : $active;
            $unpractised = $practicable->filter(fn (NotebookEntry $entry) => $entry->isUnpractised())->count();
            $roundSize = min($this::PRACTICE_SIZE, $unpractised ?: $practicable->count());
            $practiseAgain = $unpractised === 0 && $practicable->contains(fn (NotebookEntry $entry) => $entry->last_practised_at?->isToday());
        @endphp
        <div x-show="notebookTab === '{{ $tab }}'" @if ($tab !== 'mistakes') x-cloak @endif>
            @if ($roundDone && $practiceKind === $kind)
                <div class="flex items-center justify-between gap-3 rounded-2xl bg-success-soft px-4 py-3 text-sm font-extrabold text-success dark:bg-success-soft-dark dark:text-success-dark">
                    <span>✓ {{ $practiceRight }}/{{ $practiceSize }} right today</span>
                    @if ($this->morePractice > 0)
                        <button type="button" wire:click="startPractice('{{ $kind }}')" class="cursor-pointer text-accent-ink dark:text-accent-ink-dark">{{ min($this::PRACTICE_SIZE, $this->morePractice) }} more ›</button>
                    @endif
                </div>
            @elseif ($practicable->isNotEmpty())
                <div class="flex items-center gap-3 rounded-3xl bg-accent-soft p-3 dark:bg-accent-soft-dark">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-accent text-white dark:bg-accent-dark">@svg('heroicon-o-pencil', 'h-5 w-5')</span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-base font-extrabold text-ink dark:text-ink-dark">{{ $practiseAgain ? '✓ Practice again' : 'Practice' }}</span>
                        <span class="block text-xs font-bold text-accent-ink dark:text-accent-ink-dark">{{ $roundSize }} {{ Str::plural('line', $roundSize) }} · ~1 min</span>
                    </span>
                    <button type="button" wire:click="startPractice('{{ $kind }}')" class="cursor-pointer rounded-full bg-accent px-5 py-2 text-sm font-extrabold text-white dark:bg-accent-dark">Start</button>
                </div>
            @endif
        </div>
    @endforeach

    <div class="flex gap-1 rounded-full bg-surface-sunken p-1 dark:bg-surface-sunken-dark" role="tablist">
        @foreach (['mistakes' => 'Mistakes', 'tips' => 'Tips', 'words' => 'Words'] as $tab => $label)
            @php $count = $tab === 'words' ? $this->words->count() : $activeCount($tabs[$tab]); @endphp
            <button
                type="button"
                role="tab"
                x-on:click="notebookTab = '{{ $tab }}'; learned = false"
                x-bind:aria-selected="notebookTab === '{{ $tab }}'"
                class="{{ $pill }}"
                x-bind:class="notebookTab === '{{ $tab }}' ? 'bg-surface text-ink shadow-sm dark:bg-surface-dark dark:text-ink-dark' : 'text-ink-soft dark:text-ink-soft-dark'"
            >{{ $label }} <span class="font-bold text-ink-faint dark:text-ink-faint-dark">{{ $count }}</span></button>
        @endforeach
    </div>

    @foreach ($tabs as $tab => $kind)
        @php [$active, $learnedEntries] = ($byKind->get($kind) ?? collect())->partition(fn (NotebookEntry $entry) => $entry->learned_at === null); @endphp
        <div x-show="notebookTab === '{{ $tab }}'" @if ($tab !== 'mistakes') x-cloak @endif class="space-y-3">
            @if ($active->isEmpty())
                <p class="py-6 text-center text-sm text-ink-faint dark:text-ink-faint-dark">{{ $tab === 'mistakes' ? 'No mistakes saved yet.' : 'No tips saved yet.' }}</p>
            @else
                <div class="divide-y divide-line rounded-3xl bg-surface px-4 dark:divide-line-dark dark:bg-surface-dark">
                    @foreach ($active as $entry)
                        <button type="button" wire:key="notebook-row-{{ $entry->id }}" x-on:click="sheet = 'n{{ $entry->id }}'" class="flex w-full cursor-pointer items-center gap-2.5 py-3 text-left">
                            <span @class(['h-2 w-2 shrink-0 rounded-full', 'bg-accent dark:bg-accent-dark' => $entry->isUnpractised(), 'bg-transparent' => ! $entry->isUnpractised()])></span>
                            <span class="min-w-0 flex-1">
                                @if ($entry->original)
                                    <span class="block truncate text-xs text-ink-faint line-through dark:text-ink-faint-dark">{{ $entry->original }}</span>
                                @endif
                                <span class="block truncate text-sm font-bold text-ink dark:text-ink-dark">{{ $entry->better }}</span>
                            </span>
                            @if ($entry->starred_at)
                                @svg('heroicon-s-star', 'h-3.5 w-3.5 shrink-0 text-warning')
                            @endif
                            @if ($entry->times_seen > 1)
                                <span class="shrink-0 rounded-full bg-warning-soft px-2 py-0.5 text-xs font-extrabold text-warning-ink">{{ $entry->times_seen }}×</span>
                            @endif
                        </button>
                    @endforeach
                </div>
            @endif

            @if ($learnedEntries->isNotEmpty())
                <button type="button" x-on:click="learned = ! learned" class="w-full cursor-pointer py-2 text-center text-sm font-extrabold text-ink-faint dark:text-ink-faint-dark">Learned ({{ $learnedEntries->count() }}) ›</button>
                <div x-show="learned" x-cloak class="space-y-2 rounded-3xl bg-surface p-4 dark:bg-surface-dark">
                    @foreach ($learnedEntries as $entry)
                        <div wire:key="notebook-learned-{{ $entry->id }}" class="flex items-center justify-between gap-2">
                            <p class="min-w-0 text-sm text-ink-faint dark:text-ink-faint-dark">{{ $entry->better }}</p>
                            <button type="button" wire:click="bringBack({{ $entry->id }})" class="shrink-0 cursor-pointer text-xs font-bold text-accent-ink dark:text-accent-ink-dark">Bring back</button>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endforeach

    {{-- Words: find a word and its meaning, newest mission first. --}}
    <div x-show="notebookTab === 'words'" x-cloak class="space-y-3">
        @if ($this->words->isEmpty())
            <p class="py-6 text-center text-sm text-ink-faint dark:text-ink-faint-dark">Words from your lessons and the ones you tap in a text show up here.</p>
        @else
            <input type="search" x-model="search" placeholder="Search your words" aria-label="Search your words" class="w-full rounded-2xl border-0 bg-surface px-4 py-2.5 text-sm text-ink placeholder:text-ink-faint focus:ring-2 focus:ring-accent dark:bg-surface-dark dark:text-ink-dark">
            @foreach ($this->words->groupBy(fn (VocabularyWord $word) => $word->sourceMissionRun?->mission?->code ?? '') as $code => $group)
                @php
                    $mission = $group->first()->sourceMissionRun?->mission;
                    $groupWords = $group->map(fn (VocabularyWord $word) => mb_strtolower($word->word))->values()->all();
                @endphp
                <div x-show="! search || {{ \Illuminate\Support\Js::from($groupWords) }}.some(w => w.includes(search.toLowerCase()))">
                    <p class="px-2 pb-1 text-xs font-extrabold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">{{ $mission ? $mission->code.' · '.$mission->title : 'Added by you' }}</p>
                    <div class="divide-y divide-line rounded-3xl bg-surface px-4 dark:divide-line-dark dark:bg-surface-dark">
                        @foreach ($group as $word)
                            @php $meaningFa = $word->meaning_fa ?: ($this->courseMeaningsFa[mb_strtolower($word->word)] ?? null); @endphp
                            <button type="button" wire:key="word-{{ $word->id }}" x-show="! search || {{ \Illuminate\Support\Js::from(mb_strtolower($word->word)) }}.includes(search.toLowerCase())" x-on:click="sheet = 'w{{ $word->id }}'" class="flex w-full cursor-pointer items-center gap-3 py-2.5 text-left">
                                <span class="shrink-0 text-sm font-extrabold text-ink dark:text-ink-dark">{{ $word->word }}</span>
                                <span dir="rtl" class="font-fa min-w-0 flex-1 truncate text-sm text-ink-soft dark:text-ink-soft-dark">{{ $meaningFa }}</span>
                                <span class="flex shrink-0 gap-0.5" aria-hidden="true">
                                    @for ($dot = 1; $dot <= 5; $dot++)
                                        <span class="h-1 w-2 rounded-full {{ $dot <= $word->strengthLevel() ? 'bg-success dark:bg-success-dark' : 'bg-line dark:bg-line-dark' }}"></span>
                                    @endfor
                                </span>
                            </button>
                        @endforeach
                    </div>
                </div>
            @endforeach
        @endif
    </div>

    {{-- One sheet; what it shows follows the tapped row. --}}
    <x-bottom-sheet show="sheet" label="Details">
        @foreach ($this->entries as $entry)
            <div x-show="sheet === 'n{{ $entry->id }}'" wire:key="notebook-sheet-{{ $entry->id }}" class="flex flex-col gap-2">
                <p class="text-xs font-extrabold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">
                    {{ $entry->heading() }} · {{ \App\Services\FeedbackNotebook::SOURCES[$entry->source] ?? $entry->source }}@if ($entry->times_seen > 1) · {{ $entry->times_seen }}×@endif
                </p>
                @if ($entry->original)
                    <p class="text-sm {{ $entry->isMistake() ? 'text-danger-ink line-through decoration-danger' : 'text-ink-soft dark:text-ink-soft-dark' }}">{{ $entry->original }}</p>
                @endif
                <p class="flex items-center gap-2 text-base font-extrabold text-success dark:text-success-dark">{{ $entry->better }} <x-speak-button :text="$entry->better" /></p>
                @if ($entry->note)
                    <p class="font-fa text-sm leading-relaxed text-ink-soft dark:text-ink-soft-dark" dir="rtl">{{ $entry->note }}</p>
                @endif
                <div class="mt-2 grid grid-cols-2 gap-2">
                    @if ($entry->learned_at === null)
                        <button type="button" wire:click="markLearned({{ $entry->id }})" x-on:click="sheet = false" class="cursor-pointer rounded-2xl bg-surface-sunken py-3 text-sm font-extrabold text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark">✓ Learned</button>
                    @endif
                    @if ($entry->isPhraseSwap())
                        <button type="button" wire:click="toggleStar({{ $entry->id }})" aria-pressed="{{ $entry->starred_at ? 'true' : 'false' }}" class="cursor-pointer rounded-2xl py-3 text-sm font-extrabold {{ $entry->starred_at ? 'bg-warning-soft text-warning-ink' : 'bg-surface-sunken text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark' }}">{{ $entry->starred_at ? '★ In Review' : '☆ Review' }}</button>
                    @elseif ($entry->learned_at === null && (! $entry->isMistake() || $entry->original))
                        <button type="button" wire:click="practiceOne({{ $entry->id }})" class="cursor-pointer rounded-2xl bg-success py-3 text-sm font-extrabold text-white dark:bg-success-dark">✎ Practice</button>
                    @endif
                </div>
            </div>
        @endforeach
        @foreach ($this->words as $word)
            @php
                $meaningFa = $word->meaning_fa ?: ($this->courseMeaningsFa[mb_strtolower($word->word)] ?? null);
                $mission = $word->sourceMissionRun?->mission;
            @endphp
            <div x-show="sheet === 'w{{ $word->id }}'" wire:key="word-sheet-{{ $word->id }}" class="flex flex-col gap-2">
                <x-speak-word :word="$word->word" class="font-display text-3xl font-semibold text-ink dark:text-ink-dark" />
                @if (filled($word->pos) || $mission)
                    <span class="w-fit rounded-full bg-accent-soft px-2.5 py-0.5 text-xs font-extrabold text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">{{ collect([$word->pos, $mission?->code])->filter()->implode(' · ') }}</span>
                @endif
                @if (filled($word->meaning))
                    <p class="text-sm text-ink dark:text-ink-dark">{{ $word->meaning }}</p>
                @endif
                @if (filled($meaningFa))
                    <p dir="rtl" class="font-fa text-base font-extrabold text-accent-ink dark:text-accent-ink-dark">{{ $meaningFa }}</p>
                @endif
                @if (filled($word->example))
                    <p class="text-sm text-ink-soft italic dark:text-ink-soft-dark">{{ $word->highlightIn($word->example) }}</p>
                @endif
                <div class="mt-1 flex items-center justify-between text-sm font-bold">
                    <span class="text-ink-faint dark:text-ink-faint-dark">{{ $word->strengthLabel() }}</span>
                    <button type="button" wire:click="removeWord({{ $word->id }})" wire:confirm="Remove {{ $word->word }} from your words?" x-on:click="sheet = false" class="cursor-pointer text-danger-ink">Remove</button>
                </div>
            </div>
        @endforeach
    </x-bottom-sheet>
</div>
@endif
