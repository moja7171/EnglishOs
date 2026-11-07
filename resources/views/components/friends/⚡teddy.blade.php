<?php

use App\Models\AiPractice;
use App\Models\PairGame;
use App\Services\AiPractices;
use App\Services\PairPractice;
use App\Services\TeddyLevel;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public ?string $notice = null;

    /**
     * Practices the learner can pick up, by kind.
     *
     * @return list<string>
     */
    #[Computed]
    public function openKinds(): array
    {
        return AiPractice::where('learner_id', auth()->id())->open()->pluck('kind')->unique()->values()->all();
    }

    #[Computed]
    public function timesTogether(): int
    {
        return AiPractice::where('learner_id', auth()->id())->whereNotNull('finished_at')->where('answered', '>=', AiPractice::MIN_COUNTED_ANSWERS)->count();
    }

    /**
     * How many of Teddy's slips the learner caught in the last week, if he
     * slipped at all.
     *
     * @return array{caught: int, total: int}|null
     */
    #[Computed]
    public function weekSlips(): ?array
    {
        $counts = AiPractice::where('learner_id', auth()->id())
            ->where('created_at', '>=', now()->subWeek())
            ->get()
            ->map(fn (AiPractice $practice) => $practice->slipCount());

        $total = $counts->sum('total');

        return $total === 0 ? null : ['caught' => $counts->sum('caught'), 'total' => $total];
    }

    /**
     * How Teddy plays the next round, if not the usual way.
     */
    #[Computed]
    public function levelToday(): string
    {
        return app(TeddyLevel::class)->levelFor(auth()->user(), AiPractice::KIND_PRACTICE);
    }

    /**
     * Every day the learner finished, to pick from.
     *
     * @return list<array{code: string, days: list<array{key: string, day: int, shared: bool}>}>
     */
    #[Computed]
    public function myDays(): array
    {
        return app(PairPractice::class)->dayChoices(auth()->user(), auth()->user());
    }

    /**
     * A round or one game, from the latest days or the days picked; the
     * open one is picked up.
     *
     * @param  list<string>|null  $days
     */
    public function start(string $kind, ?array $days = null): void
    {
        abort_unless($kind === AiPractice::KIND_PRACTICE || in_array($kind, AiPractice::GAMES, true), 404);

        $days = $days === null ? null : array_values(array_filter($days, 'is_string'));
        $practice = app(AiPractices::class)->start(auth()->user(), $kind, $days ?: null);

        if ($practice) {
            $this->redirectRoute('ai-practice.show', $practice, navigate: true);

            return;
        }

        $this->notice = 'Finish a day first.';
    }
};
?>

@php $openKinds = $this->openKinds; @endphp

<div
    x-data="{
        sheet: false,
        mode: 'auto',
        days: [],
        toggle(key) { this.days = this.days.includes(key) ? this.days.filter((day) => day !== key) : [...this.days, key]; },
        toggleAll(keys) { this.days = keys.every((key) => this.days.includes(key)) ? this.days.filter((day) => ! keys.includes(day)) : [...new Set([...this.days, ...keys])]; },
        chosen() { return this.mode === 'auto' || this.days.length === 0 ? null : this.days; },
    }"
    class="mx-auto max-w-2xl space-y-4 px-4 pt-3 pb-6 sm:px-6"
