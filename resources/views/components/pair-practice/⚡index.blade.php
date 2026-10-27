<?php

use App\Models\PairPracticeSession;
use App\Models\User;
use App\Notifications\PairPracticeInvite;
use App\Services\PairPractice;
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

    /**
     * One row per friend: what a round would practise, every day either
     * of you finished (for picking days) and how the pair is doing.
     *
     * @return list<array{friend: User, unfinished: bool, streak: int, next: ?string, shared: list<string>, missions: list<array{code: string, days: list<array{key: string, day: int, shared: bool}>}>, nudged: bool, open: ?PairPracticeSession}>
     */
    #[Computed]
    public function pairs(): array
    {
        $me = auth()->user();
        $pairPractice = app(PairPractice::class);
        $mine = $pairPractice->learnedDays($me);

        return $me->mutualFriends()->sortBy('name')->map(function (User $friend) use ($me, $pairPractice, $mine) {
            $theirs = $pairPractice->learnedDays($friend);
            $shared = $mine->intersectByKeys($theirs);
            $latest = $shared->keys()->last();

            return [
                'friend' => $friend,
                'unfinished' => ! $pairPractice->todayProgress($friend)['finished'],
                'streak' => $pairPractice->pairStreak($me, $friend),
                'next' => $latest ? PairPracticeSession::describeDays([$latest]).($shared->count() > 1 ? ' + review' : '') : null,
                'shared' => $shared->keys()->values()->all(),
                'missions' => $mine->union($theirs)
                    ->sortByDesc(fn (array $day) => $day['mission']->number() * 10 + $day['day'])
                    ->groupBy(fn (array $day) => $day['mission']->code)
                    ->map(fn ($days, string $code) => [
                        'code' => $code,
                        'days' => $days->sortBy('day')->map(fn (array $day) => [
                            'key' => PairPractice::dayKey($day['mission'], $day['day']),
                            'day' => $day['day'],
                            'shared' => $shared->has(PairPractice::dayKey($day['mission'], $day['day'])),
                        ])->values()->all(),
                    ])
                    ->values()
                    ->all(),
                'nudged' => Cache::has(PairPractice::nudgeKey($me, $friend)),
                'open' => PairPracticeSession::forPair($me, $friend)->open()->latest()->first(),
            ];
        })->values()->all();
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
     * Picks up the pair's open round (or joins the friend's invite), or
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

        if ($session) {
            $this->redirectRoute('pair-practice.show', $session, navigate: true);
        }
    }
};
?>

