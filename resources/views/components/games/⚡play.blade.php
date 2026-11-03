<?php

use App\Models\PairGame;
use App\Notifications\PairGameInvite;
use App\Services\PairGames;
use App\Services\TabooDeck;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

new class extends Component
{
    public PairGame $pairGame;

    /**
     * Opened from an invite's Join (?join=1), the invited friend is in the
     * game with that one tap. The invite has done its job once the game is
     * open on their phone.
     */
    public function mount(PairGame $pairGame): void
    {
        abort_unless($pairGame->isAccessibleBy(auth()->user()), 403);

        if (request()->boolean('join')) {
            $pairGame->joinWithLock(auth()->user());
        }

        auth()->user()->unreadNotifications()
            ->where('type', PairGameInvite::class)
            ->get()
            ->filter(fn ($notification) => ($notification->data['game_id'] ?? null) === $pairGame->id)
            ->each->markAsRead();

        $this->pairGame = $pairGame;
    }

    public function join(): void
    {
        $this->act(fn (PairGame $pairGame) => $pairGame->join(auth()->user()));
    }

    public function startTurn(): void
    {
        $this->act(fn (PairGame $pairGame) => $pairGame->startTurn(auth()->user()));
    }

    /**
     * The explainer's taps. $seen is how many cards of this turn their
     * phone had seen, so a double tap or a stale screen changes nothing.
     */
    public function got(int $seen): void
    {
        $this->act(fn (PairGame $pairGame) => $pairGame->scoreCard(auth()->user(), PairGame::RESULT_GOT, $seen));
    }

    public function skip(int $seen): void
    {
        $this->act(fn (PairGame $pairGame) => $pairGame->scoreCard(auth()->user(), PairGame::RESULT_SKIP, $seen));
    }

    public function showLetter(int $seen): void
    {
        $this->act(fn (PairGame $pairGame) => $pairGame->showLetter(auth()->user(), $seen));
    }

    public function bonusSaid(int $seen): void
    {
        $this->act(fn (PairGame $pairGame) => $pairGame->bonusSaid(auth()->user(), $seen));
    }

    /**
     * Called by the timer on both phones; only the turn the timer belonged
     * to can be closed, and only once its time is up.
     */
    public function timeUp(int $turn): void
    {
        $this->act(fn (PairGame $pairGame) => $pairGame->timeUp($turn));
    }

    /**
     * Before the friend joined: calls the invite off. During the game:
     * ends it for both.
     */
    public function end(): void
    {
        $wasWaiting = $this->pairGame->isWaiting();

        $this->act(fn (PairGame $pairGame) => $pairGame->isWaiting()
            ? $pairGame->cancel(auth()->user())
            : $pairGame->endEarly(auth()->user()));

        if ($wasWaiting) {
            $this->redirectRoute('games.index', navigate: true);
        }
    }

    /**
     * The same game and length again with the same friend — or the
     * friend's new game, if they were quicker.
     */
    public function playAgain(): void
    {
        abort_unless($this->pairGame->isAccessibleBy(auth()->user()), 403);

        $me = auth()->user();
        $friend = $this->pairGame->partnerFor($me);

        abort_unless($me->canMessageWith($friend), 403);

        $next = app(PairGames::class)->start($me, $friend, $this->pairGame->game, $this->pairGame->minutes);

        if ($next) {
            $this->redirectRoute('games.play', $next, navigate: true);
        }
    }

    /**
     * A game of the same pair started after this one and still open.
     */
    public function nextGame(): ?PairGame
    {
        return PairGame::forPair($this->pairGame->userA, $this->pairGame->userB)->open()->whereKeyNot($this->pairGame->id)->latest()->first();
    }

    /**
     * Both phones act on one game, so every change re-reads it under a row
     * lock first.
     */
    private function act(callable $callback): void
    {
        abort_unless($this->pairGame->isAccessibleBy(auth()->user()), 403);

        DB::transaction(function () use ($callback) {
            $this->pairGame = PairGame::lockForUpdate()->findOrFail($this->pairGame->id);
            $callback($this->pairGame);
        });
    }
};
?>

