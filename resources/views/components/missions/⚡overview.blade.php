<?php

use App\Models\Mission;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    #[Computed]
    public function dueWordsCount(): int
    {
        return auth()->user()->vocabularyWords()->where('next_review_at', '<=', now())->count();
    }

    #[Computed]
    public function duePromptsCount(): int
    {
        return auth()->user()->speakingPrompts()->where('next_review_at', '<=', now())->count();
    }

    #[Computed]
    public function justBenefitedFromGrace(): bool
    {
        return auth()->user()->justBenefitedFromGrace();
    }

    #[Computed]
    public function justLostStreak(): bool
    {
        return auth()->user()->justLostStreak();
    }

    /**
     * The full curriculum is 24 missions (EOS-009 §15 roadmap, v3.0); only
     * the ones actually seeded so far are playable. Every slot 1-24 is
     * shown so the whole path is visible from day one — seeded missions as
     * real clickable cards, the rest as locked placeholders — rather than
     * the list just trailing off after whatever happens to exist yet.
     *
     * @return list<Mission|string> a Mission where seeded, otherwise its
     *                              not-yet-seeded code (e.g. "M07")
     */
    #[Computed]
    public function missionSlots(): array
    {
        $seeded = Mission::orderBy('code')->get()->keyBy('code');

        return collect(range(1, 24))
            ->map(fn ($n) => sprintf('M%02d', $n))
            ->map(fn ($code) => $seeded->get($code) ?? $code)
            ->all();
    }
};
?>

<div class="mx-auto max-w-2xl space-y-6 p-6">
    <header class="border-b border-line pb-4 dark:border-line-dark">
        <h1 class="font-display text-2xl font-extrabold text-ink dark:text-ink-dark">Missions</h1>
    </header>

    @if ($this->justBenefitedFromGrace)
        <div class="flex items-center gap-3 rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
            <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">
                @svg('heroicon-s-fire', 'h-4 w-4')
            </span>
            <span class="flex-1">
                <span class="block text-sm font-semibold text-ink dark:text-ink-dark">You missed a day, but your streak is safe!</span>
                <span class="block text-xs text-ink-faint dark:text-ink-faint-dark">One skipped day never breaks it — keep going.</span>
            </span>
        </div>
    @elseif ($this->justLostStreak)
        <div class="flex items-center gap-3 rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
            <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">
                @svg('heroicon-o-trophy', 'h-4 w-4')
            </span>
            <span class="flex-1">
                <span class="block text-sm font-semibold text-ink dark:text-ink-dark">Fresh start — your best run was {{ auth()->user()->longestStreak() }} {{ Str::plural('day', auth()->user()->longestStreak()) }}.</span>
                <span class="block text-xs text-ink-faint dark:text-ink-faint-dark">Let's see if today can be the start of a new one.</span>
            </span>
        </div>
    @endif

    @if ($this->dueWordsCount)
        <a
            href="{{ route('vocabulary.index') }}"
            wire:navigate
            class="flex items-center gap-3 rounded-2xl border border-accent-soft bg-accent-soft/40 p-4 transition-colors hover:bg-accent-soft dark:border-accent-soft-dark dark:bg-accent-soft-dark/40 dark:hover:bg-accent-soft-dark"
        >
            <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">
                @svg('heroicon-o-book-open', 'h-4 w-4')
            </span>
            <span class="flex-1">
                <span class="block text-sm font-semibold text-ink dark:text-ink-dark">{{ $this->dueWordsCount }} {{ Str::plural('word', $this->dueWordsCount) }} ready for review</span>
                <span class="block text-xs text-ink-faint dark:text-ink-faint-dark">A couple of minutes keeps them fresh.</span>
            </span>
            @svg('heroicon-o-chevron-right', 'h-4 w-4 text-ink-faint dark:text-ink-faint-dark shrink-0')
        </a>
    @endif

    @if ($this->duePromptsCount)
        <a
            href="{{ route('speaking.index') }}"
            wire:navigate
            class="flex items-center gap-3 rounded-2xl border border-accent-soft bg-accent-soft/40 p-4 transition-colors hover:bg-accent-soft dark:border-accent-soft-dark dark:bg-accent-soft-dark/40 dark:hover:bg-accent-soft-dark"
        >
            <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">
                @svg('heroicon-o-microphone', 'h-4 w-4')
            </span>
            <span class="flex-1">
                <span class="block text-sm font-semibold text-ink dark:text-ink-dark">{{ $this->duePromptsCount }} {{ Str::plural('speaking question', $this->duePromptsCount) }} ready for review</span>
                <span class="block text-xs text-ink-faint dark:text-ink-faint-dark">A couple of minutes keeps your speaking sharp too.</span>
            </span>
            @svg('heroicon-o-chevron-right', 'h-4 w-4 text-ink-faint dark:text-ink-faint-dark shrink-0')
        </a>
    @endif

    @foreach ($this->missionSlots as $slot)
        @if ($slot instanceof Mission)
            <a href="{{ route('missions.show', $slot) }}"
               data-mood="{{ $slot->moodKey() }}"
               class="block rounded-2xl border border-line bg-surface p-4 transition-colors hover:border-accent dark:border-line-dark dark:bg-surface-dark dark:hover:border-accent-dark">
                <p class="text-xs font-semibold tracking-wide text-accent-ink uppercase dark:text-accent-ink-dark">{{ $slot->code }} · {{ $slot->module }}</p>
                <h2 class="font-display text-lg font-bold text-ink dark:text-ink-dark">{{ $slot->title }}</h2>
                <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">{{ $slot->outcome }}</p>
            </a>
        @else
            <div class="flex items-center justify-between rounded-2xl border border-line bg-surface-sunken p-4 opacity-60 dark:border-line-dark dark:bg-surface-sunken-dark">
                <div>
                    <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">{{ $slot }}</p>
                    <p class="font-display text-lg font-bold text-ink-faint dark:text-ink-faint-dark">Coming soon</p>
                </div>
                <span class="shrink-0 text-ink-faint dark:text-ink-faint-dark">@svg('heroicon-o-lock-closed', 'h-4 w-4')</span>
            </div>
        @endif
    @endforeach
</div>
