<?php

use App\Models\DirectMessage;
use App\Models\PairGame;
use App\Models\PairPracticeSession;
use App\Models\User;
use App\Services\PairPractice;
use App\Services\Usernames;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    /** Rows shown at first, and added by each "Show more". */
    public const PAGE_SIZE = 20;

    /** How many faces fit the "practised today" strip. */
    public const TODAY_STRIP_SIZE = 10;

    public string $search = '';

    /** How many search results are showing. */
    public int $searchLimit = self::PAGE_SIZE;

    /** How many chats are showing; a chat with unread messages always is. */
    public int $friendLimit = self::PAGE_SIZE;

    /** The invite link this page was opened from (see the 'invite' route). */
    #[Url]
    public ?string $invite = null;

    public function updatedSearch(): void
    {
        $this->searchLimit = self::PAGE_SIZE;
    }

    public function showMoreResults(): void
    {
        $this->searchLimit += self::PAGE_SIZE;
    }

    public function showMoreFriends(): void
    {
        $this->friendLimit += self::PAGE_SIZE;
    }

    public function follow(int $userId): void
    {
        $target = User::findOrFail($userId);

        auth()->user()->follow($target);
        unset($this->following, $this->friends, $this->oneWayFollowing, $this->rows, $this->searchMatches, $this->followBack);
    }

    public function unfollow(int $userId): void
    {
        $target = User::findOrFail($userId);

        auth()->user()->unfollow($target);
        unset($this->following, $this->friends, $this->oneWayFollowing, $this->rows, $this->searchMatches, $this->followBack);
    }

    public function acceptRequest(int $userId): void
    {
        $requester = User::findOrFail($userId);

        auth()->user()->acceptFollowRequest($requester);
        unset($this->following, $this->friends, $this->oneWayFollowing, $this->rows, $this->followers, $this->pendingRequests, $this->searchMatches, $this->followBack);
    }

    public function rejectRequest(int $userId): void
    {
        $requester = User::findOrFail($userId);

        auth()->user()->rejectFollowRequest($requester);
        unset($this->pendingRequests, $this->searchMatches);
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
     * The chats on the page: the first page or two, plus any further down
     * that still have unread messages, so a badge never points at nothing.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function shownFriends(): Collection
    {
        return $this->friends
            ->filter(fn (User $friend, int $index) => $index < $this->friendLimit || ($this->summaries[$friend->id]['unread'] ?? 0) > 0)
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
        return $this->shownFriends->map(function (User $friend) {
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
    /**
     * Pair Practice rounds still open for me: a friend's invite waiting for
     * my Join, my own invite waiting for theirs, or a round to go back to.
     *
     * @return Collection<int, PairPracticeSession>
     */
    #[Computed]
    public function liveRounds(): Collection
    {
        $me = auth()->user();

        return PairPracticeSession::query()
            ->where(fn ($query) => $query->where('user_a_id', $me->id)->orWhere('user_b_id', $me->id))
            ->open()
            ->with(['userA', 'userB', 'startedBy'])
            ->latest()
            ->get()
            ->unique(fn (PairPracticeSession $session) => $session->user_a_id.'-'.$session->user_b_id)
            ->values();
    }

    /**
     * Games still open for me: a friend's invite, my own invite waiting,
     * or a game to go back to.
     *
     * @return Collection<int, PairGame>
     */
    #[Computed]
    public function liveGames(): Collection
    {
        return PairGame::involving(auth()->user())
            ->open()
            ->with(['userA', 'userB'])
            ->latest()
            ->get()
            ->unique(fn (PairGame $pairGame) => $pairGame->user_a_id.'-'.$pairGame->user_b_id)
            ->values();
    }

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

    /**
     * The person whose invite link opened this page, unless they are me,
     * already a friend or blocked either way.
     */
    #[Computed]
    public function invitedPerson(): ?User
    {
        if (! $this->invite) {
            return null;
        }

        return User::query()
            ->where('invite_code', $this->invite)
            ->where('id', '!=', auth()->id())
            ->whereNotIn('id', $this->blockedUserIds())
            ->whereNotIn('id', auth()->user()->mutualFriendIds())
            ->first();
    }

    /**
     * What the Find friends box found: my own friends, then new people —
     * the exact @username first, then those I share friends with, then
     * A–Z. One past the limit, so the page knows if there is more.
     *
     * @return array{friends: Collection<int, User>, people: Collection<int, User>, hasMore: bool}
     */
    #[Computed]
    public function searchMatches(): array
    {
        $term = trim($this->search);
        $friendIds = auth()->user()->mutualFriendIds();

        if (ltrim($term, '@') === '') {
            return ['friends' => collect(), 'people' => collect(), 'hasMore' => false];
        }

        $friends = User::query()
            ->whereIn('id', $friendIds)
            ->matchingSearch($term)
            ->orderBy('name')
            ->limit(self::PAGE_SIZE)
            ->get();

        $people = User::query()
            ->where('id', '!=', auth()->id())
            ->whereNotIn('id', $this->blockedUserIds())
            ->whereNotIn('id', $friendIds)
            ->where('discoverable', true)
            ->matchingSearch($term)
            ->withCount(['followers as shared_friends_count' => fn ($query) => $query->whereIn('follows.follower_id', $friendIds)])
            ->orderByRaw('CASE WHEN username = ? THEN 0 ELSE 1 END', [app(Usernames::class)->normalize($term)])
            ->orderByDesc('shared_friends_count')
            ->orderBy('name')
            ->limit($this->searchLimit + 1)
            ->get();

        return [
            'friends' => $friends,
            'people' => $people->take($this->searchLimit),
            'hasMore' => $people->count() > $this->searchLimit,
        ];
    }
};
?>

@php
    $pill = 'shrink-0 cursor-pointer rounded-full px-3 py-1.5 text-xs font-extrabold transition-colors disabled:pointer-events-none disabled:opacity-50';
    $iconButton = 'inline-flex h-10 w-10 shrink-0 cursor-pointer items-center justify-center rounded-full transition-colors';
    $hasAnyone = $this->friends->isNotEmpty() || $this->oneWayFollowing->isNotEmpty() || $this->pendingRequests->isNotEmpty();
    $activeToday = collect($this->rows)->where('active', true)->take(self::TODAY_STRIP_SIZE)->values();
    $moreFriends = $this->friends->count() - $this->shownFriends->count();
    $findBox = 'flex w-full items-center gap-2 rounded-2xl border-2 bg-surface px-3 py-2.5 dark:bg-surface-dark';
@endphp

{{-- Like a messenger: who practised today on top, Practice together, the
     requests, then one row per chat. The search box swaps the page for
     Find friends (search, invite link, "They follow you"); Mute, Unfollow, Block and Report live
     in each chat's ⋯. --}}
<div class="mx-auto max-w-2xl px-4 pt-3 pb-6 sm:px-6" x-data="{ adding: @js(trim($search) !== '') }" x-on:eos-friends-changed.window="$wire.$refresh()" x-back-close="{ open: adding, close: () => adding = false }">
    <div x-show="! adding" class="stagger-children space-y-4">
        <header class="flex items-center gap-2">
            <h1 class="flex-1 font-display text-3xl font-extrabold text-ink dark:text-ink-dark">Friends</h1>
            <a href="{{ route('friends.board') }}" wire:navigate aria-label="Friends Board" class="{{ $iconButton }} bg-surface text-ink-soft shadow-sm hover:text-ink dark:bg-surface-dark dark:text-ink-soft-dark">@svg('heroicon-o-trophy', 'h-5 w-5')</a>
        </header>

        @if ($hasAnyone)
            <button type="button" x-on:click="adding = true; $nextTick(() => $refs.search.focus())" class="{{ $findBox }} cursor-text border-line text-sm font-semibold text-ink-faint dark:border-line-dark dark:text-ink-faint-dark">
                @svg('heroicon-o-magnifying-glass', 'h-5 w-5 shrink-0')
                Find or add friends
            </button>
        @endif

        {{-- Tara always, then who practised today with a green ring. --}}
        <div class="-mx-4 flex gap-3 overflow-x-auto px-4 pt-2 pb-1" aria-label="Today">
            <a href="{{ route('friends.tara') }}" wire:navigate class="flex w-14 shrink-0 flex-col items-center gap-1 text-[11px] font-bold text-ink-soft dark:text-ink-soft-dark">
                <span class="relative rounded-full ring-[2.5px] ring-accent ring-offset-2 ring-offset-ground dark:ring-accent-dark dark:ring-offset-ground-dark">
                    <x-tara-avatar class="h-12 w-12" />
                    <span class="absolute -right-1 -bottom-1 inline-flex h-5 w-5 items-center justify-center rounded-full bg-accent text-white dark:bg-accent-dark">@svg('heroicon-s-sparkles', 'h-3 w-3')</span>
                </span>
                <span class="w-full truncate text-center">Tara</span>
            </a>
            @foreach ($activeToday as $row)
                <a href="{{ route('friends.conversation', $row['friend']) }}" wire:navigate wire:key="today-{{ $row['friend']->id }}" class="flex w-14 shrink-0 flex-col items-center gap-1 text-[11px] font-bold text-ink-soft dark:text-ink-soft-dark">
                    <span class="relative rounded-full ring-[2.5px] ring-success ring-offset-2 ring-offset-ground dark:ring-success-dark dark:ring-offset-ground-dark">
                        <x-user-avatar :user="$row['friend']" class="h-12 w-12 text-sm" />
                        <span class="sr-only">practiced today</span>
                    </span>
                    <span class="w-full truncate text-center">{{ $row['friend']->name }}</span>
                </a>
            @endforeach
        </div>

        @if ($invited = $this->invitedPerson)
            <div wire:key="invited-{{ $invited->id }}" class="flex items-center gap-3 rounded-2xl bg-surface px-3 py-2.5 shadow-sm dark:bg-surface-dark">
                <x-user-avatar :user="$invited" class="h-10 w-10 text-xs" />
                <p class="min-w-0 flex-1 text-sm font-extrabold text-ink dark:text-ink-dark">
                    <span class="block truncate">{{ $invited->name }}</span>
                    <span class="block truncate text-xs font-semibold text-ink-faint dark:text-ink-faint-dark"><bdi>{{ $invited->handle() }}</bdi></span>
                </p>
                <x-friends.follow-pill :user="$invited" :pill="$pill" />
            </div>
        @endif

        @if (! $hasAnyone)
            <div class="flex flex-col items-center gap-3 px-6 pt-16 text-center">
                <span class="text-5xl" aria-hidden="true">👋</span>
                <p class="text-lg font-extrabold text-ink dark:text-ink-dark">Practice is better with a friend</p>
                <p class="text-sm font-semibold text-ink-faint dark:text-ink-faint-dark">Find a classmate by name or @username.</p>
                <button type="button" x-on:click="adding = true; $nextTick(() => $refs.search.focus())" class="mt-2 w-full cursor-pointer rounded-2xl bg-accent px-5 py-3.5 text-base font-extrabold text-white shadow-sm hover:opacity-90 dark:bg-accent-dark">Add a friend</button>
            </div>
        @endif

        @foreach ($this->liveRounds as $round)
            @php
                $partner = $round->partnerFor(auth()->user());
                $invitesMe = $round->isWaiting() && $round->started_by_id === $partner->id;
            @endphp
            <a
                href="{{ $invitesMe ? route('pair-practice.show', ['session' => $round, 'join' => 1]) : route('pair-practice.show', $round) }}"
                wire:navigate
                wire:key="live-round-{{ $round->id }}"
                class="flex items-center gap-3 rounded-2xl bg-success-soft px-3 py-2.5 shadow-sm dark:bg-success-soft-dark"
            >
                <x-user-avatar :user="$partner" class="h-10 w-10 text-xs" />
                <p class="min-w-0 flex-1 text-sm font-extrabold text-ink dark:text-ink-dark">
                    <span class="block truncate">{{ match (true) {
                        $invitesMe => $partner->name.' is waiting for you',
                        $round->isWaiting() => 'Waiting for '.$partner->name.'…',
                        default => 'Round with '.$partner->name,
                    } }}</span>
                    <span class="block truncate text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">Practice together · {{ $round->daysLabel() }}</span>
                </p>
                <span class="shrink-0 rounded-full bg-success px-4 py-2 text-xs font-extrabold text-white dark:bg-success-dark">{{ $invitesMe ? 'Join' : ($round->isWaiting() ? 'Open' : 'Continue') }}</span>
            </a>
        @endforeach

        @foreach ($this->liveGames as $liveGame)
            @php
                $partner = $liveGame->partnerFor(auth()->user());
                $invitesMe = $liveGame->isWaiting() && $liveGame->started_by_id === $partner->id;
            @endphp
            <a
                href="{{ $invitesMe ? route('games.play', ['pairGame' => $liveGame, 'join' => 1]) : route('games.play', $liveGame) }}"
                wire:navigate
                wire:key="live-game-{{ $liveGame->id }}"
                class="flex items-center gap-3 rounded-2xl bg-success-soft px-3 py-2.5 shadow-sm dark:bg-success-soft-dark"
            >
                <x-user-avatar :user="$partner" class="h-10 w-10 text-xs" />
                <p class="min-w-0 flex-1 text-sm font-extrabold text-ink dark:text-ink-dark">
                    <span class="block truncate">{{ match (true) {
                        $invitesMe => $partner->name.' is waiting for you',
                        $liveGame->isWaiting() => 'Waiting for '.$partner->name.'…',
                        default => 'Game with '.$partner->name,
                    } }}</span>
                    <span class="block truncate text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">{{ App\Models\PairGame::info($liveGame->game)['name'] }} · {{ $liveGame->minutes }} min</span>
                </p>
                <span class="shrink-0 rounded-full bg-success px-4 py-2 text-xs font-extrabold text-white dark:bg-success-dark">{{ $invitesMe ? 'Join' : ($liveGame->isWaiting() ? 'Open' : 'Continue') }}</span>
            </a>
        @endforeach

        @if ($this->friends->isNotEmpty())
            <div class="grid grid-cols-2 gap-2.5">
                <a href="{{ route('pair-practice.index') }}" wire:navigate class="flex flex-col gap-0.5 rounded-2xl bg-accent px-4 py-3 text-white shadow-sm hover:opacity-90 dark:bg-accent-dark">
                    <span class="text-sm font-extrabold">👫 Practice together</span>
                    <span class="text-xs font-bold text-white/85">12 min ›</span>
                </a>
                <a href="{{ route('games.index') }}" wire:navigate class="flex flex-col gap-0.5 rounded-2xl bg-success px-4 py-3 text-white shadow-sm hover:opacity-90 dark:bg-success-dark">
                    <span class="text-sm font-extrabold">🎲 Games</span>
                    <span class="text-xs font-bold text-white/85">Taboo & more ›</span>
                </a>
            </div>
        @endif

        @foreach ($this->pendingRequests as $requester)
            <div wire:key="request-{{ $requester->id }}" class="flex items-center gap-3 rounded-2xl bg-surface px-3 py-2.5 shadow-sm dark:bg-surface-dark">
                <x-user-avatar :user="$requester" class="h-10 w-10 text-xs" />
                <p class="min-w-0 flex-1 text-sm font-extrabold text-ink dark:text-ink-dark">
                    <span class="block truncate">{{ $requester->name }}</span>
                    <span class="block truncate text-xs font-semibold text-ink-faint dark:text-ink-faint-dark"><span><bdi>{{ $requester->handle() }}</bdi></span> · wants to be friends</span>
                </p>
                <button type="button" wire:click="acceptRequest({{ $requester->id }})" wire:loading.attr="disabled" aria-label="Accept {{ $requester->name }}" class="{{ $iconButton }} h-9 w-9 bg-success text-white dark:bg-success-dark">@svg('heroicon-o-check', 'h-4 w-4')</button>
                <button type="button" wire:click="rejectRequest({{ $requester->id }})" wire:loading.attr="disabled" aria-label="Reject {{ $requester->name }}" class="{{ $iconButton }} h-9 w-9 bg-surface-sunken text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark">@svg('heroicon-o-x-mark', 'h-4 w-4')</button>
            </div>
        @endforeach

        @if ($this->rows || $this->oneWayFollowing->isNotEmpty())
            <div class="-mx-2">
                @foreach ($this->rows as $row)
                    @php $friend = $row['friend']; @endphp
                    {{-- The whole row opens the chat; the avatar opens the friend's page, and Nudge sits above it too. --}}
                    <div wire:key="friend-{{ $friend->id }}" class="relative flex items-center gap-3 rounded-2xl px-2 py-2.5 hover:bg-surface-sunken dark:hover:bg-surface-sunken-dark">
                        <a href="{{ route('friends.conversation', $friend) }}" wire:navigate aria-label="Open chat with {{ $friend->name }}" class="absolute inset-0 rounded-2xl"></a>
                        <a href="{{ route('friends.profile', $friend) }}" wire:navigate aria-label="{{ $friend->name }}’s page" class="relative shrink-0">
                            <x-user-avatar :user="$friend" class="h-12 w-12 text-sm" />
                            @if ($row['streak'] > 0)
                                <span class="absolute -right-1 -bottom-1 rounded-full bg-surface px-1 text-[10px] font-extrabold text-accent-ink shadow-sm dark:bg-surface-dark dark:text-accent-ink-dark">🔥{{ $row['streak'] }}</span>
                            @endif
                        </a>
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

                @if ($moreFriends > 0)
                    <button type="button" wire:click="showMoreFriends" wire:loading.attr="disabled" class="w-full cursor-pointer py-3 text-center text-sm font-bold text-accent-ink dark:text-accent-ink-dark">Show more ({{ $moreFriends }})</button>
                @endif

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

    {{-- Find friends: my friends and new people from one box. --}}
    <div x-show="adding" x-cloak class="space-y-4">
        <header class="flex items-center gap-2">
            <button type="button" x-on:click="adding = false" aria-label="Back to Friends" class="{{ $iconButton }} -ml-2 text-ink-soft hover:bg-surface-sunken dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">@svg('heroicon-o-chevron-left', 'h-6 w-6')</button>
            <h2 class="flex-1 font-display text-2xl font-extrabold text-ink dark:text-ink-dark">Find friends</h2>
        </header>

        <label class="{{ $findBox }} border-accent dark:border-accent-dark">
            @svg('heroicon-o-magnifying-glass', 'h-5 w-5 shrink-0 text-ink-faint dark:text-ink-faint-dark')
            <span class="sr-only">Name or @username</span>
            <input x-ref="search" type="text" wire:model.live.debounce.300ms="search" placeholder="Name or @username" autocapitalize="none" autocomplete="off" class="min-w-0 flex-1 border-0 bg-transparent p-0 text-sm font-semibold text-ink focus:ring-0 focus:outline-none dark:text-ink-dark">
        </label>

        @if (trim($search) === '')
            <x-share-app class="{{ $findBox }} cursor-pointer border-line text-left dark:border-line-dark">
                <span class="text-accent-ink dark:text-accent-ink-dark">@svg('heroicon-o-share', 'h-5 w-5 shrink-0')</span>
                <span class="min-w-0 flex-1">
                    <span class="block text-sm font-extrabold text-ink dark:text-ink-dark">Send my invite link</span>
                    <span class="block truncate text-xs font-semibold text-ink-faint dark:text-ink-faint-dark"><span x-show="! copied">You're <bdi>{{ auth()->user()->handle() }}</bdi></span><span x-show="copied" x-cloak>Copied</span></span>
                </span>
            </x-share-app>
        @else
            @php $matches = $this->searchMatches; @endphp

            @if ($matches['friends']->isNotEmpty())
                <p class="text-xs font-extrabold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Your friends</p>
                <div class="-mx-2">
                    @foreach ($matches['friends'] as $user)
                        <a href="{{ route('friends.conversation', $user) }}" wire:navigate wire:key="match-{{ $user->id }}" class="flex items-center gap-3 rounded-2xl px-2 py-2 hover:bg-surface-sunken dark:hover:bg-surface-sunken-dark">
                            <x-user-avatar :user="$user" class="h-10 w-10 text-xs" />
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-extrabold text-ink dark:text-ink-dark">{{ $user->name }}</span>
                                <span class="block truncate text-xs font-semibold text-ink-faint dark:text-ink-faint-dark"><bdi>{{ $user->handle() }}</bdi></span>
                            </span>
                        </a>
                    @endforeach
                </div>
            @endif

            @if ($matches['people']->isNotEmpty())
                @if ($matches['friends']->isNotEmpty())
                    <p class="text-xs font-extrabold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Add new</p>
                @endif
                <div class="-mx-2">
                    @foreach ($matches['people'] as $user)
                        <div wire:key="result-{{ $user->id }}" class="flex items-center gap-3 px-2 py-2">
                            <x-user-avatar :user="$user" class="h-10 w-10 text-xs" />
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-extrabold text-ink dark:text-ink-dark">{{ $user->name }}</span>
                                <span class="block truncate text-xs font-semibold text-ink-faint dark:text-ink-faint-dark"><span><bdi>{{ $user->handle() }}</bdi></span>@if ($user->shared_friends_count > 0) · {{ $user->shared_friends_count }} mutual {{ Str::plural('friend', $user->shared_friends_count) }}@endif</span>
                            </span>
                            <x-friends.follow-pill :user="$user" :pill="$pill" />
                        </div>
                    @endforeach
                </div>
                @if ($matches['hasMore'])
                    <button type="button" wire:click="showMoreResults" wire:loading.attr="disabled" class="w-full cursor-pointer py-1 text-center text-sm font-bold text-accent-ink dark:text-accent-ink-dark">Show more</button>
                @endif
            @endif

            @if ($matches['friends']->isEmpty() && $matches['people']->isEmpty())
                <p class="py-6 text-center text-sm text-ink-faint dark:text-ink-faint-dark">No one found with that name.</p>
            @endif
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
