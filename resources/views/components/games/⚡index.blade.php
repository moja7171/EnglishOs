<?php

use App\Models\PairGame;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    /**
     * A game of mine still open: a friend's invite to join, my own invite
     * waiting, or a game to go back to.
     */
    #[Computed]
    public function openGame(): ?PairGame
    {
        return PairGame::involving(auth()->user())->open()->with(['userA', 'userB'])->latest()->first();
    }

    /**
     * My last finished game, for "Again" with the same friend and length.
     */
    #[Computed]
    public function lastGame(): ?PairGame
    {
        return PairGame::involving(auth()->user())->where('stage', PairGame::STAGE_DONE)->with(['userA', 'userB'])->latest('finished_at')->first();
    }
};
?>

@php
    $me = auth()->user();
    $tagStyles = [
        'Words' => 'bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark',
        'Speaking' => 'bg-success-soft text-success dark:bg-success-soft-dark dark:text-success-dark',
        'Questions' => 'bg-surface-sunken text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark',
    ];
@endphp

{{-- Photo tiles, one per game (a game not built yet is shown as Soon), then my badges. --}}
<div class="mx-auto max-w-2xl space-y-4 px-4 pt-3 pb-6 sm:px-6" x-data x-on:eos-friends-changed.window="$wire.$refresh()">
    <header class="flex items-center gap-2">
        <a href="{{ route('friends.index') }}" wire:navigate aria-label="Back to Friends" class="-ms-2 inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-ink-soft hover:bg-surface-sunken dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">@svg('heroicon-o-chevron-left', 'h-6 w-6')</a>
        <div class="min-w-0 flex-1">
            <h1 class="font-display text-3xl font-extrabold text-ink dark:text-ink-dark">Games</h1>
            <p class="text-sm font-semibold text-ink-faint dark:text-ink-faint-dark">Play with a friend. Speak English.</p>
        </div>
        <x-usage-guide-button />
    </header>

    @if ($open = $this->openGame)
        @php
            $partner = $open->partnerFor($me);
            $invitesMe = $open->isWaiting() && $open->started_by_id === $partner->id;
        @endphp
        <a href="{{ $invitesMe ? route('games.play', ['pairGame' => $open, 'join' => 1]) : route('games.play', $open) }}" wire:navigate class="flex items-center gap-3 rounded-2xl bg-success-soft px-3 py-2.5 shadow-sm dark:bg-success-soft-dark">
            <x-user-avatar :user="$partner" class="h-10 w-10 text-xs" />
            <p class="min-w-0 flex-1 text-sm font-extrabold text-ink dark:text-ink-dark">
                <span class="block truncate">{{ match (true) {
                    $invitesMe => $partner->name.' is waiting for you',
                    $open->isWaiting() => 'Waiting for '.$partner->name.'…',
                    default => 'Game with '.$partner->name,
                } }}</span>
                <span class="block truncate text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">{{ PairGame::info($open->game)['name'] }} · {{ $open->minutes }} min</span>
            </p>
            <span class="shrink-0 rounded-full bg-success px-4 py-2 text-xs font-extrabold text-white dark:bg-success-dark">{{ $invitesMe ? 'Join' : ($open->isWaiting() ? 'Open' : 'Continue') }}</span>
        </a>
    @elseif ($last = $this->lastGame)
        @php
            $partner = $last->partnerFor($me);
            $best = max($last->score, $last->previousBest() ?? 0);
        @endphp
        <div class="flex items-center gap-3 rounded-2xl bg-surface px-3 py-2.5 shadow-sm dark:bg-surface-dark">
            <x-user-avatar :user="$partner" class="h-10 w-10 text-xs" />
            <p class="min-w-0 flex-1 text-sm font-extrabold text-ink dark:text-ink-dark">
                <span class="block truncate">Last: {{ PairGame::info($last->game)['name'] }} with {{ $partner->name }}</span>
                <span class="block truncate text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">Your record together: {{ PairGame::formatPoints($best) }}</span>
            </p>
            @if (PairGame::info($last->game)['playable'])
                <a href="{{ route('games.setup', ['game' => $last->game, 'friend' => $partner->id, 'minutes' => $last->minutes]) }}" wire:navigate class="shrink-0 rounded-full border border-line px-4 py-2 text-xs font-extrabold text-ink dark:border-line-dark dark:text-ink-dark">Again</a>
            @endif
        </div>
    @endif

    <div class="grid grid-cols-2 gap-3">
        @foreach (PairGame::GAMES as $game)
            @php $info = PairGame::info($game); @endphp
            @if ($info['playable'])
                <a href="{{ route('games.setup', $game) }}" wire:navigate wire:key="game-{{ $game }}" class="group flex flex-col overflow-hidden rounded-2xl bg-surface shadow-sm transition-transform active:scale-[0.98] dark:bg-surface-dark">
            @else
                <div wire:key="game-{{ $game }}" class="flex flex-col overflow-hidden rounded-2xl bg-surface opacity-60 shadow-sm dark:bg-surface-dark" aria-disabled="true">
            @endif
                <div class="relative h-28 bg-surface-sunken dark:bg-surface-sunken-dark">
                    <img src="{{ $info['image'] }}" alt="" loading="lazy" class="h-full w-full object-cover">
                    @if ($game === PairGame::GAME_TABOO)
                        <span class="absolute top-2 left-2 rounded-full bg-surface px-2 py-0.5 text-[11px] font-extrabold text-accent-ink dark:bg-surface-dark dark:text-accent-ink-dark">Most loved</span>
                    @elseif (! $info['playable'])
                        <span class="absolute top-2 left-2 rounded-full bg-surface px-2 py-0.5 text-[11px] font-extrabold text-ink-soft dark:bg-surface-dark dark:text-ink-soft-dark">Soon</span>
                    @endif
                </div>
                <div class="flex flex-1 flex-col gap-0.5 p-3">
                    <span class="text-[15px] font-extrabold text-ink dark:text-ink-dark">{{ $info['name'] }}</span>
                    <span class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">{{ $info['line'] }}</span>
                    <span class="mt-1.5 self-start rounded-full px-2 py-0.5 text-[11px] font-extrabold {{ $tagStyles[$info['tag']] }}">{{ $info['tag'] }}</span>
                </div>
            @if ($info['playable'])
                </a>
            @else
                </div>
            @endif
        @endforeach
    </div>

    @include('components.games.partials.badges', ['badges' => app(App\Services\GameBadges::class)->for($me), 'title' => 'Your badges'])
</div>
