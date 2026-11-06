<?php

use App\Models\AiPractice;
use App\Models\PairPracticeSession;
use App\Models\User;
use App\Notifications\PairPracticeInvite;
use App\Services\AiPractices;
use App\Services\PairPractice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    /**
     * A day to practise ("M05-2"), from a mission day's "Practice with a
     * friend" link — picked in advance when the two share it.
     */
    #[Url]
    public ?string $day = null;

    /** The friend picked from All friends, whose card comes first. */
    public ?int $pickedFriendId = null;

    public const CARDS_SHOWN = 5;

    public function pickFriend(int $friendId): void
    {
        abort_unless($this->allFriends->contains('id', $friendId), 403);

        $this->pickedFriendId = $friendId;
        unset($this->pairs);
    }

    /**
     * Every mutual friend, latest partner first.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function allFriends(): Collection
    {
        return auth()->user()->friendsForPicking();
    }

    /**
     * The friends with a card on the page: a card is heavy, so only the
     * picked one and the latest partners get one.
     *
     * @return Collection<int, User>
     */
    private function friendsWithCards(): Collection
    {
        return $this->allFriends
            ->sortBy(fn (User $friend) => $friend->id === $this->pickedFriendId ? 0 : 1)
            ->take(self::CARDS_SHOWN)
            ->values();
    }

    /**
     * One row per friend: what a round would practise, every day either
     * of you finished (for picking days) and how often the two have
     * practised together.
     *
     * @return list<array{friend: User, unfinished: bool, together: array{times: int, daysInRow: int, daysSince: ?int}, next: ?string, shared: list<string>, missions: list<array{code: string, days: list<array{key: string, day: int, shared: bool}>}>, nudged: bool, open: ?PairPracticeSession}>
     */
    #[Computed]
    public function pairs(): array
    {
        $me = auth()->user();
        $pairPractice = app(PairPractice::class);
        $mine = $pairPractice->learnedDays($me);

        return $this->friendsWithCards()->map(function (User $friend) use ($me, $pairPractice, $mine) {
            $theirs = $pairPractice->learnedDays($friend);
            $shared = $mine->intersectByKeys($theirs);
            $latest = $shared->keys()->last();

            return [
                'friend' => $friend,
                'unfinished' => ! $pairPractice->todayProgress($friend)['finished'],
                'together' => $pairPractice->together($me, $friend),
                'next' => $latest ? PairPracticeSession::describeDays([$latest]).($shared->count() > 1 ? ' + review' : '') : null,
                'shared' => $shared->keys()->values()->all(),
                'missions' => $pairPractice->dayChoices($me, $friend),
                'nudged' => Cache::has(PairPractice::nudgeKey($me, $friend)),
                'open' => PairPracticeSession::forPair($me, $friend)->open()->latest()->first(),
            ];
        })->values()->all();
    }

    /**
     * A round with Teddy, for when no friend is around: the learner's
     * open one, or a new one from the days they finished.
     *
     * @param  list<string>|null  $days
     */
    public function startWithAi(?array $days = null): void
    {
        $days = $days === null ? null : array_values(array_filter($days, 'is_string'));
        $practice = app(AiPractices::class)->start(auth()->user(), AiPractice::KIND_PRACTICE, $days ?: null);

        if ($practice) {
            $this->redirectRoute('ai-practice.show', $practice, navigate: true);
        }
    }

    /**
     * Every day the learner finished, to pick from for Teddy.
     *
     * @return list<array{code: string, days: list<array{key: string, day: int, shared: bool}>}>
     */
    #[Computed]
    public function myDays(): array
    {
        return app(PairPractice::class)->dayChoices(auth()->user(), auth()->user());
    }

    /**
     * One nudge per friend per day — a bell row plus a phone push.
     */
    public function nudge(int $friendId): void
    {
        $friend = User::findOrFail($friendId);

        abort_unless(auth()->user()->canMessageWith($friend), 403);

        app(PairPractice::class)->nudge(auth()->user(), $friend);

        unset($this->pairs);
    }

    /**
     * Picks up the pair's open round (or joins the friend's invite in the
     * same tap), or
     * draws a new one — from the chosen days, or the newest shared day
     * plus review when $days is null — and invites the friend to join on
     * their phone.
     *
     * @param  list<string>|null  $days
     */
    public function start(int $friendId, ?array $days = null): void
    {
        $friend = User::findOrFail($friendId);

        abort_unless(auth()->user()->canMessageWith($friend), 403);

        $days = $days === null ? null : array_values(array_filter($days, 'is_string'));
        $session = PairPracticeSession::forPair(auth()->user(), $friend)->open()->latest()->first();

        if (! $session && $session = app(PairPractice::class)->start(auth()->user(), $friend, $days ?: null)) {
            $friend->notify(new PairPracticeInvite($session, auth()->user()));
        }

        $session?->joinWithLock(auth()->user());

        if ($session) {
            $this->redirectRoute('pair-practice.show', $session, navigate: true);
        }
    }
};
?>

