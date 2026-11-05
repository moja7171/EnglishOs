<?php

use App\Models\PairGame;
use App\Models\User;
use App\Services\PairGames;
use App\Services\PairPractice;
use App\Services\TabooDeck;
use App\Services\JustAMinuteDeck;
use App\Services\RolePlayDeck;
use App\Services\StoryChainDeck;
use App\Services\TwoTruthsDeck;
use App\Services\WhoAmIDeck;
use App\Services\WouldYouRatherDeck;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    public string $game;

    /** Friend tiles on the page; the rest wait in the All friends sheet. */
    public const TILES_SHOWN = 8;

    /** The friend picked to play with ("Again" from the Games page sets it). */
    #[Url]
    public ?int $friend = null;

    #[Url]
    public int $minutes = 5;

    /** "auto" = every day both finished, "choose" = the days picked below. */
    public string $dayMode = 'auto';

    /** @var list<string> */
    public array $days = [];

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
     * Every mutual friend: whoever is in the app now, then the latest
     * partners.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function allFriends(): Collection
    {
        return auth()->user()->friendsForPicking()->sortBy(fn (User $friend) => $friend->isInApp() ? 0 : 1)->values();
    }

    /**
     * The friends on tiles, the picked one included even if far down the
     * list: each with their
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
            PairGame::GAME_ROLE => app(RolePlayDeck::class),
            PairGame::GAME_TRUTHS => app(TwoTruthsDeck::class),
            PairGame::GAME_RATHER => app(WouldYouRatherDeck::class),
            default => app(TabooDeck::class),
        };
        $picked = $this->allFriends->firstWhere('id', $this->friend);
        $shown = $this->allFriends->take(self::TILES_SHOWN);

        if ($picked && ! $shown->contains('id', $picked->id)) {
            $shown = $shown->take(self::TILES_SHOWN - 1)->prepend($picked);
        }

        return $shown
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
        auth()->user()->update(['games_voice_feedback' => $on]);
    }

    public function updatedMinutes(): void
    {
        $this->minutes = in_array($this->minutes, PairGame::LENGTHS, true) ? $this->minutes : PairGame::LENGTHS[0];
        unset($this->friends);
    }

    public function updatedFriend(): void
    {
        $this->dayMode = 'auto';
        $this->days = [];
        unset($this->friends, $this->dayChoices);
    }

    /**
     * The days to pick from with the friend on screen.
     *
     * @return list<array{code: string, days: list<array{key: string, day: int, shared: bool}>}>
     */
    #[Computed]
    public function dayChoices(): array
    {
        $friend = $this->allFriends->firstWhere('id', $this->friend);

        return $friend ? app(PairPractice::class)->dayChoices(auth()->user(), $friend) : [];
    }

    public function usesDays(): bool
    {
        return in_array($this->game, PairGame::DAY_BASED, true) && collect($this->dayChoices)->flatMap(fn (array $mission) => $mission['days'])->contains('shared', true);
    }

    /**
     * Invites the friend (or picks up / joins the pair's open game) and
     * opens the game.
     */
    public function play(): void
    {
        $friend = User::findOrFail($this->friend);

        abort_unless(auth()->user()->canMessageWith($friend), 403);

        $days = $this->dayMode === 'choose' && $this->usesDays() ? array_values(array_filter($this->days, 'is_string')) : null;

        $pairGame = app(PairGames::class)->start(auth()->user(), $friend, $this->game, $this->minutes, $days);

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

<div class="mx-auto max-w-2xl space-y-4 px-4 pt-3 pb-6 sm:px-6">
    <header class="flex items-center gap-2">
        <a href="{{ route('games.index') }}" wire:navigate aria-label="Back to Games" class="-ms-2 inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-ink-soft hover:bg-surface-sunken dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">@svg('heroicon-o-chevron-left', 'h-6 w-6')</a>
        <div class="min-w-0 flex-1">
            <h1 class="font-display text-3xl font-extrabold text-ink dark:text-ink-dark">{{ $info['name'] }}</h1>
            <p class="text-sm font-semibold text-ink-faint dark:text-ink-faint-dark">{{ match ($game) {
                PairGame::GAME_WHO => 'Ask yes/no questions. Guess the secret.',
                PairGame::GAME_MINUTE => 'Talk about a topic. Use the four words.',
                PairGame::GAME_STORY => 'Tell a story together, a sentence or two each.',
                PairGame::GAME_ROLE => 'Play a scene. Each has a secret goal.',
                PairGame::GAME_TRUTHS => 'Say three things, one is a lie. Find the lie.',
                PairGame::GAME_RATHER => 'Pick a side, guess your friend’s, say why.',
                default => 'Explain the word. Don’t say it.',
            } }}</p>
        </div>
        <x-usage-guide-button label="How to play" />
    </header>

    <div class="space-y-4">
        @if ($this->friends === [])
            <div class="rounded-2xl border border-dashed border-line p-5 text-center dark:border-line-dark">
                <p class="text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">Add a friend first — you both need to follow each other.</p>
                <a href="{{ route('friends.index') }}" wire:navigate class="mt-2 inline-block text-sm font-bold text-accent-ink dark:text-accent-ink-dark">Find friends ›</a>
            </div>
        @else
            <div class="card space-y-2 p-4" x-data="{ allFriends: false }">
                <p class="text-xs font-extrabold tracking-wider text-ink-faint uppercase dark:text-ink-faint-dark">Play with</p>
                <div class="-mx-4 flex gap-2.5 overflow-x-auto px-4 pb-1">
                    @foreach ($this->friends as $row)
                        @php $isPicked = $row['user']->id === $friend; @endphp
                        <button
                            type="button"
                            wire:key="friend-{{ $row['user']->id }}"
                            wire:click="$set('friend', {{ $row['user']->id }})"
                            @class([
                                'flex w-24 shrink-0 cursor-pointer flex-col items-center gap-1.5 rounded-2xl border-2 px-2 py-2.5',
                                'border-accent bg-accent-soft dark:border-accent-dark dark:bg-accent-soft-dark' => $isPicked,
                                'border-line dark:border-line-dark' => ! $isPicked,
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
                    @if ($this->allFriends->count() > self::TILES_SHOWN)
                        <button type="button" x-on:click="allFriends = true" class="flex w-24 shrink-0 cursor-pointer flex-col items-center justify-center gap-1.5 rounded-2xl bg-surface px-2 py-2.5 text-ink-soft shadow-sm dark:bg-surface-dark dark:text-ink-soft-dark">
                            @svg('heroicon-o-user-group', 'h-6 w-6')
                            <span class="text-[13px] font-bold">All ({{ $this->allFriends->count() }})</span>
                        </button>
                    @endif
                </div>
                <x-friend-sheet show="allFriends" label="All friends" :friends="$this->allFriends" :action-for="fn ($friend) => '$set(\'friend\', '.$friend->id.')'" />
            </div>

            <div class="card space-y-2 p-4">
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

            @if ($this->usesDays())
                <div
                    wire:key="days-{{ $friend }}"
                    x-data="{
                        mode: $wire.entangle('dayMode'),
                        days: $wire.entangle('days'),
                        toggle(key) {
                            this.days = this.days.includes(key) ? this.days.filter((day) => day !== key) : [...this.days, key];
                        },
                        toggleAll(keys) {
                            this.days = keys.every((key) => this.days.includes(key))
                                ? this.days.filter((day) => ! keys.includes(day))
                                : [...new Set([...this.days, ...keys])];
                        },
                    }"
                    class="card space-y-2 p-4"
                >
                    <p class="text-xs font-extrabold tracking-wider text-ink-faint uppercase dark:text-ink-faint-dark">Days</p>
                    @include('components.pair-practice.partials.day-picker', ['autoLine' => 'All the days you both finished', 'missions' => $this->dayChoices, 'keyPrefix' => $friend])
                </div>
            @endif

            @include('components.games.partials.voice-switch', ['voiceOn' => auth()->user()->games_voice_feedback])

            <p class="text-center text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">{{ match ($game) {
                PairGame::GAME_WHO => 'Secrets: jobs, food, places… from your missions',
                PairGame::GAME_MINUTE => 'Topics and words: your missions',
                PairGame::GAME_STORY => '5 min = one story · 10 min = two',
                PairGame::GAME_ROLE => 'Scenes from your missions · 5 min = 2 · 10 min = 4',
                PairGame::GAME_TRUTHS => 'Topics: your life, with your missions’ grammar',
                PairGame::GAME_RATHER => 'Questions from your missions’ topics',
                default => 'Words: your missions + your notebooks',
            } }}</p>

            <button
                type="button"
                wire:click="play"
                wire:loading.attr="disabled"
                wire:target="play"
                class="block w-full cursor-pointer rounded-full bg-accent px-5 py-4 text-base font-extrabold text-white transition-opacity hover:opacity-90 disabled:opacity-50 dark:bg-accent-dark"
            >Invite {{ $picked['user']->name ?? '' }} · Play</button>
        @endif
    </div>
</div>
