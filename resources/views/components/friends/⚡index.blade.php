<?php

use App\Models\DirectMessage;
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

    /** @var array<int, string> keyed by user id — the optional details typed for that row */
    public array $reportReason = [];

    /** @var array<int, string> keyed by user id — the chosen FriendReport::CATEGORIES key */
    public array $reportCategory = [];

    /** @var array<int, bool> keyed by user id — a report was just sent for that row */
    public array $reportSent = [];

    public function follow(int $userId): void
    {
        $target = User::findOrFail($userId);

        auth()->user()->follow($target);
        unset($this->following, $this->friends, $this->oneWayFollowing, $this->summaries, $this->searchResults);
    }

    public function unfollow(int $userId): void
    {
        $target = User::findOrFail($userId);

        auth()->user()->unfollow($target);
        unset($this->following, $this->friends, $this->oneWayFollowing, $this->summaries, $this->searchResults);
    }

    public function acceptRequest(int $userId): void
    {
        $requester = User::findOrFail($userId);

        auth()->user()->acceptFollowRequest($requester);
        unset($this->following, $this->friends, $this->oneWayFollowing, $this->summaries, $this->followers, $this->pendingRequests, $this->searchResults);
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

        auth()->user()->block($target);

        unset($this->following, $this->friends, $this->oneWayFollowing, $this->summaries, $this->followers, $this->pendingRequests, $this->searchResults);
    }

    public function startReport(int $userId): void
    {
        unset($this->reportSent[$userId]);
        $this->reporting[$userId] = true;
    }

    public function cancelReport(int $userId): void
    {
        unset($this->reporting[$userId], $this->reportReason[$userId], $this->reportCategory[$userId]);
    }

    public function dismissReportSent(int $userId): void
    {
        unset($this->reportSent[$userId]);
    }

    /**
     * A category is required (it is what makes the report actionable); the
     * details are optional. The reported person's latest real message is kept
     * as the snapshot.
     */
    public function submitReport(int $userId): void
    {
        $category = $this->reportCategory[$userId] ?? '';

        if (! array_key_exists($category, FriendReport::CATEGORIES)) {
            return;
        }

        $details = trim($this->reportReason[$userId] ?? '');

        FriendReport::create([
            'reporter_id' => auth()->id(),
            'reported_id' => $userId,
            'category' => $category,
            'reason' => $details !== '' ? mb_substr($details, 0, 1000) : FriendReport::CATEGORIES[$category],
            'message_snapshot' => auth()->user()->lastMessageFrom(User::findOrFail($userId)),
        ]);

        unset($this->reporting[$userId], $this->reportReason[$userId], $this->reportCategory[$userId]);
        $this->reportSent[$userId] = true;
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
     * People you follow who haven't followed back — no chat yet, so they sit
     * apart from the inbox.
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
            DirectMessage::TYPE_AUDIO => 'Voice message',
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

<div class="stagger-children mx-auto max-w-2xl space-y-6 p-4 sm:p-6">
    <a href="{{ route('home') }}" class="inline-flex cursor-pointer items-center gap-1 rounded-full border border-line px-3 py-1.5 text-xs leading-none font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">
        @svg('heroicon-o-chevron-left', 'h-3.5 w-3.5')
        All missions
    </a>

    <header class="flex items-center gap-3 card p-4">
        <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">
            @svg('heroicon-s-user-group', 'h-5 w-5')
        </span>
        <div class="min-w-0 flex-1">
            <h1 class="font-display text-2xl font-extrabold text-ink dark:text-ink-dark">Friends</h1>
            <p class="mt-0.5 text-sm text-ink-soft dark:text-ink-soft-dark">Send a follow request, accept the ones you get, and message anyone who follows you back.</p>
        </div>
        <a
            href="{{ route('friends.board') }}"
            wire:navigate
            title="Friends Board — see everyone's progress"
            class="inline-flex h-9 w-9 shrink-0 cursor-pointer items-center justify-center rounded-full text-ink-faint transition-colors hover:bg-surface-sunken hover:text-ink dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark dark:hover:text-ink-dark"
        >@svg('heroicon-o-chart-bar', 'h-4.5 w-4.5')</a>
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
                    <div class="flex items-center gap-3 card px-3.5 py-2.5 shadow-sm">
                        <x-user-avatar :user="$user" class="h-9 w-9 text-xs" />
                        <span class="flex-1 truncate text-sm font-semibold text-ink dark:text-ink-dark">{{ $user->name }}</span>
                        @if (auth()->user()->isFollowing($user))
                            {{-- Two taps, like Unfollow in the "⋯" menu: the first arms the
                                 button for a few seconds, the second actually unfollows. --}}
                            <button
                                type="button"
                                x-data="{ armed: false, timer: null }"
                                x-on:click="if (! armed) { armed = true; timer = setTimeout(() => armed = false, 3000) } else { clearTimeout(timer); $wire.unfollow({{ $user->id }}) }"
                                wire:loading.attr="disabled"
                                wire:target="unfollow({{ $user->id }})"
                                x-bind:class="armed ? 'border-danger-line bg-danger-soft text-danger-ink' : 'border-line text-ink-soft hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark'"
                                class="shrink-0 cursor-pointer rounded-full border px-3 py-1 text-xs font-semibold transition-colors disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50"
                            ><span x-show="! armed">Following</span><span x-show="armed" x-cloak>Unfollow?</span></button>
                        @elseif (auth()->user()->hasPendingRequestTo($user))
                            <button
                                type="button"
                                wire:click="unfollow({{ $user->id }})"
                                wire:loading.attr="disabled"
                                wire:target="unfollow({{ $user->id }})"
                                title="Cancel request"
                                class="shrink-0 cursor-pointer rounded-full border border-dashed border-line px-3 py-1 text-xs font-semibold text-ink-faint transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50"
                            >Requested</button>
                        @elseif (auth()->user()->hasPendingRequestFrom($user))
                            <button
                                type="button"
                                wire:click="acceptRequest({{ $user->id }})"
                                wire:loading.attr="disabled"
                                wire:target="acceptRequest({{ $user->id }})"
                                class="shrink-0 cursor-pointer rounded-full bg-accent px-3 py-1 text-xs font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50"
                            >Accept request</button>
                        @else
                            <button
                                type="button"
                                wire:click="follow({{ $user->id }})"
                                wire:loading.attr="disabled"
                                wire:target="follow({{ $user->id }})"
                                class="shrink-0 cursor-pointer rounded-full bg-accent px-3 py-1 text-xs font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50"
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
        <div class="card-accent p-3.5">
            <p class="text-xs font-semibold tracking-wide text-accent-ink uppercase dark:text-accent-ink-dark">Follow requests ({{ $this->pendingRequests->count() }})</p>
            <div class="mt-2 space-y-2">
                @foreach ($this->pendingRequests as $requester)
                    <div class="flex items-center gap-3 rounded-xl bg-surface px-3 py-2 shadow-sm dark:bg-surface-dark">
                        <x-user-avatar :user="$requester" class="h-9 w-9 text-xs" />
                        <span class="flex-1 truncate text-sm font-semibold text-ink dark:text-ink-dark">{{ $requester->name }}</span>
                        <button
                            type="button"
                            wire:click="acceptRequest({{ $requester->id }})"
                            wire:loading.attr="disabled"
                            wire:target="acceptRequest({{ $requester->id }})"
                            title="Accept"
                            class="inline-flex h-8 w-8 cursor-pointer items-center justify-center rounded-full bg-accent text-white transition-colors hover:opacity-90 dark:bg-accent-dark disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50"
                        >@svg('heroicon-o-check', 'h-4 w-4')</button>
                        <button
                            type="button"
                            wire:click="rejectRequest({{ $requester->id }})"
                            wire:loading.attr="disabled"
                            wire:target="rejectRequest({{ $requester->id }})"
                            title="Reject"
                            class="inline-flex h-8 w-8 cursor-pointer items-center justify-center rounded-full border border-line text-ink-faint transition-colors hover:bg-surface-sunken dark:border-line-dark dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50"
                        >@svg('heroicon-o-x-mark', 'h-4 w-4')</button>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if ($this->friends->isNotEmpty())
        <div>
            <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Friends ({{ $this->friends->count() }})</p>
            <div class="mt-2 space-y-2.5">
                @foreach ($this->friends as $friend)
                    @php
                        $summary = $this->summaries[$friend->id] ?? null;
                        $unread = $summary['unread'] ?? 0;
                        $stats = $this->stats($friend);
                    @endphp
                    {{-- The whole card opens the chat (stretched link under the content);
                         only the "⋯" menu and the report form sit above it. --}}
                    <div wire:key="friend-{{ $friend->id }}" class="relative card p-3.5 shadow-sm transition-shadow hover:shadow-md">
                        <a
                            href="{{ route('friends.conversation', $friend) }}"
                            wire:navigate
                            aria-label="Open chat with {{ $friend->name }}"
                            class="absolute inset-0 z-0 rounded-2xl"
                        ></a>
                        <div class="pointer-events-none relative flex items-center gap-3">
                            <x-user-avatar :user="$friend" class="h-11 w-11 text-sm" />
                            <div class="min-w-0 flex-1">
                                <div class="flex items-baseline justify-between gap-2">
                                    <p class="truncate text-sm font-semibold text-ink dark:text-ink-dark">{{ $friend->name }}</p>
                                    @if ($summary)
                                        <span class="shrink-0 text-[11px] text-ink-faint tabular-nums dark:text-ink-faint-dark">{{ $summary['last']->created_at->diffForHumans(['short' => true, 'parts' => 1]) }}</span>
                                    @endif
                                </div>
                                <p class="mt-0.5 truncate text-xs {{ $unread ? 'font-semibold text-ink dark:text-ink-dark' : 'text-ink-faint dark:text-ink-faint-dark' }}">
                                    {{ $summary ? $this->preview($summary['last']) : 'No messages yet — say hello' }}
                                </p>
                                @if ($stats['streak'] > 0)
                                    <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                        <span class="inline-flex items-center gap-1 rounded-full bg-accent-soft px-2 py-0.5 text-[11px] font-semibold text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">
                                            <x-streak-flame :streak="$stats['streak']" size="h-3 w-3" />
                                            {{ $stats['streak'] }}-day streak
                                        </span>
                                    </div>
                                @endif
                            </div>
                            <div class="pointer-events-auto relative z-10 flex shrink-0 items-center gap-1.5">
                                @if ($unread)
                                    <span class="pointer-events-none inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-accent px-1.5 text-[11px] font-bold text-white dark:bg-accent-dark" title="{{ $unread }} unread">{{ $unread }}</span>
                                @endif
                                <x-friends.card-menu :friend="$friend" :mutual="true" />
                            </div>
                        </div>

                        @if (isset($reporting[$friend->id]))
                            <div class="relative z-10">
                                <x-friends.report-form :friend="$friend" :selected="$reportCategory[$friend->id] ?? null" :category-model="'reportCategory.'.$friend->id" :details-model="'reportReason.'.$friend->id" :submit-action="'submitReport('.$friend->id.')'" :cancel-action="'cancelReport('.$friend->id.')'" />
                            </div>
                        @elseif (isset($reportSent[$friend->id]))
                            <div class="relative z-10">
                                <x-friends.report-sent :friend="$friend" :block-action="'block('.$friend->id.')'" :dismiss-action="'dismissReportSent('.$friend->id.')'" />
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if ($this->oneWayFollowing->isNotEmpty())
        <div>
            <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Waiting to follow you back ({{ $this->oneWayFollowing->count() }})</p>
            <div class="mt-2 space-y-2.5">
                @foreach ($this->oneWayFollowing as $friend)
                    <div wire:key="following-{{ $friend->id }}" class="card p-3.5 shadow-sm">
                        <div class="flex items-center gap-3">
                            <x-user-avatar :user="$friend" class="h-11 w-11 text-sm" />
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold text-ink dark:text-ink-dark">{{ $friend->name }}</p>
                                <p class="mt-0.5 flex items-center gap-1 text-xs text-ink-faint dark:text-ink-faint-dark">
                                    @svg('heroicon-o-lock-closed', 'h-3.5 w-3.5 shrink-0')
                                    They don't follow you back yet — messaging unlocks once they do.
                                </p>
                            </div>
                            <x-friends.card-menu :friend="$friend" :mutual="false" />
                        </div>

                        @if (isset($reporting[$friend->id]))
                            <x-friends.report-form :friend="$friend" :selected="$reportCategory[$friend->id] ?? null" :category-model="'reportCategory.'.$friend->id" :details-model="'reportReason.'.$friend->id" :submit-action="'submitReport('.$friend->id.')'" :cancel-action="'cancelReport('.$friend->id.')'" />
                        @elseif (isset($reportSent[$friend->id]))
                            <x-friends.report-sent :friend="$friend" :block-action="'block('.$friend->id.')'" :dismiss-action="'dismissReportSent('.$friend->id.')'" />
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if ($this->friends->isEmpty() && $this->oneWayFollowing->isEmpty())
        <div class="flex flex-col items-center gap-2 rounded-2xl border border-dashed border-line py-8 text-center dark:border-line-dark">
            @svg('heroicon-o-user-plus', 'h-6 w-6 text-ink-faint/60 dark:text-ink-faint-dark/60')
            <p class="text-sm text-ink-faint dark:text-ink-faint-dark">Search above to follow your first classmate.</p>
        </div>
    @endif

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