@php
    $me = auth()->user();
    $friend = $pairGame->partnerFor($me);
    $info = PairGame::info($pairGame->game);
    $best = $pairGame->previousBest();
    $poll = match (true) {
        $pairGame->isCancelled() => null,
        $pairGame->isPlaying() => '1s',
        $pairGame->isFinished() => '4s',
        default => '2s',
    };
@endphp

<div class="mx-auto max-w-xl" @if ($poll) wire:poll.{{ $poll }} @endif>
    @if ($pairGame->isCancelled())
        <div class="space-y-2 p-6 text-center">
            @php $endedBy = $pairGame->endedBy; @endphp
            <p class="text-sm font-semibold text-ink dark:text-ink-dark">{{ $endedBy?->is($me) ? 'You' : ($endedBy->name ?? 'Your friend') }} {{ $pairGame->joined_at ? 'ended the game.' : 'cancelled the invite.' }}</p>
            <a href="{{ route('games.index') }}" wire:navigate class="inline-block pt-1 text-sm font-semibold text-accent-ink dark:text-accent-ink-dark">Back to Games ›</a>
        </div>
    @elseif ($pairGame->isWaiting())
        @php $isStarter = $pairGame->started_by_id === $me->id; @endphp
        <div class="space-y-4 p-4">
            <a href="{{ route('games.index') }}" wire:navigate aria-label="Back to Games" class="inline-flex h-10 w-10 items-center justify-center rounded-full text-ink-soft hover:bg-surface-sunken dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">@svg('heroicon-o-chevron-left', 'h-6 w-6')</a>

            @if ($pairGame->isExpired())
                <div class="card p-6 text-center">
                    <p class="text-sm font-semibold text-ink dark:text-ink-dark">This invite has expired.</p>
                    <a href="{{ route('games.setup', ['game' => $pairGame->game, 'friend' => $friend->id, 'minutes' => $pairGame->minutes]) }}" wire:navigate class="mt-3 inline-block text-sm font-semibold text-accent-ink dark:text-accent-ink-dark">Invite again ›</a>
                </div>
            @else
                <div class="card space-y-4 overflow-hidden p-0 text-center">
                    <img src="{{ $info['image'] }}" alt="" class="h-36 w-full object-cover">
                    <div class="space-y-4 px-6 pb-6">
                        <x-user-avatar :user="$friend" class="mx-auto -mt-12 h-16 w-16 text-lg ring-4 ring-surface dark:ring-surface-dark" />
                        <div>
                            <p class="font-display text-xl font-bold text-ink dark:text-ink-dark">{{ $isStarter ? 'Waiting for '.$friend->name.'…' : $friend->name.' wants to play' }}</p>
                            <p class="mt-1 text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">{{ $info['name'] }} · {{ $pairGame->minutes }} min{{ $best !== null ? ' · Best '.PairGame::formatPoints($best) : '' }}</p>
                        </div>
                        @unless ($isStarter)
                            <button type="button" wire:click="join" wire:loading.attr="disabled" wire:target="join" class="block w-full cursor-pointer rounded-2xl bg-accent px-4 py-3.5 text-base font-extrabold text-white disabled:opacity-50 dark:bg-accent-dark">Join</button>
                        @endunless
                        <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Not together? Call on any app.</p>
                    </div>
                </div>
                <button type="button" wire:click="end" class="mx-auto block cursor-pointer text-xs font-semibold text-ink-faint hover:text-ink-soft dark:text-ink-faint-dark dark:hover:text-ink-soft-dark">Cancel</button>
            @endif
        </div>
    @elseif ($pairGame->isFinished())
        @include('components.games.partials.taboo-result')
    @else
        @include('components.games.partials.taboo-arena')
    @endif
</div>
