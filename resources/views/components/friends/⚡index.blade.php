<?php

use App\Models\DirectMessage;
use App\Models\User;
use App\Services\PairPractice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $search = '';

    public function follow(int $userId): void
    {
        $target = User::findOrFail($userId);

        auth()->user()->follow($target);
        unset($this->following, $this->friends, $this->oneWayFollowing, $this->rows, $this->searchResults, $this->followBack);
    }

    public function unfollow(int $userId): void
    {
        $target = User::findOrFail($userId);

        auth()->user()->unfollow($target);
        unset($this->following, $this->friends, $this->oneWayFollowing, $this->rows, $this->searchResults, $this->followBack);
    }

    public function acceptRequest(int $userId): void
    {
        $requester = User::findOrFail($userId);

        auth()->user()->acceptFollowRequest($requester);
        unset($this->following, $this->friends, $this->oneWayFollowing, $this->rows, $this->followers, $this->pendingRequests, $this->searchResults, $this->followBack);
    }

    public function rejectRequest(int $userId): void
    {
        $requester = User::findOrFail($userId);

        auth()->user()->rejectFollowRequest($requester);
        unset($this->pendingRequests, $this->searchResults);
    }

    /**
     * The 🔥 Nudge on a friend who hasn't practised today (one a day, see
     * PairPractice::nudge()).
     */
    public function nudge(int $friendId): void
    {
        $friend = User::findOrFail($friendId);

        abort_unless(auth()->user()->canMessageWith($friend), 403);

        app(PairPractice::class)->nudge(auth()->user(), $friend);

        unset($this->rows);
    }

    /**
     * Every user with a block relationship with the current user, either
     * direction — kept out of every list and the search alike.
     *
     * @return Collection<int, int>
     */
    private function blockedUserIds()
    {
        return auth()->user()->blockedUserIds();
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

    /**
     * Inbox data (last message + unread per friend), see
     * User::conversationSummaries().
     */
    #[Computed]
    public function summaries()
    {
        return auth()->user()->conversationSummaries();
    }

    /**
     * Mutual friends — the ones you can message — most recent conversation
     * first, then A–Z for people you've never talked to.
     */
    #[Computed]
    public function friends()
    {
        $mutual = auth()->user()->mutualFriendIds();
        $summaries = $this->summaries;

        return $this->following
            ->filter(fn (User $user) => $mutual->contains($user->id))
            ->sort(fn (User $a, User $b) => [$summaries[$b->id]['last']->id ?? 0, strtolower($a->name)] <=> [$summaries[$a->id]['last']->id ?? 0, strtolower($b->name)])
            ->values();
    }

    /**
     * One chat row per friend: the last message, unread count, streak,
     * whether they practised today and where they are (mission · step) —
     * counts and positions only, never what they answered.
     *
     * @return list<array{friend: User, summary: ?array, unread: int, streak: int, active: bool, where: ?string, nudged: bool}>
     */
    #[Computed]
    public function rows(): array
    {
        return $this->friends->map(function (User $friend) {
            $summary = $this->summaries[$friend->id] ?? null;
            $run = $friend->currentInProgressMissionRun();
            $step = $run?->currentStepKey();

            return [
                'friend' => $friend,
                'summary' => $summary,
                'unread' => $summary['unread'] ?? 0,
                'streak' => $friend->currentStreak(),
                'active' => $friend->wasActiveToday(),
                'where' => $run ? $run->mission->code.($step ? ' · '.$run->mission->stepLabel($step) : '') : null,
                'nudged' => Cache::has(PairPractice::nudgeKey(auth()->user(), $friend)),
            ];
        })->all();
    }

    /**
     * People you follow who haven't followed back — no chat yet, so they sit
     * faded under the chats.
     */
    #[Computed]
    public function oneWayFollowing()
    {
        $mutual = auth()->user()->mutualFriendIds();

        return $this->following->reject(fn (User $user) => $mutual->contains($user->id))->values();
    }

    /**
     * One-line inbox preview of the latest message in a thread.
     */
    public function preview(DirectMessage $message): string
    {
        $text = match ($message->type) {
            DirectMessage::TYPE_AUDIO => '🎤 Voice message',
            DirectMessage::TYPE_FILE => $message->attachment_name ?? 'File',
            DirectMessage::TYPE_NUDGE => 'Nudge: '.$message->body,
            default => $message->body,
        };

        return $message->sender_id === auth()->id() ? "You: {$text}" : $text;
    }

    #[Computed]
    public function followers()
    {
        return auth()->user()->followers()
            ->whereNotIn('users.id', $this->blockedUserIds())
            ->orderBy('name')
            ->get();
    }

    /**
     * Followers you don't follow yet — "They follow you" on Add friends.
     */
    #[Computed]
    public function followBack()
    {
        return $this->followers
            ->reject(fn (User $follower) => auth()->user()->isFollowing($follower))
            ->values();
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
            // Plain 'like', not 'ilike' — MySQL's LIKE is already
            // case-insensitive under the app's utf8mb4_unicode_ci
            // collation (unlike Postgres, where LIKE is case-sensitive
            // and ILIKE — a Postgres-only operator MySQL's parser
            // rejects outright — was needed for this same effect).
            ->where('name', 'like', "%{$term}%")
            ->orderBy('name')
            ->limit(20)
            ->get();
    }
};
?>