{{-- Like the other Friends screens: ‹ title, then one card per friend with
     how often the two practised together and the one button. --}}
<div class="stagger-children mx-auto max-w-2xl space-y-3 p-4 sm:p-6" x-data x-on:eos-friends-changed.window="$wire.$refresh()">
    <header class="flex items-center gap-2">
        <a href="{{ route('friends.index') }}" wire:navigate aria-label="Back to Friends" class="-ms-2 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-ink-soft transition-colors hover:bg-surface-sunken dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">@svg('heroicon-o-chevron-left', 'h-6 w-6')</a>
        <div class="min-w-0 flex-1">
            <h1 class="font-display text-2xl font-extrabold text-ink dark:text-ink-dark">Practice together</h1>
            @if ($day)
                <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">{{ PairPracticeSession::describeDays([$day]) }} · pick a friend</p>
            @endif
        </div>
        <x-usage-guide-button />
    </header>

    @php $openWithAi = AiPractice::where('learner_id', auth()->id())->where('kind', AiPractice::KIND_PRACTICE)->open()->exists(); @endphp
    <div
        x-data="{
            sheet: false,
            mode: 'auto',
            days: [],
            toggle(key) {
                this.days = this.days.includes(key) ? this.days.filter((day) => day !== key) : [...this.days, key];
            },
            toggleAll(keys) {
                this.days = keys.every((key) => this.days.includes(key))
                    ? this.days.filter((day) => ! keys.includes(day))
                    : [...new Set([...this.days, ...keys])];
            },
        }"
        class="card space-y-2.5 rounded-3xl p-4"
    >
        <div class="flex items-center gap-3">
            <x-teddy-avatar class="h-11 w-11" />
            <div class="min-w-0 flex-1">
                <p class="font-semibold text-ink dark:text-ink-dark">Teddy</p>
                <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Your AI classmate. Your voice is recorded.</p>
            </div>
        </div>

        @if ($openWithAi)
            <button type="button" wire:click="startWithAi" wire:loading.attr="disabled" wire:target="startWithAi" class="block w-full cursor-pointer rounded-2xl border-2 border-accent px-4 py-3 text-base font-extrabold text-accent-ink disabled:opacity-50 dark:border-accent-dark dark:text-accent-ink-dark">Continue</button>
        @elseif ($this->myDays === [])
            <p class="rounded-full border border-dashed border-line px-4 py-2.5 text-center text-xs font-semibold text-ink-faint dark:border-line-dark dark:text-ink-faint-dark">Finish a day first</p>
        @else
            <button type="button" x-on:click="sheet = true" class="block w-full cursor-pointer rounded-2xl border-2 border-accent px-4 py-3 text-base font-extrabold text-accent-ink dark:border-accent-dark dark:text-accent-ink-dark">Practice with Teddy</button>

            <x-bottom-sheet show="sheet" label="Practice with Teddy">
                <x-slot:title>Practice with Teddy</x-slot:title>
                @include('components.pair-practice.partials.day-picker', ['autoLine' => 'Your latest days', 'missions' => $this->myDays, 'keyPrefix' => 'ai'])
                <button type="button" x-bind:disabled="mode === 'choose' && days.length === 0" x-on:click="$wire.startWithAi(mode === 'auto' ? null : days); sheet = false" class="block w-full cursor-pointer rounded-full bg-accent px-4 py-3 text-sm font-semibold text-white disabled:opacity-40 dark:bg-accent-dark">Start</button>
            </x-bottom-sheet>
        @endif
    </div>

    @forelse ($this->pairs as $pair)
        @php
            $friend = $pair['friend'];
            $preset = $day !== null && in_array($day, $pair['shared'], true);
            $together = $pair['together'];
        @endphp
        <div
            wire:key="pair-{{ $friend->id }}"
            x-data="{
                sheet: false,
                mode: @js($preset ? 'choose' : 'auto'),
                days: @js($preset ? [$day] : []),
                toggle(key) {
                    this.days = this.days.includes(key) ? this.days.filter((day) => day !== key) : [...this.days, key];
                },
                toggleAll(keys) {
                    this.days = keys.every((key) => this.days.includes(key))
                        ? this.days.filter((day) => ! keys.includes(day))
                        : [...new Set([...this.days, ...keys])];
                },
            }"
            class="card space-y-2.5 rounded-3xl p-4"
        >
            <div class="flex items-center gap-3">
                <x-user-avatar :user="$friend" class="h-11 w-11 text-sm" />
                <p class="min-w-0 flex-1 truncate font-semibold text-ink dark:text-ink-dark">{{ $friend->name }}</p>
                @if ($pair['unfinished'])
                    <button
                        type="button"
                        wire:click="nudge({{ $friend->id }})"
                        @disabled($pair['nudged'])
                        title="{{ $pair['nudged'] ? 'Nudged' : 'Nudge '.$friend->name.' to finish today' }}"
                        aria-label="{{ $pair['nudged'] ? 'Nudged' : 'Nudge' }}"
                        class="inline-flex h-9 w-9 shrink-0 cursor-pointer items-center justify-center rounded-full border border-line text-base transition-colors hover:bg-surface-sunken disabled:cursor-default disabled:opacity-60 dark:border-line-dark dark:hover:bg-surface-sunken-dark"
                    >{{ $pair['nudged'] ? '✓' : '👋' }}</button>
                @endif
            </div>

            @if ($together['times'] > 0)
                <div class="flex flex-wrap gap-1.5 text-xs font-bold">
                    <span class="rounded-full bg-success-soft px-2.5 py-0.5 text-success dark:bg-success-soft-dark dark:text-success-dark">👫 {{ $together['times'] }}× together</span>
                    @if ($together['daysInRow'] > 1)
                        <span class="rounded-full bg-accent-soft px-2.5 py-0.5 text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">🔥 {{ $together['daysInRow'] }} days</span>
                    @endif
                </div>
            @endif

            @if (($together['daysSince'] ?? 0) >= 3)
                <p class="text-xs font-bold text-accent-ink dark:text-accent-ink-dark">{{ $together['daysSince'] }} days without practicing together</p>
            @elseif ($pair['next'])
                <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Next: <span class="font-semibold text-ink-soft dark:text-ink-soft-dark">{{ $pair['next'] }}</span></p>
            @endif

            @if ($open = $pair['open'])
                <button
                    type="button"
                    wire:click="start({{ $friend->id }})"
                    wire:loading.attr="disabled"
                    wire:target="start"
                    class="block w-full cursor-pointer truncate rounded-2xl bg-accent px-4 py-3 text-base font-extrabold text-white shadow-[0_4px_0_color-mix(in_srgb,var(--color-accent)_60%,black)] transition-opacity hover:opacity-90 disabled:opacity-50 dark:bg-accent-dark"
                >{{ match (true) {
                    $open->isWaiting() && $open->started_by_id === $friend->id => 'Join '.$friend->name,
                    $open->isWaiting() => 'Waiting for '.$friend->name,
                    default => 'Continue',
                } }}</button>
            @elseif ($pair['shared'] !== [])
                <button type="button" x-on:click="sheet = true" class="block w-full cursor-pointer rounded-2xl bg-accent px-4 py-3 text-base font-extrabold text-white shadow-[0_4px_0_color-mix(in_srgb,var(--color-accent)_60%,black)] transition-opacity hover:opacity-90 dark:bg-accent-dark">Practice · 12 min</button>

                <template x-teleport="body">
                    <div x-on:keydown.escape.window="sheet = false">
                        <div x-show="sheet" x-cloak x-transition.opacity x-on:click="sheet = false" class="fixed inset-0 z-[60] bg-ink/40"></div>
                        <div
                            x-show="sheet"
                            x-cloak
                            x-transition:enter="transition duration-200 ease-out motion-reduce:duration-0"
                            x-transition:enter-start="translate-y-full"
                            x-transition:enter-end="translate-y-0"
                            x-transition:leave="transition duration-150 ease-in motion-reduce:duration-0"
                            x-transition:leave-start="translate-y-0"
                            x-transition:leave-end="translate-y-full"
                            role="dialog"
                            aria-modal="true"
                            aria-label="Practice with {{ $friend->name }}"
                            class="fixed inset-x-0 bottom-0 z-[70] mx-auto flex max-h-[85vh] w-full max-w-lg flex-col rounded-t-2xl border border-b-0 border-line bg-surface pb-[env(safe-area-inset-bottom)] shadow-2xl dark:border-line-dark dark:bg-surface-dark"
                        >
                            <div class="mx-auto mt-2 mb-1 h-1 w-10 shrink-0 rounded-full bg-line dark:bg-line-dark" aria-hidden="true"></div>
                            <div class="space-y-4 overflow-y-auto p-5">
                                <p class="font-display text-lg font-bold text-ink dark:text-ink-dark">Practice with {{ $friend->name }}</p>

                                @include('components.pair-practice.partials.day-picker', ['autoLine' => $pair['next'], 'missions' => $pair['missions'], 'keyPrefix' => $friend->id])

                                <button
                                    type="button"
                                    x-bind:disabled="mode === 'choose' && days.length === 0"
                                    x-on:click="$wire.start({{ $friend->id }}, mode === 'auto' ? null : days); sheet = false"
                                    class="block w-full cursor-pointer rounded-full bg-accent px-4 py-3 text-sm font-semibold text-white transition-colors hover:opacity-90 disabled:cursor-default disabled:opacity-40 dark:bg-accent-dark"
                                >Send invite</button>
                                <p class="text-center text-xs text-ink-faint dark:text-ink-faint-dark">Not together? Call on any app.</p>
                            </div>
                        </div>
                    </div>
                </template>
            @else
                <p class="rounded-full border border-dashed border-line px-4 py-2.5 text-center text-xs font-semibold text-ink-faint dark:border-line-dark dark:text-ink-faint-dark">Finish a day together first</p>
            @endif
        </div>
    @empty
        <div class="card p-6 text-center">
            <p class="text-sm text-ink-soft dark:text-ink-soft-dark">Add a friend first — you both need to follow each other.</p>
            <a href="{{ route('friends.index') }}" wire:navigate class="mt-3 inline-block text-sm font-semibold text-accent-ink dark:text-accent-ink-dark">Find friends ›</a>
        </div>
    @endforelse

    @if ($this->allFriends->count() > self::CARDS_SHOWN)
        <div x-data="{ allFriends: false }">
            <button type="button" x-on:click="allFriends = true" class="w-full cursor-pointer rounded-2xl bg-surface py-3 text-center text-sm font-bold text-accent-ink shadow-sm dark:bg-surface-dark dark:text-accent-ink-dark">All friends ({{ $this->allFriends->count() }})</button>
            <x-friend-sheet show="allFriends" label="All friends" :friends="$this->allFriends" :action-for="fn ($friend) => 'pickFriend('.$friend->id.')'" />
        </div>
    @endif
</div>
