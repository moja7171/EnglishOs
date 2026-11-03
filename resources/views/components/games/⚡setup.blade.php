<?php

use App\Models\PairGame;
use App\Models\User;
use App\Services\PairGames;
use App\Services\TabooDeck;
use App\Services\JustAMinuteDeck;
use App\Services\StoryChainDeck;
use App\Services\WhoAmIDeck;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    public string $game;

    /** The friend picked to play with ("Again" from the Games page sets it). */
    #[Url]
    public ?int $friend = null;

    #[Url]
    public int $minutes = 5;

    public function mount(string $game): void
    {
        abort_unless(in_array($game, PairGame::PLAYABLE, true), 404);

        $this->game = $game;
        $this->minutes = in_array($this->minutes, PairGame::LENGTHS, true) ? $this->minutes : PairGame::LENGTHS[0];

        if ($this->friend === null || ! collect($this->friends)->contains(fn (array $row) => $row['user']->id === $this->friend)) {
            $this->friend = $this->friends[0]['user']->id ?? null;
        }
    }

    /**
     * Mutual friends, the one I last played with first: each with their
     * turn length (the newer learner of the two sets the level), our best
     * score in this game and whether they are in the app now.
     *
     * @return list<array{user: User, turnSeconds: int, best: ?int, online: bool}>
     */
    #[Computed]
    public function friends(): array
    {
        $me = auth()->user();
        $deck = match ($this->game) {
            PairGame::GAME_WHO => app(WhoAmIDeck::class),
            PairGame::GAME_MINUTE => app(JustAMinuteDeck::class),
            PairGame::GAME_STORY => app(StoryChainDeck::class),
            default => app(TabooDeck::class),
        };
        $lastPartnerIds = PairGame::involving($me)->latest()->get(['user_a_id', 'user_b_id'])
            ->flatMap(fn (PairGame $pairGame) => [$pairGame->user_a_id, $pairGame->user_b_id])
            ->reject(fn (int $id) => $id === $me->id)
            ->unique()
            ->values();

        return $me->mutualFriends()
            ->sortBy(fn (User $friend) => [$lastPartnerIds->search($friend->id) === false ? PHP_INT_MAX : $lastPartnerIds->search($friend->id), strtolower($friend->name)])
            ->map(function (User $friend) use ($me, $deck) {
                $best = PairGame::forPair($me, $friend)->where('game', $this->game)->where('minutes', $this->minutes)->where('stage', PairGame::STAGE_DONE)->max('score');

                return [
                    'user' => $friend,
                    'turnSeconds' => $deck->rulesFor($me, $friend, $this->minutes)['turn_seconds'],
                    'best' => $best === null ? null : (int) $best,
                    'online' => $friend->isInApp(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * "Feedback on my sentences" on or off — kept for next time.
     */
    public function setVoiceFeedback(bool $on): void
    {
        auth()->user()->update(['game_voice_feedback' => $on]);
    }

    public function updatedMinutes(): void
    {
        $this->minutes = in_array($this->minutes, PairGame::LENGTHS, true) ? $this->minutes : PairGame::LENGTHS[0];
        unset($this->friends);
    }

    /**
     * Invites the friend (or picks up / joins the pair's open game) and
     * opens the game.
     */
    public function play(): void
    {
        $friend = User::findOrFail($this->friend);

        abort_unless(auth()->user()->canMessageWith($friend), 403);

        $pairGame = app(PairGames::class)->start(auth()->user(), $friend, $this->game, $this->minutes);

        if ($pairGame) {
            $this->redirectRoute('games.play', $pairGame, navigate: true);
        }
    }
};
?>

@php
    $info = PairGame::info($game);
    $picked = collect($this->friends)->first(fn (array $row) => $row['user']->id === $friend);
@endphp

<div class="mx-auto max-w-2xl pb-6">
    <div class="relative h-44 overflow-hidden bg-surface-sunken sm:rounded-b-3xl dark:bg-surface-sunken-dark">
        <img src="{{ $info['image'] }}" alt="" class="h-full w-full object-cover">
        <a href="{{ route('games.index') }}" wire:navigate aria-label="Back to Games" class="absolute top-3 left-3 inline-flex h-11 w-11 items-center justify-center rounded-full bg-surface text-ink shadow-sm dark:bg-surface-dark dark:text-ink-dark">@svg('heroicon-o-chevron-left', 'h-6 w-6')</a>
    </div>

    <div class="relative -mt-6 space-y-5 rounded-t-3xl bg-ground px-4 pt-5 sm:px-6 dark:bg-ground-dark">
        <div>
            <h1 class="font-display text-3xl font-extrabold text-ink dark:text-ink-dark">{{ $info['name'] }}</h1>
            <p class="text-sm font-semibold text-ink-faint dark:text-ink-faint-dark">{{ match ($game) {
                PairGame::GAME_WHO => 'Ask yes/no questions. Guess the secret.',
                PairGame::GAME_MINUTE => 'Talk about a topic. Use the four words.',
                PairGame::GAME_STORY => 'Tell a story together, a sentence or two each.',
                default => 'Explain the word. Don’t say it.',
            } }}</p>
        </div>

        <details class="group rounded-2xl bg-surface px-4 py-3 shadow-sm dark:bg-surface-dark">
            <summary dir="rtl" class="font-fa flex cursor-pointer list-none items-center justify-between text-sm font-bold text-accent-ink dark:text-accent-ink-dark">
                چطور بازی کنیم؟
                <span class="transition-transform group-open:rotate-180">@svg('heroicon-o-chevron-down', 'h-4 w-4')</span>
            </summary>
            <div class="pt-2.5">@include('components.games.partials.guide', ['gameKey' => $game])</div>
        </details>

        <div class="grid grid-cols-3 gap-2 text-center text-xs font-bold text-ink dark:text-ink-dark">
            <div class="flex flex-col items-center gap-1.5 rounded-2xl bg-surface p-3 shadow-sm dark:bg-surface-dark">
                <span class="text-accent dark:text-accent-dark">@svg('heroicon-o-clock', 'h-6 w-6')</span>
                {{ $picked['turnSeconds'] ?? TabooDeck::TURN_SECONDS }} s each
            </div>
            <div class="flex flex-col items-center gap-1.5 rounded-2xl bg-surface p-3 shadow-sm dark:bg-surface-dark">
                <span class="text-accent dark:text-accent-dark">@svg('heroicon-o-arrows-right-left', 'h-6 w-6')</span>
                Then swap
            </div>
            <div class="flex flex-col items-center gap-1.5 rounded-2xl bg-surface p-3 shadow-sm dark:bg-surface-dark">
                <span class="text-accent dark:text-accent-dark">@svg('heroicon-o-trophy', 'h-6 w-6')</span>
                Beat your record
            </div>
        </div>

        @if ($this->friends === [])
            <div class="rounded-2xl border border-dashed border-line p-5 text-center dark:border-line-dark">
                <p class="text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">Add a friend first — you both need to follow each other.</p>
                <a href="{{ route('friends.index') }}" wire:navigate class="mt-2 inline-block text-sm font-bold text-accent-ink dark:text-accent-ink-dark">Find friends ›</a>
            </div>
        @else
            <div class="space-y-2">
                <p class="text-xs font-extrabold tracking-wider text-ink-faint uppercase dark:text-ink-faint-dark">Play with</p>
                <div class="-mx-4 flex gap-2.5 overflow-x-auto px-4 pb-1 sm:-mx-6 sm:px-6">
                    @foreach ($this->friends as $row)
                        @php $isPicked = $row['user']->id === $friend; @endphp
                        <button
                            type="button"
                            wire:key="friend-{{ $row['user']->id }}"
                            wire:click="$set('friend', {{ $row['user']->id }})"
                            @class([
                                'flex w-24 shrink-0 cursor-pointer flex-col items-center gap-1.5 rounded-2xl border-2 px-2 py-2.5',
                                'border-accent bg-accent-soft dark:border-accent-dark dark:bg-accent-soft-dark' => $isPicked,
                                'border-transparent bg-surface shadow-sm dark:bg-surface-dark' => ! $isPicked,
                            ])
                        >
                            <x-user-avatar :user="$row['user']" class="h-11 w-11 text-sm" />
                            <span class="w-full truncate text-center text-[13px] font-bold text-ink dark:text-ink-dark">{{ $row['user']->name }}</span>
                            <span @class(['text-[11px] font-semibold', 'text-success dark:text-success-dark' => $row['online'], 'text-ink-faint dark:text-ink-faint-dark' => ! $row['online']])>{{ match (true) {
                                $row['online'] => 'Online',
                                $row['best'] !== null => 'Best: '.PairGame::formatPoints($row['best']),
                                default => 'New',
                            } }}</span>
                        </button>
                    @endforeach
                </div>
            </div>

            <div class="space-y-2">
                <p class="text-xs font-extrabold tracking-wider text-ink-faint uppercase dark:text-ink-faint-dark">Time</p>
                <div class="grid grid-cols-2 gap-1.5 rounded-2xl bg-surface-sunken p-1 dark:bg-surface-sunken-dark">
                    @foreach (PairGame::LENGTHS as $length)
                        <button type="button" wire:key="length-{{ $length }}" wire:click="$set('minutes', {{ $length }})" @class([
                            'h-11 cursor-pointer rounded-xl text-[15px]',
                            'bg-surface font-extrabold text-ink shadow-sm dark:bg-surface-dark dark:text-ink-dark' => $minutes === $length,
                            'font-bold text-ink-soft dark:text-ink-soft-dark' => $minutes !== $length,
                        ])>{{ $length }} min</button>
                    @endforeach
                </div>
            </div>

            @include('components.games.partials.voice-switch', ['me' => auth()->user()])

            <p class="text-center text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">{{ match ($game) {
                PairGame::GAME_WHO => 'Secrets: jobs, food, places… from your missions',
                PairGame::GAME_MINUTE => 'Topics and words: your missions',
                PairGame::GAME_STORY => '5 min = one story · 10 min = two',
                default => 'Words: your missions + your notebooks',
            } }}</p>

            <button
                type="button"
                wire:click="play"
                wire:loading.attr="disabled"
                wire:target="play"
                class="block w-full cursor-pointer rounded-2xl bg-accent px-5 py-4 text-lg font-extrabold text-white shadow-[0_4px_0_color-mix(in_srgb,var(--color-accent)_60%,black)] transition-opacity hover:opacity-90 disabled:opacity-50 dark:bg-accent-dark"
            >Invite {{ $picked['user']->name ?? '' }} · Play</button>
        @endif
    </div>
</div>
