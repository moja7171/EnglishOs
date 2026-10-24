<?php

use App\Models\GrammarPoint;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * One finished mission looked back on, opened from its photo on the
 * Missions page: what it taught (its grammar), the words the learner picked,
 * and their own voice from it. A mirror onto data the run already holds
 * — nothing is asked or recorded here. Revisiting the steps themselves
 * stays on the mission runner ("Open mission").
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

<div class="mx-auto max-w-2xl space-y-4 p-4 sm:p-6">
    {{-- The mission's photo banner, the same as its Missions card, with
         when it was cleared. --}}
    <x-mission-banner
        :mission-code="$mission->code"
        :title="$mission->title"
        :back-href="route('missions.index')"
        back-label="Missions"
        :done-label="'Done'.($this->run->completed_at ? ' '.$this->run->completed_at->format('M j') : '')"
    >
        <p class="mt-0.5 text-sm text-white/80">{{ $mission->code }}{{ $mission->outcome ? ' · '.$mission->outcome : '' }}</p>
    </x-mission-banner>

    <x-practice-again :mission-code="$mission->code" />

    @if ($this->grammarTitle)
        <section class="card p-4">
            <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Grammar</p>
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
        </section>
    @endif

    @if (count($this->words))
        <section class="card p-4">
            <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Your words · {{ count($this->words) }}</p>
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
        </section>
    @endif

    @if (count($this->recordings))
        <section class="card p-4">
            <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Your voice</p>
            <ul class="mt-2 divide-y divide-line dark:divide-line-dark">
                @foreach ($this->recordings as $recording)
                    <li class="space-y-1.5 py-2.5 first:pt-0 last:pb-0">
                        <p class="text-sm font-semibold text-ink dark:text-ink-dark">{{ $recording['label'] }}</p>
                        <x-audio-player-compact :url="$recording['url']" />
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <a
        href="{{ route('missions.show', [$mission, 'overview']) }}"
        wire:navigate
        class="inline-flex cursor-pointer items-center gap-1 rounded-full border border-line px-4 py-2 text-sm font-semibold text-ink-soft transition-colors hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
    >Open mission @svg('heroicon-o-chevron-right', 'h-3.5 w-3.5')</a>
</div>