<div class="stagger-children mx-auto max-w-2xl space-y-4 p-4 sm:p-6">
    <a href="{{ route('friends.index') }}" wire:navigate class="inline-flex cursor-pointer items-center gap-1 rounded-full border border-line px-3 py-1.5 text-xs leading-none font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">
        @svg('heroicon-o-chevron-left', 'h-3.5 w-3.5')
        Friends
    </a>

    <header>
        <h1 class="font-display text-2xl font-extrabold text-ink dark:text-ink-dark">Practice together</h1>
        <p class="mt-0.5 text-sm text-ink-soft dark:text-ink-soft-dark">
            {{ $day ? PairPracticeSession::describeDays([$day]).' · pick a friend' : 'Each on your own phone · 12 min' }}
        </p>
    </header>

    @forelse ($this->pairs as $pair)
        @php
            $friend = $pair['friend'];
            $preset = $day !== null && in_array($day, $pair['shared'], true);
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
            class="card space-y-3 p-4"
        >
            <div class="flex items-center gap-3">
                <x-user-avatar :user="$friend" class="h-11 w-11 text-sm" />
                <p class="min-w-0 flex-1 truncate font-semibold text-ink dark:text-ink-dark">{{ $friend->name }}</p>
                @if ($pair['streak'] > 0)
                    <span class="inline-flex items-center gap-1 text-sm font-bold text-accent-ink dark:text-accent-ink-dark" title="Days in a row you both finished">
                        @svg('heroicon-s-fire', 'h-4 w-4') {{ $pair['streak'] }}
                    </span>
                @endif
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

            @if ($pair['next'])
                <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Next: <span class="font-semibold text-ink-soft dark:text-ink-soft-dark">{{ $pair['next'] }}</span></p>
            @endif

            @if ($open = $pair['open'])
                <button
                    type="button"
                    wire:click="start({{ $friend->id }})"
                    wire:loading.attr="disabled"
                    class="block w-full cursor-pointer truncate rounded-full bg-accent px-4 py-3 text-sm font-semibold text-white transition-colors hover:opacity-90 disabled:opacity-50 dark:bg-accent-dark"
                >{{ match (true) {
                    $open->isWaiting() && $open->started_by_id === $friend->id => 'Join '.$friend->name,
                    $open->isWaiting() => 'Waiting for '.$friend->name,
                    default => 'Continue',
                } }}</button>
            @elseif ($pair['shared'] !== [])
                <button type="button" x-on:click="sheet = true" class="block w-full cursor-pointer rounded-full bg-accent px-4 py-3 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark">Practice · 12 min</button>

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

                                <div class="space-y-2">
                                    <label class="flex cursor-pointer items-center gap-3 rounded-xl border p-3" x-bind:class="mode === 'auto' ? 'border-accent bg-accent-soft/40 dark:border-accent-dark dark:bg-accent-soft-dark/40' : 'border-line dark:border-line-dark'">
                                        <input type="radio" value="auto" x-model="mode" class="accent-accent">
                                        <span class="min-w-0">
                                            <span class="block text-sm font-semibold text-ink dark:text-ink-dark">Latest (auto)</span>
                                            <span class="block truncate text-xs text-ink-faint dark:text-ink-faint-dark">{{ $pair['next'] }}</span>
                                        </span>
                                    </label>
                                    <label class="flex cursor-pointer items-center gap-3 rounded-xl border p-3" x-bind:class="mode === 'choose' ? 'border-accent bg-accent-soft/40 dark:border-accent-dark dark:bg-accent-soft-dark/40' : 'border-line dark:border-line-dark'">
                                        <input type="radio" value="choose" x-model="mode" class="accent-accent">
                                        <span class="text-sm font-semibold text-ink dark:text-ink-dark">Choose days</span>
                                    </label>
                                </div>

                                <div x-show="mode === 'choose'" x-cloak class="space-y-3">
                                    @foreach ($pair['missions'] as $mission)
                                        @php $sharedKeys = collect($mission['days'])->where('shared', true)->pluck('key')->values()->all(); @endphp
                                        <div wire:key="mission-{{ $friend->id }}-{{ $mission['code'] }}" class="flex flex-wrap items-center gap-2">
                                            <span class="w-10 shrink-0 text-xs font-bold text-ink-soft dark:text-ink-soft-dark">{{ $mission['code'] }}</span>
                                            @foreach ($mission['days'] as $missionDay)
                                                @if ($missionDay['shared'])
                                                    <button
                                                        type="button"
                                                        x-on:click="toggle(@js($missionDay['key']))"
                                                        x-bind:class="days.includes(@js($missionDay['key'])) ? 'border-accent bg-accent text-white dark:border-accent-dark dark:bg-accent-dark' : 'border-line text-ink-soft dark:border-line-dark dark:text-ink-soft-dark'"
                                                        class="h-9 min-w-9 cursor-pointer rounded-full border px-3 text-sm font-semibold transition-colors"
                                                    >{{ $missionDay['day'] }}</button>
                                                @else
                                                    <span class="inline-flex h-9 items-center gap-0.5 rounded-full border border-dashed border-line px-2.5 text-sm text-ink-faint dark:border-line-dark dark:text-ink-faint-dark" title="Not finished by both of you yet">{{ $missionDay['day'] }} @svg('heroicon-o-lock-closed', 'h-3 w-3')</span>
                                                @endif
                                            @endforeach
                                            @if (count($sharedKeys) > 1)
                                                <button type="button" x-on:click="toggleAll(@js($sharedKeys))" class="cursor-pointer px-1 text-xs font-semibold text-accent-ink dark:text-accent-ink-dark">All</button>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>

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
</div>
