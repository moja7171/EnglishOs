<?php

use App\Models\GrammarPoint;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * One finished mission looked back on, under its four days on the
 * mission's own page (the runner's overview): what it taught (its
 * grammar), the words the learner picked, and their own voice from it. A
 * mirror onto data the run already holds — nothing is asked or recorded
 * here.
 */
new class extends Component
{
    public Mission $mission;

    public function mount(): void
    {
        abort_unless($this->run !== null, 404);
    }

    /** The learner's cleared run of this mission — the same bar the mission-to-mission gate uses. */
    #[Computed]
    public function run(): ?MissionRun
    {
        return auth()->user()->missionRuns()
            ->where('mission_id', $this->mission->id)
            ->whereIn('status', [MissionRun::STATUS_COMPLETE, MissionRun::STATUS_NEEDS_REVIEW])
            ->first();
    }

    #[Computed]
    public function grammarTitle(): ?string
    {
        return Mission::roadmapCatalog()[$this->mission->code]['grammar'] ?? null;
    }

    /** @return Collection<int, GrammarPoint> */
    #[Computed]
    public function grammarPoints(): Collection
    {
        return auth()->user()->grammarPoints()
            ->where('mission_code', $this->mission->code)
            ->orderBy('id')
            ->get();
    }

    /** @return list<array{phrase: string, meaningFa: ?string}> */
    #[Computed]
    public function words(): array
    {
        return collect($this->run->selectedVocabularyWords())
            ->map(fn (string $phrase) => ['phrase' => $phrase, 'meaningFa' => $this->mission->persianMeaningFor($phrase)])
            ->all();
    }

    /** @return list<array{label: string, url: string}> */
    #[Computed]
    public function recordings(): array
    {
        return $this->run->recordingsByStep();
    }
};
?>

{{-- A finished mission looked back on, under its days on the mission's
     own page (see the runner): its grammar, the learner's own words and
     recordings, each folded. --}}
<div class="space-y-2.5">
    @if ($this->grammarTitle)
        <details class="card group p-4">
            <summary class="flex cursor-pointer list-none items-center gap-2">
                <span class="min-w-0 flex-1 text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Grammar</span>
                @svg('heroicon-o-chevron-down', 'h-4 w-4 shrink-0 text-ink-faint transition-transform group-open:rotate-180 dark:text-ink-faint-dark')
            </summary>
            <div class="mt-1 flex flex-wrap items-center gap-2">
                <p class="text-sm font-semibold text-ink dark:text-ink-dark">{{ $this->grammarTitle }}</p>
                @if ($this->grammarPoints->isNotEmpty())
                    @if ($this->grammarPoints->contains(fn ($point) => $point->repetitions >= User::MASTERED_GRAMMAR_REPETITIONS))
                        <span class="inline-flex items-center gap-1 rounded-full bg-success-soft px-2 py-0.5 text-[11px] font-semibold text-success dark:bg-success-soft-dark dark:text-success-dark">@svg('heroicon-s-check', 'h-3 w-3') Learned</span>
                    @else
                        <span class="inline-flex items-center gap-1 rounded-full bg-surface-sunken px-2 py-0.5 text-[11px] font-semibold text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark">@svg('heroicon-o-arrow-path', 'h-3 w-3') In review</span>
                    @endif
                @endif
            </div>
            @foreach ($this->grammarPoints->pluck('example_sentence')->filter()->unique() as $example)
                <x-speak-word :word="$example" block top class="mt-2 text-sm text-ink-soft italic dark:text-ink-soft-dark" />
            @endforeach
        </details>
    @endif

    @if (count($this->words))
        <details class="card group p-4">
            <summary class="flex cursor-pointer list-none items-center gap-2">
                <span class="min-w-0 flex-1 text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Your words · {{ count($this->words) }}</span>
                @svg('heroicon-o-chevron-down', 'h-4 w-4 shrink-0 text-ink-faint transition-transform group-open:rotate-180 dark:text-ink-faint-dark')
            </summary>
            <ul class="mt-2 flex flex-wrap gap-1.5">
                @foreach ($this->words as $word)
                    <li class="rounded-xl bg-accent-soft px-2.5 py-1 dark:bg-accent-soft-dark">
                        <x-speak-word :word="$word['phrase']" size="sm" class="text-sm font-semibold text-accent-ink dark:text-accent-ink-dark" />
                        @if ($word['meaningFa'])
                            <span class="font-fa block text-xs text-accent-ink/75 dark:text-accent-ink-dark/75" dir="rtl">{{ $word['meaningFa'] }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </details>
    @endif

    @if (count($this->recordings))
        <details class="card group p-4">
            <summary class="flex cursor-pointer list-none items-center gap-2">
                <span class="min-w-0 flex-1 text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Your voice · {{ count($this->recordings) }}</span>
                @svg('heroicon-o-chevron-down', 'h-4 w-4 shrink-0 text-ink-faint transition-transform group-open:rotate-180 dark:text-ink-faint-dark')
            </summary>
            <ul class="mt-2 divide-y divide-line dark:divide-line-dark">
                @foreach ($this->recordings as $recording)
                    <li class="space-y-1.5 py-2.5 first:pt-0 last:pb-0">
                        <p class="text-sm font-semibold text-ink dark:text-ink-dark">{{ $recording['label'] }}</p>
                        <x-audio-player-compact :url="$recording['url']" />
                    </li>
                @endforeach
            </ul>
        </details>
    @endif
</div>