>
    <header class="flex items-center gap-1">
        <a href="{{ route('friends.index') }}" wire:navigate aria-label="Back to Friends" class="-ms-2 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-ink-soft transition-colors hover:bg-surface-sunken dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">@svg('heroicon-o-chevron-left', 'h-6 w-6')</a>
        <h1 class="font-display text-2xl font-extrabold text-ink dark:text-ink-dark">Teddy</h1>
    </header>

    <div class="card flex flex-col items-center gap-1 p-5 text-center">
        <x-teddy-avatar class="mb-1 h-20 w-20" />
        <p class="font-semibold text-ink dark:text-ink-dark">Your AI classmate</p>
        <p class="text-sm text-ink-soft dark:text-ink-soft-dark">Loves travel and food. Always free to practice.</p>
        @if ($this->timesTogether > 0)
            <p class="mt-1 rounded-full bg-success-soft px-2.5 py-0.5 text-xs font-bold text-success dark:bg-success-soft-dark dark:text-success-dark">{{ $this->timesTogether }}× together</p>
        @endif
        @php
            $weekSlips = $this->weekSlips;
            $levelLine = match ($this->levelToday) {
                TeddyLevel::EASY => 'Teddy plays gently today',
                TeddyLevel::HARD => 'Teddy plays sharp today',
                default => null,
            };
        @endphp
        @if ($weekSlips || $levelLine)
            <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">{{ collect([$weekSlips ? "This week you caught {$weekSlips['caught']} of {$weekSlips['total']} slips" : null, $levelLine])->filter()->join(' · ') }}</p>
        @endif
    </div>

    @if ($notice)
        <p class="rounded-xl bg-warning-soft px-3 py-2 text-sm font-semibold text-warning-ink">{{ $notice }}</p>
    @endif

    @if ($this->myDays !== [])
        <button type="button" x-on:click="sheet = true" class="flex w-full cursor-pointer items-center justify-between gap-2 rounded-2xl border border-line bg-surface px-4 py-2.5 text-start text-sm font-semibold text-ink dark:border-line-dark dark:bg-surface-dark dark:text-ink-dark">
            <span class="flex items-center gap-2">@svg('heroicon-o-calendar-days', 'h-5 w-5 text-ink-faint dark:text-ink-faint-dark') <span x-text="chosen() === null ? 'Your latest days' : days.length + (days.length === 1 ? ' day chosen' : ' days chosen')"></span></span>
            <span class="text-ink-faint dark:text-ink-faint-dark" aria-hidden="true">›</span>
        </button>

        <x-bottom-sheet show="sheet" label="Choose days">
            <x-slot:title>Which days?</x-slot:title>
            @include('components.pair-practice.partials.day-picker', ['autoLine' => 'Your latest days', 'missions' => $this->myDays, 'keyPrefix' => 'teddy'])
            <button type="button" x-bind:disabled="mode === 'choose' && days.length === 0" x-on:click="sheet = false" class="block w-full cursor-pointer rounded-full bg-accent px-4 py-3 text-sm font-semibold text-white disabled:opacity-40 dark:bg-accent-dark">Done</button>
        </x-bottom-sheet>
    @endif

    <button type="button" x-on:click="$wire.start('{{ AiPractice::KIND_PRACTICE }}', chosen())" wire:loading.attr="disabled" wire:target="start" class="block w-full cursor-pointer rounded-2xl bg-accent px-4 py-3 text-base font-extrabold text-white shadow-[0_4px_0_color-mix(in_srgb,var(--color-accent)_60%,black)] transition-opacity hover:opacity-90 disabled:opacity-50 dark:bg-accent-dark">@if (in_array(AiPractice::KIND_PRACTICE, $openKinds, true))<span x-text="chosen() === null ? 'Continue practice' : 'Practice together'">Continue practice</span>@else Practice together @endif</button>

    <div class="grid grid-cols-2 gap-2.5">
        @foreach (collect(AiPractice::GAMES)->sortBy(fn (string $game) => $game === PairGame::GAME_ASK ? 0 : 1)->values() as $game)
            @php $info = PairGame::info($game); @endphp
            <button type="button" wire:key="teddy-game-{{ $game }}" x-on:click="$wire.start('{{ $game }}', chosen())" wire:loading.attr="disabled" wire:target="start" class="cursor-pointer overflow-hidden rounded-2xl bg-surface text-start shadow-sm disabled:opacity-50 dark:bg-surface-dark">
                <img src="{{ $info['image'] }}" alt="" loading="lazy" class="h-20 w-full object-cover">
                <span class="block px-3 pt-2 text-sm font-extrabold text-ink dark:text-ink-dark">{{ $info['name'] }}</span>
                <span class="block truncate px-3 pb-2.5 text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">{{ in_array($game, $openKinds, true) ? 'Continue' : $info['line'] }}</span>
            </button>
        @endforeach
    </div>

    <p class="text-center text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">Your voice is recorded.</p>
</div>
