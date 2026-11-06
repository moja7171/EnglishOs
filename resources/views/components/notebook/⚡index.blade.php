<?php

use App\Livewire\Concerns\PractisesNotebookLines;
use App\Models\Mission;
use App\Models\NotebookEntry;
use App\Models\VocabularyWord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use App\Services\MissionGrid;
use App\Services\WordSentences;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
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

    /**
     * The last word or line taken off a list, kept for the "Undo" bar:
     * its type ('word' or 'entry'), a label and its stored row. Locked, so
     * the browser can't change what Undo puts back.
     *
     * @var array{type: string, label: string, attributes: array<string, mixed>}|null
     */
    #[Locked]
    public ?array $removed = null;

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
            ->with('mission:id,code,title')
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
            ->with(['sourceMissionRun:id,mission_id', 'sourceMissionRun.mission:id,code,title', 'addedInMission:id,code,title', 'sentences'])
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
        return $this->words->contains(fn (VocabularyWord $word) => blank($word->meaning_fa)) ? Mission::persianMeanings() : [];
    }

    /**
     * How common each word is (see VocabularyWord::commonnessOf()), by word id.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function wordCommonness(): array
    {
        return VocabularyWord::commonnessOf($this->words);
    }

    /**
     * The data the one shared word sheet shows, for every word, by id. It
     * rides on the page so a tapped word's sheet opens at once.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function wordSheets(): array
    {
        return $this->words->mapWithKeys(fn (VocabularyWord $word) => [
            $word->id => $word->sheetData($this->wordCommonness[$word->id] ?? null, $this->courseMeaningsFa[mb_strtolower($word->word)] ?? null),
        ])->all();
    }

    /**
     * Words grouped for the Words tab: one card per mission, the latest
     * mission first. A word the lesson gave goes in its mission, and a word
     * the learner added themselves in the mission they added it in; the few
     * with no mission known are last in "More words". Due words come first
     * in a card.
     *
     * @return Collection<int, array{code: string, title: string, cover: ?string, words: Collection<int, VocabularyWord>, due: int, mine: int}>
     */
    #[Computed]
    public function wordGroups(): Collection
    {
        $grid = app(MissionGrid::class);

        return $this->words
            ->groupBy(fn (VocabularyWord $word) => $word->mission()?->code ?? '')
            ->map(function (Collection $words, string $code) use ($grid) {
                $words = $words->sortBy(fn (VocabularyWord $word) => $word->isDue() ? 0 : 1)->values();

                return [
                    'code' => $code,
                    'title' => $code === '' ? 'More words' : $words->first()->mission()->title,
                    'cover' => $code === '' ? null : $grid->coverUrl($code),
                    'words' => $words,
                    'due' => $words->filter(fn (VocabularyWord $word) => $word->isDue())->count(),
                    'mine' => $words->whereNull('source_mission_run_id')->count(),
                ];
            })
            ->sortBy([
                fn (array $a, array $b) => ($a['code'] === '') <=> ($b['code'] === ''),
                fn (array $a, array $b) => (int) preg_replace('/\D/', '', $b['code']) <=> (int) preg_replace('/\D/', '', $a['code']),
            ])
            ->values();
    }

    /**
     * The lines the Mistakes tab lists: mistakes and "better ways" — the
     * speaking techniques only feed the Tip card.
     *
     * @return Collection<int, NotebookEntry>
     */
    #[Computed]
    public function visibleEntries(): Collection
    {
        return $this->entries->reject(fn (NotebookEntry $entry) => $entry->isTechnique())->values();
    }

    /**
     * The Tip card on top of Mistakes: the speaking technique suggested
     * last, until the learner says "Got it".
     */
    #[Computed]
    public function tipCard(): ?NotebookEntry
    {
        return $this->entries
            ->filter(fn (NotebookEntry $entry) => $entry->isTechnique() && $entry->learned_at === null)
            ->sortByDesc('last_seen_at')
            ->first();
    }

    public function gotTip(int $entryId): void
    {
        $entry = auth()->user()->notebookEntries()->findOrFail($entryId);

        if ($entry->isTechnique()) {
            $entry->update(['learned_at' => now()]);
        }

        unset($this->entries, $this->visibleEntries, $this->tipCard);
    }

    /**
     * The active lines grouped by what kind of mistake they are (past
     * simple, a / an / the, …), the kind made most often first; the ones
     * with no kind, then the "better ways", last.
     *
     * @return Collection<int, array{title: string, entries: Collection<int, NotebookEntry>}>
     */
    public function entryGroups(): Collection
    {
        $rank = fn (string $title): int => match ($title) {
            'Sounds more natural' => 2,
            'Other mistakes' => 1,
            default => 0,
        };

        return $this->visibleEntries->whereNull('learned_at')
            ->groupBy(fn (NotebookEntry $entry): string => match (true) {
                $entry->isPhraseSwap() => 'Sounds more natural',
                $entry->label !== null => $entry->heading(),
                default => 'Other mistakes',
            })
            ->map(fn (Collection $entries, string $title) => ['title' => $title, 'entries' => $entries->values()])
            ->sortBy([
                fn (array $a, array $b) => $rank($a['title']) <=> $rank($b['title']),
                fn (array $a, array $b) => $b['entries']->sum('times_seen') <=> $a['entries']->sum('times_seen'),
            ])
            ->values();
    }

    /**
     * The same lines, one card per mission they were made in, the latest
     * mission first — the way the Words tab is laid out.
     *
     * @return Collection<int, array{code: string, title: string, cover: ?string, entries: Collection<int, NotebookEntry>, due: int}>
     */
    public function entryMissionGroups(): Collection
    {
        $grid = app(MissionGrid::class);

        return $this->visibleEntries->whereNull('learned_at')
            ->groupBy(fn (NotebookEntry $entry): string => $entry->mission?->code ?? '')
            ->map(fn (Collection $entries, string $code) => [
                'code' => $code,
                'title' => $code === '' ? 'More lines' : $entries->first()->mission->title,
                'cover' => $code === '' ? null : $grid->coverUrl($code),
                'entries' => $entries->values(),
                'due' => $entries->filter(fn (NotebookEntry $entry) => $entry->isMistake() && $entry->isDue())->count(),
            ])
            ->sortBy([
                fn (array $a, array $b) => ($a['code'] === '') <=> ($b['code'] === ''),
                fn (array $a, array $b) => (int) preg_replace('/\D/', '', $b['code']) <=> (int) preg_replace('/\D/', '', $a['code']),
            ])
            ->values();
    }

    /**
     * Another simple sentence for the word. Null when the AI can't make
     * one, and the sheet says so.
     */
    public function makeSentence(int $wordId): ?string
    {
        $word = auth()->user()->vocabularyWords()->with('sentences')->findOrFail($wordId);
        $sentence = app(WordSentences::class)->makeOne($word, auth()->user());

        unset($this->words, $this->wordGroups, $this->wordSheets, $this->wordCommonness);

        return $sentence?->sentence;
    }

    /**
     * Checks the sentence the learner wrote with the word and keeps it.
     *
     * @return array{ok: bool, used: bool, better: ?string, note: string}
     */
    public function checkSentence(int $wordId, string $sentence): array
    {
        $word = auth()->user()->vocabularyWords()->with('sentences')->findOrFail($wordId);
        $result = app(WordSentences::class)->check($word, $sentence, auth()->user());

        unset($this->words, $this->wordGroups, $this->wordSheets, $this->wordCommonness);

        return $result ?? ['ok' => false, 'used' => true, 'better' => null, 'note' => 'I couldn’t check that just now. Try again in a moment.'];
    }

    /**
     * Takes a word off the list. Renderless: the page has already hidden the
     * row and shown its Undo bar (see removeWordNow() below), so the answer
     * is tiny and the page is refreshed in the background later.
     */
    #[Renderless]
    public function removeWord(int $wordId): void
    {
        $word = auth()->user()->vocabularyWords()->findOrFail($wordId);

        $this->removed = ['type' => 'word', 'label' => $word->word, 'attributes' => $word->getAttributes()];
        $word->delete();

        unset($this->words, $this->wordGroups);
    }

    public function removeEntry(int $entryId): void
    {
        $entry = auth()->user()->notebookEntries()->findOrFail($entryId);

        $this->removed = ['type' => 'entry', 'label' => $entry->better, 'attributes' => $entry->getAttributes()];
        $entry->delete();

        unset($this->entries, $this->visibleEntries, $this->tipCard);
    }

    /**
     * Puts the last removed word or line back, as it was.
     */
    public function undoRemove(): void
    {
        if ($this->removed === null || (int) ($this->removed['attributes']['learner_id'] ?? 0) !== auth()->id()) {
            return;
        }

        $model = $this->removed['type'] === 'word' ? new VocabularyWord : new NotebookEntry;

        try {
            $model->forceFill($this->removed['attributes'])->save();
        } catch (UniqueConstraintViolationException) {
            // Feedback saved the same line again in the meantime.
        }

        $this->removed = null;

        unset($this->words, $this->wordGroups, $this->entries, $this->visibleEntries, $this->tipCard);
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

        unset($this->entries, $this->visibleEntries, $this->tipCard);
    }

    public function bringBack(int $entryId): void
    {
        auth()->user()->notebookEntries()->findOrFail($entryId)->update(['learned_at' => null, 'next_review_at' => now()]);

        unset($this->entries, $this->visibleEntries, $this->tipCard);
    }

    /**
     * The most repeated lines of one tab not yet practised on this visit.
     */
    public function startPractice(string $kind = NotebookEntry::KIND_MISTAKE): void
    {
        if ($kind !== NotebookEntry::KIND_MISTAKE) {
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
    $tabs = ['mistakes' => NotebookEntry::KIND_MISTAKE];
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
    x-on:eos-word-saved.window="$wire.$refresh()"
    x-data="{
        notebookTab: @js($roundDone ? 'mistakes' : (in_array(request()->query('tab'), ['mistakes', 'tips', 'words'], true) ? (request()->query('tab') === 'tips' ? 'mistakes' : request()->query('tab')) : 'words')),
        sheet: false,
        learned: false,
        search: '',
        edit: false,
        filter: { words: 'all', mistakes: 'all' },
        open: {},
        expanded: {},
        filtering(tab) { return this.filter[tab] !== 'all' || this.search !== ''; },
        shows(tab, meta) {
            const filter = this.filter[tab];
            return (filter === 'all' || meta.s === filter || meta.f.includes(filter))
                && (this.search === '' || meta.t.includes(this.search.toLowerCase()));
        },
        isOpen(tab, key, first) { return this.filtering(tab) || (key in this.open ? this.open[key] : first); },
        setTab(tab) {
            this.notebookTab = tab;
            this.learned = false;
            this.search = '';
            this.edit = false;
            // Kept in the address, so a redraw of the page (back from the background, Back) opens on the same tab.
            const url = new URL(window.location.href);
            url.searchParams.set('tab', tab);
            history.replaceState(history.state, '', url);
        },
        gone: {},
        undo: null,
        undoTimer: null,
        removeWordNow(id, label) {
            this.gone['w' + id] = true;
            this.undo = { id, label };
            this.sheet = false;
            clearTimeout(this.undoTimer);
            this.$wire.removeWord(id).catch(() => { delete this.gone['w' + id]; this.undo = null; });
            this.undoTimer = setTimeout(() => { this.undo = null; this.$wire.$refresh(); }, 5000);
        },
        undoWord() {
            if (! this.undo) { return; }
            clearTimeout(this.undoTimer);
            delete this.gone['w' + this.undo.id];
            this.undo = null;
            this.$wire.undoRemove();
        },
    }"
>
    <div class="flex items-center justify-between">
        <h1 class="font-display text-3xl font-semibold text-ink dark:text-ink-dark">Notebook</h1>
        <div class="flex items-center gap-1">
            @if ($this->entries->isNotEmpty() || $this->words->isNotEmpty())
                <button type="button" x-on:click="edit = ! edit" x-text="edit ? 'Done' : 'Edit'" class="cursor-pointer px-1 py-1.5 text-sm font-extrabold text-accent-ink dark:text-accent-ink-dark">Edit</button>
            @endif
            <x-usage-guide-button />
        </div>
    </div>

    <div class="flex gap-1 rounded-full bg-surface-sunken p-1 dark:bg-surface-sunken-dark" role="tablist">
        @foreach (['words' => 'Words', 'mistakes' => 'Mistakes'] as $tab => $label)
            @php
                $count = $tab === 'words' ? $this->words->count() : $this->visibleEntries->whereNull('learned_at')->count();
                $newCount = $tab === 'words' ? 0 : $this->visibleEntries->filter(fn (NotebookEntry $entry) => $entry->isUnpractised())->count();
            @endphp
            <button
                type="button"
                role="tab"
                x-on:click="setTab('{{ $tab }}')"
                x-bind:aria-selected="notebookTab === '{{ $tab }}'"
                class="{{ $pill }}"
                x-bind:class="notebookTab === '{{ $tab }}' ? 'bg-surface text-ink shadow-sm dark:bg-surface-dark dark:text-ink-dark' : 'text-ink-soft dark:text-ink-soft-dark'"
            >{{ $label }} <span class="font-bold text-ink-faint dark:text-ink-faint-dark">{{ $count }}</span>@if ($newCount > 0) <span class="ml-0.5 inline-block min-w-4 rounded-full bg-accent px-1 align-middle text-[0.65rem] leading-4 font-extrabold text-white dark:bg-accent-dark" title="Not practised yet">{{ $newCount }}</span>@endif</button>
        @endforeach
    </div>

    @php
        $statusDot = fn (string $status) => match ($status) {
            'known' => 'bg-success dark:bg-success-dark',
            'learning' => 'bg-warning',
            default => 'bg-ink-faint dark:bg-ink-faint-dark',
        };
        $removeSheetButton = 'mt-1 inline-flex w-full cursor-pointer items-center justify-center gap-2 rounded-2xl border border-danger-line bg-danger-soft py-3 text-sm font-extrabold text-danger-ink';
        $removeButton = 'inline-flex h-7 w-7 shrink-0 cursor-pointer items-center justify-center rounded-full bg-danger-soft text-danger-ink';
    @endphp

    {{-- Mistakes: the lines the learner got wrong (and a few "better ways"), grouped by kind of mistake or by mission, with one Tip card on top. --}}
    @php
        [$active, $learnedEntries] = $this->visibleEntries->partition(fn (NotebookEntry $entry) => $entry->learned_at === null);
        $learnedEntries = $learnedEntries->values();
        $tip = $this->tipCard;
        $sources = \App\Services\FeedbackNotebook::SOURCES;
        $sourceOf = fn (NotebookEntry $entry) => $sources[$entry->source] ?? $entry->source;
        $metaOf = fn (NotebookEntry $entry) => [
            's' => $entry->studyStatus(),
            'f' => array_values(array_filter([$entry->times_seen > 1 ? 'repeat' : null])),
            't' => mb_strtolower($entry->original.' '.$entry->better.' '.$entry->note),
        ];
        $allMetas = $active->map($metaOf)->values();
        $statusCounts = $active->countBy(fn (NotebookEntry $entry) => $entry->studyStatus());
        $segments = [
            ['status' => 'new', 'label' => 'Not practised', 'count' => $statusCounts->get('new', 0)],
            ['status' => 'learning', 'label' => 'Practising', 'count' => $statusCounts->get('learning', 0)],
            ['status' => 'known', 'label' => 'Learned', 'count' => $learnedEntries->count(), 'learned' => true],
        ];
        $chips = [['all', 'All', $active->count()], ['repeat', 'Repeating', $active->where('times_seen', '>', 1)->count()], ['new', 'Not practised', $statusCounts->get('new', 0)]];
    @endphp
    <div x-show="notebookTab === 'mistakes'" x-cloak class="space-y-3" x-data="{ all: {{ \Illuminate\Support\Js::from($allMetas) }}, searching: false, learnedAll: false, tipGone: false, groupBy: (() => { try { return localStorage.getItem('eos-mistakes-group') === 'type' ? 'type' : 'mission'; } catch (e) { return 'mission'; } })(), setGroupBy(by) { this.groupBy = by; try { localStorage.setItem('eos-mistakes-group', by); } catch (e) {} } }">
        @if ($tip)
            <div x-show="! tipGone" class="flex items-start gap-3 rounded-3xl bg-surface p-4 dark:bg-surface-dark">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300">@svg('heroicon-o-sparkles', 'h-5 w-5')</span>
                <div class="min-w-0 flex-1">
                    <p class="text-xs font-extrabold tracking-wide text-sky-800 uppercase dark:text-sky-300">Tip · {{ $tip->techniqueName() }}</p>
                    <p class="mt-0.5 text-[15px] leading-snug text-ink dark:text-ink-dark">{{ \App\Services\LearnerFeedback::TECHNIQUES[$tip->label]['why'] ?? $tip->better }}</p>
                    <button type="button" wire:click="gotTip({{ $tip->id }})" x-on:click="tipGone = true" class="mt-2 cursor-pointer text-sm font-extrabold text-accent-ink dark:text-accent-ink-dark">Got it</button>
                </div>
            </div>
        @endif

        @if ($active->isEmpty() && $learnedEntries->isEmpty())
            <p class="py-6 text-center text-sm text-ink-faint dark:text-ink-faint-dark">No mistakes saved yet.</p>
        @else
            <div x-show="searching || search !== ''" x-cloak>
                <input type="search" x-model="search" x-ref="entrySearch" placeholder="Search your mistakes" aria-label="Search your mistakes" class="w-full rounded-2xl border-0 bg-surface px-4 py-2.5 text-sm text-ink placeholder:text-ink-faint focus:ring-2 focus:ring-accent dark:bg-surface-dark dark:text-ink-dark">
            </div>
            <x-notebook-filter-bar tab="mistakes" :segments="$segments" :chips="$chips">
                <x-slot:lead>
                    <button type="button" x-on:click="searching = ! searching; if (searching) { setTimeout(() => $refs.entrySearch.focus(), 60) } else { search = '' }" x-bind:aria-pressed="searching || search !== ''" aria-label="Search your mistakes" class="inline-flex h-8 w-8 shrink-0 cursor-pointer items-center justify-center rounded-full bg-surface text-ink-soft dark:bg-surface-dark dark:text-ink-soft-dark">@svg('heroicon-o-magnifying-glass', 'h-4 w-4')</button>
                </x-slot:lead>
            </x-notebook-filter-bar>

            <div class="flex items-center justify-between gap-3">
                <span class="text-xs font-extrabold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Group by</span>
                <span class="inline-flex gap-0.5 rounded-full bg-surface-sunken p-0.5 dark:bg-surface-sunken-dark" role="group" aria-label="Group by">
                    <button type="button" x-on:click="setGroupBy('mission')" x-bind:aria-pressed="groupBy === 'mission'" x-bind:class="groupBy === 'mission' ? 'bg-ink text-surface dark:bg-ink-dark dark:text-surface-dark' : 'text-ink-soft dark:text-ink-soft-dark'" class="cursor-pointer rounded-full px-3.5 py-1.5 text-sm font-extrabold">Mission</button>
                    <button type="button" x-on:click="setGroupBy('type')" x-bind:aria-pressed="groupBy === 'type'" x-bind:class="groupBy === 'type' ? 'bg-ink text-surface dark:bg-ink-dark dark:text-surface-dark' : 'text-ink-soft dark:text-ink-soft-dark'" class="cursor-pointer rounded-full px-3.5 py-1.5 text-sm font-extrabold">Type</button>
                </span>
            </div>

            <p x-show="! all.some(meta => shows('mistakes', meta))" x-cloak class="py-6 text-center text-sm font-bold text-ink-faint dark:text-ink-faint-dark">Nothing here.</p>

            <div x-show="groupBy === 'type'" x-cloak class="space-y-3">
                @foreach ($this->entryGroups() as $group)
                    @php
                        $key = 'mt-'.$loop->index;
                        $first = $loop->first ? 'true' : 'false';
                        $metas = $group['entries']->map($metaOf)->values();
                    @endphp
                    <section aria-label="{{ $group['title'] }}" class="overflow-hidden rounded-3xl bg-surface dark:bg-surface-dark" x-data="{ metas: {{ \Illuminate\Support\Js::from($metas) }} }" x-show="metas.some(meta => shows('mistakes', meta))">
                        <button type="button" x-on:click="open['{{ $key }}'] = ! isOpen('mistakes', '{{ $key }}', {{ $first }})" x-bind:aria-expanded="isOpen('mistakes', '{{ $key }}', {{ $first }})" class="flex w-full cursor-pointer items-center gap-2.5 p-4 text-left">
                            @if ($group['title'] === 'Sounds more natural')
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300">@svg('heroicon-o-sparkles', 'h-[18px] w-[18px]')</span>
                            @endif
                            <span class="min-w-0 flex-1 truncate text-base font-extrabold text-ink dark:text-ink-dark">{{ $group['title'] }}</span>
                            <span class="text-sm font-bold text-ink-soft dark:text-ink-soft-dark" x-text="filtering('mistakes') ? metas.filter(meta => shows('mistakes', meta)).length + ' found' : '{{ $group['entries']->count() }}'">{{ $group['entries']->count() }}</span>
                            <span class="shrink-0 text-ink-faint transition-transform dark:text-ink-faint-dark" x-bind:class="isOpen('mistakes', '{{ $key }}', {{ $first }}) ? 'rotate-180' : ''">@svg('heroicon-m-chevron-down', 'h-5 w-5')</span>
                        </button>
                        <div x-show="isOpen('mistakes', '{{ $key }}', {{ $first }})" @unless ($loop->first) x-cloak @endunless class="divide-y divide-line border-t border-line px-4 dark:divide-line-dark dark:border-line-dark">
                            @foreach ($group['entries'] as $entry)
                                @include('components.notebook.partials.entry-row', ['entry' => $entry, 'show' => "shows('mistakes', metas[".$loop->index."])", 'keyPrefix' => 'notebook-row-type', 'tag' => trim(($entry->mission?->code ?? '').($entry->mission ? ' · ' : '').$sourceOf($entry)), 'removeButton' => $removeButton])
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>

            <div x-show="groupBy === 'mission'" class="space-y-3">
                @foreach ($this->entryMissionGroups() as $group)
                    @php
                        $key = 'mm-'.$loop->index;
                        $metas = $group['entries']->map($metaOf)->values();
                        $groupCounts = $group['entries']->countBy(fn (NotebookEntry $entry) => $entry->studyStatus());
                        $groupTotal = $group['entries']->count();
                    @endphp
                    <x-notebook.mission-card tab="mistakes" :group-key="$key" :first="$loop->first" :code="$group['code']" :title="$group['title']" :cover="$group['cover']" :count-text="$groupTotal.' '.Str::plural('line', $groupTotal)" :due="$group['due']" :counts="['known' => $groupCounts->get('known', 0), 'learning' => $groupCounts->get('learning', 0), 'new' => $groupCounts->get('new', 0)]" :metas="$metas">
                        @foreach ($group['entries'] as $entry)
                            @include('components.notebook.partials.entry-row', ['entry' => $entry, 'show' => "shows('mistakes', metas[".$loop->index."])", 'keyPrefix' => 'notebook-row-mission', 'tag' => $entry->isPhraseSwap() ? 'Sounds more natural' : $entry->heading(), 'removeButton' => $removeButton])
                        @endforeach
                    </x-notebook.mission-card>
                @endforeach
            </div>
        @endif

        @if ($learnedEntries->isNotEmpty())
            <button type="button" x-on:click="learned = ! learned" class="w-full cursor-pointer py-2 text-center text-sm font-extrabold text-ink-faint dark:text-ink-faint-dark">Learned and older ({{ $learnedEntries->count() }}) ›</button>
            <div x-show="learned" x-cloak class="space-y-3 rounded-3xl bg-surface p-4 dark:bg-surface-dark">
                @foreach ($learnedEntries as $entry)
                    <div wire:key="notebook-learned-{{ $entry->id }}" x-show="learnedAll || {{ $loop->index }} < 10" @if ($loop->index >= 10) x-cloak @endif class="flex items-center justify-between gap-2">
                        <p class="min-w-0 text-sm text-ink-faint dark:text-ink-faint-dark">
                            @if ($entry->original)
                                <span @class(['block text-xs', 'line-through' => $entry->isMistake()])>{{ $entry->original }}</span>
                            @endif
                            {{ $entry->better }}
                        </p>
                        <button type="button" wire:click="bringBack({{ $entry->id }})" class="shrink-0 cursor-pointer text-xs font-bold text-accent-ink dark:text-accent-ink-dark">Bring back</button>
                    </div>
                @endforeach
                @if ($learnedEntries->count() > 10)
                    <button type="button" x-show="! learnedAll" x-on:click="learnedAll = true" class="w-full cursor-pointer py-1 text-center text-sm font-extrabold text-accent-ink dark:text-accent-ink-dark">Show all {{ $learnedEntries->count() }} ›</button>
                @endif
            </div>
        @endif
    </div>

    {{-- Words: where the learner learns them. How many are ready, today's few, one simple sentence under each word, then every word by mission. --}}
    <div x-show="notebookTab === 'words'" x-cloak class="space-y-3">
        @if ($this->words->isEmpty())
            <p class="py-6 text-center text-sm text-ink-faint dark:text-ink-faint-dark">Words from your lessons and the ones you tap in a text show up here.</p>
        @else
            @php
                $wordMeta = fn (VocabularyWord $word) => [
                    's' => $word->studyStatus(),
                    'f' => $word->source_mission_run_id === null ? ['mine'] : [],
                    't' => mb_strtolower($word->word.' '.$word->meaning.' '.($word->meaning_fa ?: ($this->courseMeaningsFa[mb_strtolower($word->word)] ?? ''))),
                ];
                $wordCounts = $this->words->countBy(fn (VocabularyWord $word) => $word->studyStatus());
                $allWordMetas = $this->words->map($wordMeta)->values();
                $totalWords = $this->words->count();
                $dueCount = $this->words->filter(fn (VocabularyWord $word) => $word->isDue())->count();
            @endphp

            <div x-data="{ all: {{ \Illuminate\Support\Js::from($allWordMetas) }}, searching: false }" class="space-y-3">
                <div x-show="searching || search !== ''" x-cloak class="relative">
                    <input type="search" x-model="search" x-ref="wordSearch" placeholder="Search your words" aria-label="Search your words" class="w-full rounded-2xl border-0 bg-surface px-4 py-2.5 text-sm text-ink placeholder:text-ink-faint focus:ring-2 focus:ring-accent dark:bg-surface-dark dark:text-ink-dark dark:placeholder:text-ink-faint-dark">
                </div>
                <x-notebook-filter-bar
                    tab="words"
                    :segments="[]"
                    :chips="array_values(array_filter([
                        ['all', 'All', $this->words->count()],
                        $this->words->contains(fn (VocabularyWord $word) => $word->source_mission_run_id === null) ? ['mine', 'Mine', $this->words->whereNull('source_mission_run_id')->count()] : null,
                        ['new', 'New', $wordCounts->get('new', 0)],
                        ['learning', 'Learning', $wordCounts->get('learning', 0)],
                        ['known', 'Known', $wordCounts->get('known', 0)],
                    ]))"
                >
                    <x-slot:lead>
                        <button type="button" x-on:click="searching = ! searching; if (searching) { setTimeout(() => $refs.wordSearch.focus(), 60) } else { search = '' }" x-bind:aria-pressed="searching || search !== ''" aria-label="Search your words" class="inline-flex h-8 w-8 shrink-0 cursor-pointer items-center justify-center rounded-full bg-surface text-ink-soft dark:bg-surface-dark dark:text-ink-soft-dark">@svg('heroicon-o-magnifying-glass', 'h-4 w-4')</button>
                    </x-slot:lead>
                </x-notebook-filter-bar>

                <p x-show="! all.some(meta => shows('words', meta))" x-cloak class="py-6 text-center text-sm font-bold text-ink-faint dark:text-ink-faint-dark">Nothing here.</p>

                @foreach ($this->wordGroups as $group)
                    @php
                        $key = 'words-'.$loop->index;
                        $metas = $group['words']->map($wordMeta)->values();
                        $groupCounts = $group['words']->countBy(fn (VocabularyWord $word) => $word->studyStatus());
                        $groupTotal = $group['words']->count();
                    @endphp
                    <x-notebook.mission-card tab="words" :group-key="$key" :first="$loop->first" :code="$group['code']" :title="$group['title']" :cover="$group['cover']" :count-text="$groupTotal.' '.Str::plural('word', $groupTotal).($group['mine'] > 0 ? ' · '.$group['mine'].' yours' : '')" :due="$group['due']" :counts="['known' => $groupCounts->get('known', 0), 'learning' => $groupCounts->get('learning', 0), 'new' => $groupCounts->get('new', 0)]" :metas="$metas">
                        @foreach ($group['words'] as $word)
                            @if ($loop->index < 4)
                                @include('components.notebook.partials.word-row', ['word' => $word, 'courseFa' => $this->courseMeaningsFa, 'statusDot' => $statusDot, 'commonness' => $this->wordCommonness, 'removeButton' => $removeButton, 'show' => "shows('words', metas[".$loop->index."])", 'keyPrefix' => 'word'])
                            @else
                                {{-- Past the first four a row is only built when "Show all" or a search/filter needs it, so a long list is not thousands of live elements. --}}
                                <template x-if="expanded['{{ $key }}'] || filtering('words')">
                                    @include('components.notebook.partials.word-row', ['word' => $word, 'courseFa' => $this->courseMeaningsFa, 'statusDot' => $statusDot, 'commonness' => $this->wordCommonness, 'removeButton' => $removeButton, 'show' => "shows('words', metas[".$loop->index."])", 'keyPrefix' => 'word'])
                                </template>
                            @endif
                        @endforeach
                        @if ($groupTotal > 4)
                            <button type="button" x-show="! filtering('words')" x-on:click="expanded['{{ $key }}'] = ! expanded['{{ $key }}']" x-text="expanded['{{ $key }}'] ? 'Show less' : 'Show all {{ $groupTotal }} ›'" class="w-full cursor-pointer py-3 text-center text-sm font-extrabold text-accent-ink dark:text-accent-ink-dark">Show all {{ $groupTotal }} ›</button>
                        @endif
                    </x-notebook.mission-card>
                @endforeach
            </div>
        @endif
    </div>

    {{-- One sheet; what it shows follows the tapped row. --}}
    <x-bottom-sheet show="sheet" label="Details">
        @foreach ($this->visibleEntries->whereNull('learned_at') as $entry)
            <div x-show="sheet === 'n{{ $entry->id }}'" wire:key="notebook-sheet-{{ $entry->id }}" class="flex flex-col gap-2">
                <p class="flex items-center gap-1.5 text-xs font-extrabold tracking-wide uppercase {{ $entry->isPhraseSwap() ? 'text-sky-800 dark:text-sky-300' : 'text-ink-faint dark:text-ink-faint-dark' }}">
                    @if ($entry->isPhraseSwap())
                        @svg('heroicon-o-sparkles', 'h-3.5 w-3.5')
                    @endif
                    <span>{{ $entry->heading() }}@if ($entry->mission) · {{ $entry->mission->code }}@endif · {{ \App\Services\FeedbackNotebook::SOURCES[$entry->source] ?? $entry->source }}@if ($entry->isMistake() && $entry->times_seen > 1) · {{ $entry->times_seen }}×@endif</span>
                </p>
                @if ($entry->original)
                    <p class="text-sm {{ $entry->isMistake() ? 'text-danger-ink line-through decoration-danger' : 'text-ink-soft dark:text-ink-soft-dark' }}">{{ $entry->original }}</p>
                @endif
                <p class="flex items-center gap-2 text-base font-extrabold text-success dark:text-success-dark">{{ $entry->better }} <x-speak-button :text="$entry->better" /></p>
                @if ($entry->note)
                    <p class="font-fa text-sm leading-relaxed text-ink-soft dark:text-ink-soft-dark" dir="rtl">{{ $entry->note }}</p>
                @endif
                <p class="text-xs font-bold text-ink-faint dark:text-ink-faint-dark">{{ $entry->isMistake() ? '✓ In Daily Review until you know it' : 'Nothing wrong here — just a more natural way to say it.' }}</p>
                <div class="mt-2 grid grid-cols-2 gap-2">
                    <button type="button" wire:click="markLearned({{ $entry->id }})" x-on:click="sheet = false" class="cursor-pointer rounded-2xl bg-surface-sunken py-3 text-sm font-extrabold text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark">{{ $entry->isMistake() ? '✓ Learned' : '✓ Got it' }}</button>
                    @if ($entry->original)
                        <button type="button" wire:click="practiceOne({{ $entry->id }})" class="cursor-pointer rounded-2xl bg-success py-3 text-sm font-extrabold text-white dark:bg-success-dark">✎ Practice</button>
                    @endif
                </div>
                <button type="button" wire:click="removeEntry({{ $entry->id }})" x-on:click="sheet = false" class="{{ $removeSheetButton }}">@svg('heroicon-o-trash', 'h-4 w-4') Remove from my mistakes</button>
            </div>
        @endforeach
        @include('components.notebook.partials.word-sheet', ['sheets' => $this->wordSheets, 'removeSheetButton' => $removeSheetButton])
    </x-bottom-sheet>

    {{-- A removed word's bar is shown by the page at once (removeWordNow); a removed line's comes from the server. --}}
    <template x-if="undo">
        <div x-transition.opacity role="status" class="fixed inset-x-4 bottom-[calc(5.5rem+env(safe-area-inset-bottom))] z-[60] mx-auto flex max-w-md items-center gap-3 rounded-2xl bg-hero px-4 py-3 text-sm font-bold text-white shadow-lg">
            <span class="min-w-0 flex-1 truncate">“<span x-text="undo.label"></span>” removed</span>
            <button type="button" x-on:click="undoWord()" class="shrink-0 cursor-pointer px-1 font-extrabold text-accent-ink-dark">Undo</button>
        </div>
    </template>

    @if ($removed && $removed['type'] !== 'word')
        <div
            wire:key="removed-{{ $removed['type'] }}-{{ $removed['attributes']['id'] }}"
            x-data="{ show: true }"
            x-init="setTimeout(() => show = false, 5000)"
            x-show="show"
            x-transition.opacity
            role="status"
            class="fixed inset-x-4 bottom-[calc(5.5rem+env(safe-area-inset-bottom))] z-[60] mx-auto flex max-w-md items-center gap-3 rounded-2xl bg-hero px-4 py-3 text-sm font-bold text-white shadow-lg"
        >
            <span class="min-w-0 flex-1 truncate">“{{ $removed['label'] }}” removed</span>
            <button type="button" wire:click="undoRemove" class="shrink-0 cursor-pointer px-1 font-extrabold text-accent-ink-dark">Undo</button>
        </div>
    @endif
</div>
@endif
