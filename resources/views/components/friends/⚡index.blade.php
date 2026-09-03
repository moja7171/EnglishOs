<?php

use App\Models\FriendBlock;
use App\Models\FriendReport;
use App\Models\User;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $search = '';

    #[Computed]
    public function friendsActiveTodayCount(): int
    {
        return auth()->user()->mutualFriendsActiveTodayCount();
    }

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

    public function acceptRequest(int $userId): void
    {
        $requester = User::findOrFail($userId);

        auth()->user()->acceptFollowRequest($requester);
        unset($this->following, $this->followers, $this->pendingRequests, $this->searchResults);
    }

    public function rejectRequest(int $userId): void
    {
        $requester = User::findOrFail($userId);

        auth()->user()->rejectFollowRequest($requester);
        unset($this->pendingRequests, $this->searchResults);
    }

    public function block(int $userId): void
    {
        $target = User::findOrFail($userId);

        FriendBlock::firstOrCreate([
            'blocker_id' => auth()->id(),
            'blocked_id' => $target->id,
        ]);

        unset($this->following, $this->followers, $this->pendingRequests, $this->searchResults);
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
            'missionsCompleted' => $user->missionsCompletedCount(),
        ];
    }

    /**
     * Every user with a block relationship with the current user, either
     * direction — kept out of Following/Followers/search alike, so a
     * block quietly removes both sides from each other's view everywhere
     * in this component, not just from starting a new conversation.
     *
     * @return Collection<int, int>
     */
    private function blockedUserIds()
    {
        return FriendBlock::where('blocker_id', auth()->id())->pluck('blocked_id')
            ->merge(FriendBlock::where('blocked_id', auth()->id())->pluck('blocker_id'));
    }

    #[Computed]
    public function pendingRequests()
    {
        $blocked = $this->blockedUserIds();

        return auth()->user()->pendingFollowRequests()
            ->reject(fn (User $requester) => $blocked->contains($requester->id))
            ->values();
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
            ->where('discoverable', true)
            // ilike, not like — Postgres' LIKE is case-sensitive by default.
            ->where('name', 'ilike', "%{$term}%")
            ->orderBy('name')
            ->limit(20)
            ->get();
    }
};
?>

