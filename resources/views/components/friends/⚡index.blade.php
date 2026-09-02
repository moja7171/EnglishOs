<?php

use App\Models\FriendBlock;
use App\Models\FriendReport;
use App\Models\MissionRun;
use App\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $search = '';

    /** @var array<int, string> keyed by user id — shows the report textarea for that row */
    public array $reporting = [];

    /** @var array<int, string> keyed by user id — the reason typed for that row */
    public array $reportReason = [];

    public function follow(int $userId): void
    {
        $target = User::findOrFail($userId);

        auth()->user()->follow($target);
        unset($this->following, $this->searchResults);
    }

    public function unfollow(int $userId): void
    {
        $target = User::findOrFail($userId);

        auth()->user()->unfollow($target);
        unset($this->following, $this->searchResults);
    }

    public function block(int $userId): void
    {
        $target = User::findOrFail($userId);

        FriendBlock::firstOrCreate([
            'blocker_id' => auth()->id(),
            'blocked_id' => $target->id,
        ]);

        unset($this->following, $this->followers, $this->searchResults);
    }

    public function startReport(int $userId): void
    {
        $this->reporting[$userId] = true;
    }

    public function cancelReport(int $userId): void
    {
        unset($this->reporting[$userId], $this->reportReason[$userId]);
    }

    public function submitReport(int $userId): void
    {
        $reason = trim($this->reportReason[$userId] ?? '');

        if ($reason === '') {
            return;
        }

        FriendReport::create([
            'reporter_id' => auth()->id(),
            'reported_id' => $userId,
            'reason' => $reason,
        ]);

        unset($this->reporting[$userId], $this->reportReason[$userId]);
    }

    /**
     * High-level stats only — streak and missions completed, never the
     * raw Evidence content (recordings, written text, error log) a
     * follower has no business seeing. See User::currentStreak() and
     * EOS-009 §8's "دوستان" catalog entry once this is documented.
     *
     * @return array{streak: int, missionsCompleted: int}
     */
    public function stats(User $user): array
    {
        return [
            'streak' => $user->currentStreak(),
            'missionsCompleted' => $user->missionRuns()->where('status', MissionRun::STATUS_COMPLETE)->count(),
        ];
    }

    /**
     * Every user with a block relationship with the current user, either
     * direction — kept out of Following/Followers/search alike, so a
     * block quietly removes both sides from each other's view everywhere
     * in this component, not just from starting a new conversation.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function blockedUserIds()
    {
        return FriendBlock::where('blocker_id', auth()->id())->pluck('blocked_id')
            ->merge(FriendBlock::where('blocked_id', auth()->id())->pluck('blocker_id'));
    }

    #[Computed]
    public function following()
    {
        return auth()->user()->following()
            ->whereNotIn('users.id', $this->blockedUserIds())
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function followers()
    {
        return auth()->user()->followers()
            ->whereNotIn('users.id', $this->blockedUserIds())
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function searchResults()
    {
        $term = trim($this->search);

        if ($term === '') {
            return collect();
        }

        return User::query()
            ->where('id', '!=', auth()->id())
            ->whereNotIn('id', $this->blockedUserIds())
            // ilike, not like — Postgres' LIKE is case-sensitive by default.
            ->where('name', 'ilike', "%{$term}%")
            ->orderBy('name')
            ->limit(20)
            ->get();
    }
};
?>

<div class="mx-auto max-w-2xl space-y-6 p-6">
    <a href="{{ route('home') }}" class="inline-flex items-center gap-1 text-xs font-semibold text-ink-faint transition-colors hover:text-ink dark:text-ink-faint-dark dark:hover:text-ink-dark">
        @svg('heroicon-o-chevron-left', 'h-3.5 w-3.5')
        All missions
    </a>

    <header>
        <p class="inline-flex items-center gap-1.5 text-xs font-bold tracking-widest text-ink-faint uppercase dark:text-ink-faint-dark">
            <span class="h-1.5 w-1.5 rounded-full bg-accent dark:bg-accent-dark"></span>
            English OS
        </p>
        <h1 class="font-display text-2xl font-extrabold text-ink dark:text-ink-dark">Friends</h1>
        <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">Follow classmates, cheer each other on, and message anyone who follows you back.</p>
    </header>

    <div>
        <input
            type="text"
            wire:model.live.debounce.300ms="search"
            placeholder="Search by name…"
            class="w-full rounded-full border border-line bg-transparent px-4 py-2 text-sm text-ink dark:border-line-dark dark:text-ink-dark"
        >

        @if (trim($search) !== '')
            <div class="mt-3 space-y-2">
                @forelse ($this->searchResults as $user)
                    <div class="flex items-center justify-between gap-3 rounded-xl border border-line px-3.5 py-2.5 dark:border-line-dark">
                        <span class="text-sm font-semibold text-ink dark:text-ink-dark">{{ $user->name }}</span>
                        @if (auth()->user()->isFollowing($user))
                            <button
                                type="button"
                                wire:click="unfollow({{ $user->id }})"
                                class="cursor-pointer rounded-full border border-line px-3 py-1 text-xs font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                            >Following</button>
                        @else
                            <button
                                type="button"
                                wire:click="follow({{ $user->id }})"
                                class="cursor-pointer rounded-full bg-accent px-3 py-1 text-xs font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
                            >Follow</button>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-ink-faint dark:text-ink-faint-dark">No one found with that name.</p>
                @endforelse
            </div>
        @endif
    </div>

    <div>
        <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Following ({{ $this->following->count() }})</p>
        <div class="mt-2 space-y-2">
            @forelse ($this->following as $friend)
                @php $stats = $this->stats($friend); $mutual = auth()->user()->isMutualWith($friend); @endphp
                <div class="rounded-xl border border-line p-3 dark:border-line-dark">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-sm font-semibold text-ink dark:text-ink-dark">{{ $friend->name }}</p>
                            <p class="mt-0.5 flex items-center gap-3 text-xs text-ink-faint dark:text-ink-faint-dark">
                                @if ($stats['streak'] > 0)
                                    <span class="inline-flex items-center gap-1 font-semibold text-accent-ink dark:text-accent-ink-dark">
                                        @svg('heroicon-s-fire', 'h-3.5 w-3.5')
                                        {{ $stats['streak'] }}
                                    </span>
                                @endif
                                <span>{{ $stats['missionsCompleted'] }} {{ Str::plural('mission', $stats['missionsCompleted']) }} completed</span>
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            @if ($mutual)
                                <a
                                    href="{{ route('friends.conversation', $friend) }}"
                                    wire:navigate
                                    class="inline-flex cursor-pointer items-center gap-1 rounded-full bg-ink px-3 py-1.5 text-xs font-semibold text-ground transition-colors hover:opacity-85 dark:bg-ink-dark dark:text-ground-dark"
                                >@svg('heroicon-o-chat-bubble-left-right', 'h-3.5 w-3.5') Message</a>
                            @endif
                            <button
                                type="button"
                                wire:click="unfollow({{ $friend->id }})"
                                class="cursor-pointer rounded-full border border-line px-3 py-1.5 text-xs font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                            >Unfollow</button>
                        </div>
                    </div>

                    @if (! $mutual)
                        <p class="mt-2 flex items-center gap-1 text-xs text-ink-faint dark:text-ink-faint-dark">
                            @svg('heroicon-o-lock-closed', 'h-3.5 w-3.5')
                            They don't follow you back yet — messaging unlocks once they do.
                        </p>
                    @endif

                    <div class="mt-2 flex items-center gap-3">
                        @if (! isset($reporting[$friend->id]))
                            <button
                                type="button"
                                wire:click="block({{ $friend->id }})"
                                wire:confirm="Block {{ $friend->name }}? They won't be able to message you, and you won't see each other's activity."
                                class="cursor-pointer text-xs text-ink-faint underline decoration-dotted underline-offset-2 hover:text-red-600 dark:text-ink-faint-dark"
                            >Block</button>
                            <button
                                type="button"
                                wire:click="startReport({{ $friend->id }})"
                                class="cursor-pointer text-xs text-ink-faint underline decoration-dotted underline-offset-2 hover:text-red-600 dark:text-ink-faint-dark"
                            >Report</button>
                        @endif
                    </div>

                    @if (isset($reporting[$friend->id]))
                        <div class="mt-2 space-y-2 rounded-xl border border-red-200 bg-red-50 p-3 dark:border-red-900 dark:bg-red-950">
                            <textarea
                                wire:model="reportReason.{{ $friend->id }}"
                                rows="2"
                                placeholder="What happened?"
                                class="w-full rounded-lg border border-red-300 bg-transparent px-2 py-1 text-sm text-ink dark:border-red-800 dark:text-ink-dark"
                            ></textarea>
                            <div class="flex gap-2">
                                <button
                                    type="button"
                                    wire:click="submitReport({{ $friend->id }})"
                                    class="cursor-pointer rounded-full border border-red-300 px-3 py-1 text-xs font-semibold text-red-600 transition-colors hover:bg-red-100 dark:border-red-800 dark:hover:bg-red-950"
                                >Submit report</button>
                                <button
                                    type="button"
                                    wire:click="cancelReport({{ $friend->id }})"
                                    class="cursor-pointer text-xs text-ink-faint underline dark:text-ink-faint-dark"
                                >Cancel</button>
                            </div>
                        </div>
                    @endif
                </div>
            @empty
                <p class="text-sm text-ink-faint dark:text-ink-faint-dark">Search above to follow your first classmate.</p>
            @endforelse
        </div>
    </div>

    @if ($this->followers->count())
        <div>
            <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Followers ({{ $this->followers->count() }})</p>
            <div class="mt-2 flex flex-wrap gap-1.5">
                @foreach ($this->followers as $follower)
                    <span class="inline-flex items-center gap-1.5 rounded-full border border-line px-2.5 py-1 text-xs text-ink-soft dark:border-line-dark dark:text-ink-soft-dark">
                        {{ $follower->name }}
                        @if (! auth()->user()->isFollowing($follower))
                            <button type="button" wire:click="follow({{ $follower->id }})" class="cursor-pointer font-semibold text-accent-ink hover:opacity-80 dark:text-accent-ink-dark">Follow back</button>
                        @endif
                    </span>
                @endforeach
            </div>
        </div>
    @endif
</div>
