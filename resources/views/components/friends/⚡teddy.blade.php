<?php

use App\Models\AiPractice;
use App\Models\PairGame;
use App\Services\AiPractices;
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
     * A round from the latest days, or one game; the open one is picked up.
     */
    public function start(string $kind): void
    {
        abort_unless($kind === AiPractice::KIND_PRACTICE || in_array($kind, AiPractice::GAMES, true), 404);

        $practice = app(AiPractices::class)->start(auth()->user(), $kind);

        if ($practice) {
            $this->redirectRoute('ai-practice.show', $practice, navigate: true);

            return;
        }

        $this->notice = 'Finish a day first.';
    }
};
?>

@php $openKinds = $this->openKinds; @endphp

<div class="mx-auto max-w-2xl space-y-4 px-4 pt-3 pb-6 sm:px-6">
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
    </div>

    @if ($notice)
        <p class="rounded-xl bg-warning-soft px-3 py-2 text-sm font-semibold text-warning-ink">{{ $notice }}</p>
    @endif

    <button type="button" wire:click="start('{{ AiPractice::KIND_PRACTICE }}')" wire:loading.attr="disabled" wire:target="start" class="block w-full cursor-pointer rounded-2xl bg-accent px-4 py-3 text-base font-extrabold text-white shadow-[0_4px_0_color-mix(in_srgb,var(--color-accent)_60%,black)] transition-opacity hover:opacity-90 disabled:opacity-50 dark:bg-accent-dark">{{ in_array(AiPractice::KIND_PRACTICE, $openKinds, true) ? 'Continue practice' : 'Practice together' }}</button>

    <div class="grid grid-cols-2 gap-2.5">
        @foreach (AiPractice::GAMES as $game)
            @php $info = PairGame::info($game); @endphp
            <button type="button" wire:key="teddy-game-{{ $game }}" wire:click="start('{{ $game }}')" wire:loading.attr="disabled" wire:target="start" class="cursor-pointer overflow-hidden rounded-2xl bg-surface text-start shadow-sm disabled:opacity-50 dark:bg-surface-dark">
                <img src="{{ $info['image'] }}" alt="" loading="lazy" class="h-20 w-full object-cover">
                <span class="block px-3 pt-2 text-sm font-extrabold text-ink dark:text-ink-dark">{{ $info['name'] }}</span>
                <span class="block truncate px-3 pb-2.5 text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">{{ in_array($game, $openKinds, true) ? 'Continue' : $info['line'] }}</span>
            </button>
        @endforeach
    </div>

    <p class="text-center text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">Your voice is recorded.</p>
</div>