<div class="mx-auto max-w-2xl space-y-6 p-4 sm:p-6">
    <a href="{{ route('home') }}" class="inline-flex items-center gap-1 text-xs font-semibold text-ink-faint transition-colors hover:text-ink dark:text-ink-faint-dark dark:hover:text-ink-dark">
        @svg('heroicon-o-chevron-left', 'h-3.5 w-3.5')
        All missions
    </a>

    <header class="flex items-center gap-3 rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
        <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">
            @svg('heroicon-s-user-group', 'h-5 w-5')
        </span>
        <div>
            <h1 class="font-display text-2xl font-extrabold text-ink dark:text-ink-dark">Friends</h1>
            <p class="mt-0.5 text-sm text-ink-soft dark:text-ink-soft-dark">Send a follow request, accept the ones you get, and message anyone who follows you back.</p>
        </div>
    </header>

    @if ($this->friendsActiveTodayCount)
        <p class="flex items-center gap-1.5 text-xs text-ink-faint dark:text-ink-faint-dark">
            @svg('heroicon-s-fire', 'h-3.5 w-3.5 text-accent-ink dark:text-accent-ink-dark')
            {{ $this->friendsActiveTodayCount }} {{ Str::plural('friend', $this->friendsActiveTodayCount) }} already practiced today
        </p>
    @endif

    <div>
        <div class="relative">
            <span class="pointer-events-none absolute inset-y-0 left-4 flex items-center text-ink-faint dark:text-ink-faint-dark">
                @svg('heroicon-o-magnifying-glass', 'h-4 w-4')
            </span>
            <input
                type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="Search by name…"
                class="w-full rounded-full border border-line bg-surface py-2.5 pr-4 pl-10 text-sm text-ink shadow-sm transition-colors focus:border-accent focus:outline-none dark:border-line-dark dark:bg-surface-dark dark:text-ink-dark dark:focus:border-accent-dark"
            >
        </div>

        @if (trim($search) !== '')
            <div class="mt-3 space-y-2">
                @forelse ($this->searchResults as $user)
                    <div class="flex items-center gap-3 rounded-2xl border border-line bg-surface px-3.5 py-2.5 shadow-sm dark:border-line-dark dark:bg-surface-dark">
                        <x-user-avatar :user="$user" class="h-9 w-9 text-xs" />
                        <span class="flex-1 truncate text-sm font-semibold text-ink dark:text-ink-dark">{{ $user->name }}</span>
                        @if (auth()->user()->isFollowing($user))
                            <button
                                type="button"
                                wire:click="unfollow({{ $user->id }})"
                                class="shrink-0 cursor-pointer rounded-full border border-line px-3 py-1 text-xs font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                            >Following</button>
                        @elseif (auth()->user()->hasPendingRequestTo($user))
                            <button
                                type="button"
                                wire:click="unfollow({{ $user->id }})"
                                title="Cancel request"
                                class="shrink-0 cursor-pointer rounded-full border border-dashed border-line px-3 py-1 text-xs font-semibold text-ink-faint transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark"
                            >Requested</button>
                        @else
                            <button
                                type="button"
                                wire:click="follow({{ $user->id }})"
                                class="shrink-0 cursor-pointer rounded-full bg-accent px-3 py-1 text-xs font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
                            >Follow</button>
                        @endif
                    </div>
                @empty
                    <div class="flex flex-col items-center gap-2 rounded-2xl border border-dashed border-line py-6 text-center dark:border-line-dark">
                        @svg('heroicon-o-face-frown', 'h-6 w-6 text-ink-faint/60 dark:text-ink-faint-dark/60')
                        <p class="text-sm text-ink-faint dark:text-ink-faint-dark">No one found with that name.</p>
                    </div>
                @endforelse
            </div>
        @endif
    </div>

    @if ($this->pendingRequests->count())
        <div class="rounded-2xl border border-accent/30 bg-accent-soft/40 p-3.5 dark:border-accent-dark/30 dark:bg-accent-soft-dark/20">
            <p class="text-xs font-semibold tracking-wide text-accent-ink uppercase dark:text-accent-ink-dark">Follow requests ({{ $this->pendingRequests->count() }})</p>
            <div class="mt-2 space-y-2">
                @foreach ($this->pendingRequests as $requester)
                    <div class="flex items-center gap-3 rounded-xl bg-surface px-3 py-2 shadow-sm dark:bg-surface-dark">
                        <x-user-avatar :user="$requester" class="h-9 w-9 text-xs" />
                        <span class="flex-1 truncate text-sm font-semibold text-ink dark:text-ink-dark">{{ $requester->name }}</span>
                        <button
                            type="button"
                            wire:click="acceptRequest({{ $requester->id }})"
                            title="Accept"
                            class="inline-flex h-8 w-8 cursor-pointer items-center justify-center rounded-full bg-accent text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
                        >@svg('heroicon-o-check', 'h-4 w-4')</button>
                        <button
                            type="button"
                            wire:click="rejectRequest({{ $requester->id }})"
                            title="Reject"
                            class="inline-flex h-8 w-8 cursor-pointer items-center justify-center rounded-full border border-line text-ink-faint transition-colors hover:bg-surface-sunken dark:border-line-dark dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark"
                        >@svg('heroicon-o-x-mark', 'h-4 w-4')</button>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <div>
        <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Following ({{ $this->following->count() }})</p>
        <div class="mt-2 space-y-2.5">
            @forelse ($this->following as $friend)
                @php $stats = $this->stats($friend); $mutual = auth()->user()->isMutualWith($friend); @endphp
                <div class="rounded-2xl border border-line bg-surface p-3.5 shadow-sm transition-shadow hover:shadow-md dark:border-line-dark dark:bg-surface-dark">
                    <div class="flex items-center gap-3">
                        <x-user-avatar :user="$friend" class="h-11 w-11 text-sm" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-ink dark:text-ink-dark">{{ $friend->name }}</p>
                            <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                @if ($stats['streak'] > 0)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-accent-soft px-2 py-0.5 text-[11px] font-semibold text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">
                                        @svg('heroicon-s-fire', 'h-3 w-3')
                                        {{ $stats['streak'] }}-day streak
                                    </span>
                                @endif
                                <span class="inline-flex items-center gap-1 rounded-full bg-surface-sunken px-2 py-0.5 text-[11px] font-medium text-ink-faint dark:bg-surface-sunken-dark dark:text-ink-faint-dark">
                                    @svg('heroicon-o-check-badge', 'h-3 w-3')
                                    {{ $stats['missionsCompleted'] }} {{ Str::plural('mission', $stats['missionsCompleted']) }}
                                </span>
                            </div>
                        </div>
                        <div class="flex shrink-0 items-center gap-1.5">
                            @if ($mutual)
                                <a
                                    href="{{ route('friends.conversation', $friend) }}"
                                    wire:navigate
                                    title="Message"
                                    class="inline-flex h-9 w-9 cursor-pointer items-center justify-center rounded-full bg-ink text-ground transition-colors hover:opacity-85 dark:bg-ink-dark dark:text-ground-dark"
                                >@svg('heroicon-o-chat-bubble-left-right', 'h-4 w-4')</a>
                            @endif
                            <button
                                type="button"
                                wire:click="unfollow({{ $friend->id }})"
                                class="cursor-pointer rounded-full border border-line px-3 py-1.5 text-xs font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                            >Unfollow</button>
                        </div>
                    </div>

                    @if (! $mutual)
                        <p class="mt-2.5 flex items-center gap-1 text-xs text-ink-faint dark:text-ink-faint-dark">
                            @svg('heroicon-o-lock-closed', 'h-3.5 w-3.5')
                            They don't follow you back yet — messaging unlocks once they do.
                        </p>
                    @endif

                    @if (! isset($reporting[$friend->id]))
                        <div class="mt-2.5 flex items-center gap-1 border-t border-line pt-2.5 dark:border-line-dark">
                            <button
                                type="button"
                                wire:click="block({{ $friend->id }})"
                                wire:confirm="Block {{ $friend->name }}? They won't be able to message you, and you won't see each other's activity."
                                title="Block"
                                class="inline-flex h-7 w-7 cursor-pointer items-center justify-center rounded-full text-ink-faint transition-colors hover:bg-red-100 hover:text-red-600 dark:text-ink-faint-dark dark:hover:bg-red-950"
                            >@svg('heroicon-o-no-symbol', 'h-3.5 w-3.5')</button>
                            <button
                                type="button"
                                wire:click="startReport({{ $friend->id }})"
                                title="Report"
                                class="inline-flex h-7 w-7 cursor-pointer items-center justify-center rounded-full text-ink-faint transition-colors hover:bg-surface-sunken hover:text-ink dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark dark:hover:text-ink-dark"
                            >@svg('heroicon-o-flag', 'h-3.5 w-3.5')</button>
                        </div>
                    @else
                        <div class="mt-2.5 space-y-2 rounded-xl border border-red-200 bg-red-50 p-3 dark:border-red-900 dark:bg-red-950">
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
                <div class="flex flex-col items-center gap-2 rounded-2xl border border-dashed border-line py-8 text-center dark:border-line-dark">
                    @svg('heroicon-o-user-plus', 'h-6 w-6 text-ink-faint/60 dark:text-ink-faint-dark/60')
                    <p class="text-sm text-ink-faint dark:text-ink-faint-dark">Search above to follow your first classmate.</p>
                </div>
            @endforelse
        </div>
    </div>

    @if ($this->followers->count())
        <div x-data="{ showFollowers: false }">
            <button
                type="button"
                x-on:click="showFollowers = !showFollowers"
                class="flex w-full cursor-pointer items-center justify-between gap-2 text-xs font-semibold tracking-wide text-ink-faint uppercase transition-colors hover:text-ink dark:text-ink-faint-dark dark:hover:text-ink-dark"
            >
                <span>Followers ({{ $this->followers->count() }})</span>
                <span class="transition-transform" :class="showFollowers ? 'rotate-180' : ''">
                    @svg('heroicon-o-chevron-down', 'h-3.5 w-3.5')
                </span>
            </button>

            <div x-show="showFollowers" x-cloak x-transition.opacity.duration.150ms class="mt-2 flex flex-wrap gap-2">
                @foreach ($this->followers as $follower)
                    <span class="inline-flex items-center gap-2 rounded-full border border-line bg-surface py-1 pr-3 pl-1 text-xs text-ink-soft shadow-sm dark:border-line-dark dark:bg-surface-dark dark:text-ink-soft-dark">
                        <x-user-avatar :user="$follower" class="h-5 w-5 text-[10px]" />
                        {{ $follower->name }}
                        @if (! auth()->user()->isFollowing($follower))
                            @if (auth()->user()->hasPendingRequestTo($follower))
                                <span class="font-semibold text-ink-faint dark:text-ink-faint-dark">Requested</span>
                            @else
                                <button type="button" wire:click="follow({{ $follower->id }})" class="cursor-pointer font-semibold text-accent-ink hover:opacity-80 dark:text-accent-ink-dark">Follow back</button>
                            @endif
                        @endif
                    </span>
                @endforeach
            </div>
        </div>
    @endif
</div>