@php
    $pill = 'shrink-0 cursor-pointer rounded-full px-3 py-1.5 text-xs font-extrabold transition-colors disabled:pointer-events-none disabled:opacity-50';
    $iconButton = 'inline-flex h-10 w-10 shrink-0 cursor-pointer items-center justify-center rounded-full transition-colors';
    $hasAnyone = $this->friends->isNotEmpty() || $this->oneWayFollowing->isNotEmpty() || $this->pendingRequests->isNotEmpty();
    $activeFirst = collect($this->rows)->sortByDesc('active')->values();
@endphp

{{-- Like a messenger: who practised today on top, Practice together, the
     requests, then one row per chat. ➕ swaps the page for Add friends
     (search + "They follow you"); Mute, Unfollow, Block and Report live
     in each chat's ⋯. --}}
<div class="mx-auto max-w-2xl px-4 pt-3 pb-6 sm:px-6" x-data="{ adding: @js(trim($search) !== '') }" x-back-close="{ open: adding, close: () => adding = false }">
    <div x-show="! adding" class="stagger-children space-y-4">
        <header class="flex items-center gap-2">
            <h1 class="flex-1 font-display text-3xl font-extrabold text-ink dark:text-ink-dark">Friends</h1>
            <a href="{{ route('friends.board') }}" wire:navigate aria-label="Friends Board" class="{{ $iconButton }} bg-surface text-ink-soft shadow-sm hover:text-ink dark:bg-surface-dark dark:text-ink-soft-dark">@svg('heroicon-o-trophy', 'h-5 w-5')</a>
            <button type="button" x-on:click="adding = true; $nextTick(() => $refs.search.focus())" aria-label="Add friends" class="{{ $iconButton }} bg-accent text-white hover:opacity-90 dark:bg-accent-dark">@svg('heroicon-o-plus', 'h-5 w-5')</button>
        </header>

        @if (! $hasAnyone)
            <div class="flex flex-col items-center gap-3 px-6 pt-16 text-center">
                <span class="text-5xl" aria-hidden="true">👋</span>
                <p class="text-lg font-extrabold text-ink dark:text-ink-dark">Practice is better with a friend</p>
                <p class="text-sm font-semibold text-ink-faint dark:text-ink-faint-dark">Find a classmate by name.</p>
                <button type="button" x-on:click="adding = true; $nextTick(() => $refs.search.focus())" class="mt-2 w-full cursor-pointer rounded-2xl bg-accent px-5 py-3.5 text-base font-extrabold text-white shadow-sm hover:opacity-90 dark:bg-accent-dark">Add a friend</button>
            </div>
        @endif

        @if ($activeFirst->isNotEmpty())
            {{-- Who practised today: a green ring. --}}
            <div class="-mx-4 flex gap-3 overflow-x-auto px-4 pb-1" aria-label="Today">
                @foreach ($activeFirst as $row)
                    <a href="{{ route('friends.conversation', $row['friend']) }}" wire:navigate wire:key="today-{{ $row['friend']->id }}" class="flex w-14 shrink-0 flex-col items-center gap-1 text-[11px] font-bold text-ink-soft dark:text-ink-soft-dark">
                        <span @class(['relative rounded-full', 'ring-[2.5px] ring-success ring-offset-2 ring-offset-ground dark:ring-success-dark dark:ring-offset-ground-dark' => $row['active']])>
                            <x-user-avatar :user="$row['friend']" class="h-12 w-12 text-sm" />
                            @if ($row['active'])
                                <span class="sr-only">practiced today</span>
                            @endif
                        </span>
                        <span class="w-full truncate text-center">{{ $row['friend']->name }}</span>
                    </a>
                @endforeach
            </div>
        @endif

        @if ($this->friends->isNotEmpty())
            <a href="{{ route('pair-practice.index') }}" wire:navigate class="flex items-center gap-2.5 rounded-2xl bg-accent px-4 py-3 text-sm font-extrabold text-white shadow-sm hover:opacity-90 dark:bg-accent-dark">
                <span aria-hidden="true">👫</span>
                <span class="flex-1">Practice together</span>
                <span class="text-xs font-bold text-white/85">12 min ›</span>
            </a>
        @endif

        @foreach ($this->pendingRequests as $requester)
            <div wire:key="request-{{ $requester->id }}" class="flex items-center gap-3 rounded-2xl bg-surface px-3 py-2.5 shadow-sm dark:bg-surface-dark">
                <x-user-avatar :user="$requester" class="h-10 w-10 text-xs" />
                <p class="min-w-0 flex-1 text-sm font-extrabold text-ink dark:text-ink-dark">
                    <span class="block truncate">{{ $requester->name }}</span>
                    <span class="block text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">wants to be friends</span>
                </p>
                <button type="button" wire:click="acceptRequest({{ $requester->id }})" wire:loading.attr="disabled" aria-label="Accept {{ $requester->name }}" class="{{ $iconButton }} h-9 w-9 bg-success text-white dark:bg-success-dark">@svg('heroicon-o-check', 'h-4 w-4')</button>
                <button type="button" wire:click="rejectRequest({{ $requester->id }})" wire:loading.attr="disabled" aria-label="Reject {{ $requester->name }}" class="{{ $iconButton }} h-9 w-9 bg-surface-sunken text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark">@svg('heroicon-o-x-mark', 'h-4 w-4')</button>
            </div>
        @endforeach

        @if ($this->rows || $this->oneWayFollowing->isNotEmpty())
            <div class="-mx-2">
                @foreach ($this->rows as $row)
                    @php $friend = $row['friend']; @endphp
                    {{-- The whole row opens the chat; only Nudge sits above it. --}}
                    <div wire:key="friend-{{ $friend->id }}" class="relative flex items-center gap-3 rounded-2xl px-2 py-2.5 hover:bg-surface-sunken dark:hover:bg-surface-sunken-dark">
                        <a href="{{ route('friends.conversation', $friend) }}" wire:navigate aria-label="Open chat with {{ $friend->name }}" class="absolute inset-0 rounded-2xl"></a>
                        <span class="pointer-events-none relative shrink-0">
                            <x-user-avatar :user="$friend" class="h-12 w-12 text-sm" />
                            @if ($row['streak'] > 0)
                                <span class="absolute -right-1 -bottom-1 rounded-full bg-surface px-1 text-[10px] font-extrabold text-accent-ink shadow-sm dark:bg-surface-dark dark:text-accent-ink-dark">🔥{{ $row['streak'] }}</span>
                            @endif
                        </span>
                        <div class="pointer-events-none min-w-0 flex-1">
                            <p class="flex items-baseline gap-1.5 truncate text-sm font-extrabold text-ink dark:text-ink-dark">
                                {{ $friend->name }}
                                @if ($row['active'])
                                    <span class="truncate text-[11px] font-bold text-success dark:text-success-dark">● {{ $row['where'] ?? 'Practiced today' }}</span>
                                @else
                                    <span class="text-[11px] font-bold text-ink-faint dark:text-ink-faint-dark">Not today</span>
                                @endif
                            </p>
                            <p class="mt-0.5 truncate text-xs {{ $row['unread'] ? 'font-bold text-ink dark:text-ink-dark' : 'text-ink-faint dark:text-ink-faint-dark' }}">
                                {{ $row['summary'] ? $this->preview($row['summary']['last']) : 'Say hello 👋' }}
                            </p>
                        </div>
                        <div class="relative flex shrink-0 flex-col items-end gap-1 text-[11px] font-bold text-ink-faint dark:text-ink-faint-dark">
                            @if ($row['summary'])
                                <span class="pointer-events-none tabular-nums">{{ $row['summary']['last']->created_at->diffForHumans(['short' => true, 'parts' => 1, 'syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE]) }}</span>
                            @endif
                            @if ($row['unread'])
                                <span class="pointer-events-none inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-accent px-1.5 text-[11px] font-bold text-white dark:bg-accent-dark" title="{{ $row['unread'] }} unread">{{ $row['unread'] }}</span>
                            @elseif (! $row['active'])
                                <button type="button" wire:click="nudge({{ $friend->id }})" @disabled($row['nudged']) class="{{ $pill }} bg-accent text-white dark:bg-accent-dark">{{ $row['nudged'] ? '✓ Nudged' : '🔥 Nudge' }}</button>
                            @endif
                        </div>
                    </div>
                @endforeach

                @foreach ($this->oneWayFollowing as $friend)
                    <div wire:key="following-{{ $friend->id }}" class="flex items-center gap-3 px-2 py-2.5">
                        <span class="opacity-60"><x-user-avatar :user="$friend" class="h-12 w-12 text-sm" /></span>
                        <div class="min-w-0 flex-1 opacity-60">
                            <p class="truncate text-sm font-extrabold text-ink dark:text-ink-dark">{{ $friend->name }}</p>
                            <p class="truncate text-xs text-ink-faint dark:text-ink-faint-dark">Hasn't followed back yet</p>
                        </div>
                        {{-- Two taps: the first arms it, the second cancels the follow. --}}
                        <button
                            type="button"
                            x-data="{ armed: false, timer: null }"
                            x-on:click="if (! armed) { armed = true; timer = setTimeout(() => armed = false, 3000) } else { clearTimeout(timer); $wire.unfollow({{ $friend->id }}) }"
                            x-bind:class="armed ? 'border-danger-line bg-danger-soft text-danger-ink' : 'border-line text-ink-faint dark:border-line-dark dark:text-ink-faint-dark'"
                            class="{{ $pill }} border"
                        ><span x-show="! armed">Waiting</span><span x-show="armed" x-cloak>Cancel?</span></button>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- ➕ Add friends --}}
    <div x-show="adding" x-cloak class="space-y-4">
        <header class="flex items-center gap-2">
            <button type="button" x-on:click="adding = false" aria-label="Back to Friends" class="{{ $iconButton }} -ml-2 text-ink-soft hover:bg-surface-sunken dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">@svg('heroicon-o-chevron-left', 'h-6 w-6')</button>
            <h2 class="flex-1 font-display text-2xl font-extrabold text-ink dark:text-ink-dark">Add friends</h2>
        </header>

        <label class="flex items-center gap-2 rounded-2xl border-2 border-accent bg-surface px-3 py-2.5 dark:border-accent-dark dark:bg-surface-dark">
            @svg('heroicon-o-magnifying-glass', 'h-5 w-5 shrink-0 text-ink-faint dark:text-ink-faint-dark')
            <span class="sr-only">Search by name</span>
            <input x-ref="search" type="text" wire:model.live.debounce.300ms="search" placeholder="Search by name" class="min-w-0 flex-1 border-0 bg-transparent p-0 text-sm font-semibold text-ink focus:ring-0 focus:outline-none dark:text-ink-dark">
        </label>

        @if (trim($search) !== '')
            <div class="-mx-2">
                @forelse ($this->searchResults as $user)
                    <div wire:key="result-{{ $user->id }}" class="flex items-center gap-3 px-2 py-2">
                        <x-user-avatar :user="$user" class="h-10 w-10 text-xs" />
                        <span class="min-w-0 flex-1 truncate text-sm font-extrabold text-ink dark:text-ink-dark">{{ $user->name }}</span>
                        @if (auth()->user()->isFollowing($user))
                            <span class="{{ $pill }} cursor-default bg-surface-sunken text-ink-faint dark:bg-surface-sunken-dark dark:text-ink-faint-dark">Following</span>
                        @elseif (auth()->user()->hasPendingRequestTo($user))
                            <button type="button" wire:click="unfollow({{ $user->id }})" wire:loading.attr="disabled" title="Cancel request" class="{{ $pill }} bg-surface-sunken text-ink-faint dark:bg-surface-sunken-dark dark:text-ink-faint-dark">Requested</button>
                        @elseif (auth()->user()->hasPendingRequestFrom($user))
                            <button type="button" wire:click="acceptRequest({{ $user->id }})" wire:loading.attr="disabled" class="{{ $pill }} bg-success text-white dark:bg-success-dark">Accept request</button>
                        @else
                            <button type="button" wire:click="follow({{ $user->id }})" wire:loading.attr="disabled" class="{{ $pill }} bg-accent text-white dark:bg-accent-dark">Follow</button>
                        @endif
                    </div>
                @empty
                    <p class="py-6 text-center text-sm text-ink-faint dark:text-ink-faint-dark">No one found with that name.</p>
                @endforelse
            </div>
        @endif

        @if ($this->followBack->isNotEmpty())
            <p class="text-xs font-extrabold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">They follow you</p>
            <div class="-mx-2">
                @foreach ($this->followBack as $follower)
                    <div wire:key="follower-{{ $follower->id }}" class="flex items-center gap-3 px-2 py-2">
                        <x-user-avatar :user="$follower" class="h-10 w-10 text-xs" />
                        <span class="min-w-0 flex-1 truncate text-sm font-extrabold text-ink dark:text-ink-dark">{{ $follower->name }}</span>
                        @if (auth()->user()->hasPendingRequestTo($follower))
                            <span class="{{ $pill }} cursor-default bg-surface-sunken text-ink-faint dark:bg-surface-sunken-dark dark:text-ink-faint-dark">Requested</span>
                        @else
                            <button type="button" wire:click="follow({{ $follower->id }})" wire:loading.attr="disabled" class="{{ $pill }} bg-success text-white dark:bg-success-dark">Follow back</button>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
