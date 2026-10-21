<?php

use App\Models\PairPracticeSession;
use App\Models\User;
use App\Notifications\PairPracticeNudge;
use App\Services\PairPractice;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    /**
     * One row per friend: their progress today, what the two of you can
     * practise and how the pair has been doing.
     *
     * @return list<array{friend: User, today: array{done: int, total: int, finished: bool}, streak: int, sharedDays: int, lockedDay: ?array, nudged: bool, sessions: int, last: ?PairPracticeSession}>
     */
    #[Computed]
    public function pairs(): array
    {
        $me = auth()->user();
        $pairPractice = app(PairPractice::class);

        return $me->mutualFriends()->sortBy('name')->map(function (User $friend) use ($me, $pairPractice) {
            $finished = PairPracticeSession::forPair($me, $friend)->whereNotNull('finished_at');

            return [
                'friend' => $friend,
                'today' => $pairPractice->todayProgress($friend),
                'streak' => $pairPractice->pairStreak($me, $friend),
                'sharedDays' => $pairPractice->sharedDays($me, $friend)->count(),
                'lockedDay' => $pairPractice->lockedDay($me, $friend),
                'nudged' => Cache::has($this->nudgeKey($friend)),
                'sessions' => (clone $finished)->count(),
                'last' => $finished->latest('finished_at')->first(),
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

        if (Cache::add($this->nudgeKey($friend), true, now()->endOfDay())) {
            $friend->notify(new PairPracticeNudge(auth()->user()));
        }

        unset($this->pairs);
    }

    /**
     * Picks up today's unfinished round of this pair, or draws a new one.
     */
    public function start(int $friendId): void
    {
        $friend = User::findOrFail($friendId);

        abort_unless(auth()->user()->canMessageWith($friend), 403);

        $session = PairPracticeSession::forPair(auth()->user(), $friend)
            ->whereNull('finished_at')
            ->where('created_at', '>=', now()->subHours(2))
            ->latest()
            ->first()
            ?? app(PairPractice::class)->start(auth()->user(), $friend);

        if ($session) {
            $this->redirectRoute('pair-practice.show', $session, navigate: true);
        }
    }

    private function nudgeKey(User $friend): string
    {
        return 'pair-nudge:'.auth()->id().':'.$friend->id.':'.now()->toDateString();
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
        <p class="mt-0.5 text-sm text-ink-soft dark:text-ink-soft-dark">One phone, two people, 12 minutes.</p>
    </header>

    @forelse ($this->pairs as $pair)
        @php $friend = $pair['friend']; @endphp
        <div wire:key="pair-{{ $friend->id }}" class="card space-y-3 p-4">
            <div class="flex items-center gap-3">
                <x-user-avatar :user="$friend" class="h-10 w-10 text-sm" />
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-ink dark:text-ink-dark">{{ $friend->name }}</p>
                    <p class="text-xs text-ink-faint dark:text-ink-faint-dark">
                        @if ($pair['today']['finished'])
                            Today done ✓
                        @elseif ($pair['today']['total'] > 0)
                            Today: {{ $pair['today']['done'] }} of {{ $pair['today']['total'] }} steps
                        @else
                            No day open today
                        @endif
                    </p>
                </div>
                @if ($pair['streak'] > 0)
                    <span class="inline-flex items-center gap-1 text-sm font-bold text-accent-ink dark:text-accent-ink-dark" title="Days in a row you both finished">
                        @svg('heroicon-s-fire', 'h-4 w-4') {{ $pair['streak'] }}
                    </span>
                @endif
            </div>

            @if ($pair['last'])
                <p class="text-xs text-ink-faint dark:text-ink-faint-dark">
                    {{ $pair['sessions'] }} {{ Str::plural('session', $pair['sessions']) }} · last: you {{ $pair['last']->scoreFor(auth()->user()) }} – {{ $pair['last']->scoreFor($friend) }} {{ $friend->name }}
                </p>
            @endif

            @if ($pair['lockedDay'])
                <p class="flex items-center gap-1.5 text-xs text-ink-faint dark:text-ink-faint-dark">
                    @svg('heroicon-o-lock-closed', 'h-3.5 w-3.5 shrink-0')
                    {{ $pair['lockedDay']['mission']->code }} · Day {{ $pair['lockedDay']['day'] }} opens once
                    {{ $pair['lockedDay']['waitingFor']->is(auth()->user()) ? 'you finish' : $pair['lockedDay']['waitingFor']->name.' finishes' }} it.
                </p>
            @endif

            <div class="flex gap-2">
                @if ($pair['sharedDays'] > 0)
                    <button
                        type="button"
                        wire:click="start({{ $friend->id }})"
                        wire:loading.attr="disabled"
                        wire:target="start({{ $friend->id }})"
                        class="flex-1 cursor-pointer rounded-full bg-accent px-4 py-2 text-sm font-semibold text-white transition-colors hover:opacity-90 disabled:opacity-50 dark:bg-accent-dark"
                    >Start · 12 min</button>
                @else
                    <span class="flex-1 rounded-full border border-dashed border-line px-4 py-2 text-center text-xs font-semibold text-ink-faint dark:border-line-dark dark:text-ink-faint-dark">Finish a day together first</span>
                @endif

                @unless ($pair['today']['finished'])
                    <button
                        type="button"
                        wire:click="nudge({{ $friend->id }})"
                        @disabled($pair['nudged'])
                        class="shrink-0 cursor-pointer rounded-full border border-line px-4 py-2 text-sm font-semibold text-ink-soft transition-colors hover:bg-surface-sunken disabled:cursor-default disabled:opacity-60 dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                    >{{ $pair['nudged'] ? 'Nudged ✓' : 'Nudge 👋' }}</button>
                @endunless
            </div>
        </div>
    @empty
        <div class="card p-6 text-center">
            <p class="text-sm text-ink-soft dark:text-ink-soft-dark">Add a friend first — you both need to follow each other.</p>
            <a href="{{ route('friends.index') }}" wire:navigate class="mt-3 inline-block text-sm font-semibold text-accent-ink dark:text-accent-ink-dark">Find friends ›</a>
        </div>
    @endforelse
</div>
