<?php

use App\Models\User;
use App\Notifications\PairPracticeInvite;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Blade;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    /**
     * IDs that were unread at the moment the dropdown opened — they stay
     * highlighted while it's open even though the badge is already clear.
     *
     * @var list<string>
     */
    public array $freshIds = [];

    /** How recent an unread alert must be to still pop up as a banner. */
    public const BANNER_MINUTES = 15;

    /**
     * Runs as the page opens and on every poll: the learner counts as "in
     * the app" (so new alerts skip the phone buzz, see User::isInApp()),
     * and the unread alerts of the last BANNER_MINUTES go to
     * <x-notification-banner>, which shows them one at a time and reports
     * back each one it showed (markShown()). Until then they stay unread.
     */
    public function checkNew(): void
    {
        $user = auth()->user();
        $user->markInApp();

        $fresh = $user->unreadNotifications()
            ->where('created_at', '>=', now()->subMinutes(self::BANNER_MINUTES))
            ->reorder()
            ->oldest()
            ->limit(3)
            ->get();

        if ($fresh->isNotEmpty()) {
            $people = $this->peopleFor($fresh);

            $this->dispatch('notification-banner', items: $fresh->map(fn ($notification) => [
                'id' => $notification->id,
                'title' => $notification->data['title'],
                'url' => $notification->data['url'],
                'icon' => svg($notification->data['icon'], 'h-5 w-5')->toHtml(),
                'avatar' => ($person = $people->get($this->personId($notification)))
                    ? Blade::render('<x-user-avatar :user="$user" class="h-11 w-11 text-sm" />', ['user' => $person])
                    : null,
                'urgent' => $notification->type === PairPracticeInvite::class,
            ])->values()->all());
        }
    }

    /**
     * The friend a notification is about, when it names one — the avatar
     * on its row and banner.
     */
    private function personId(DatabaseNotification $notification): ?int
    {
        return $notification->data['sender_id'] ?? $notification->data['follower_id'] ?? null;
    }

    /**
     * @param  Collection<int, DatabaseNotification>  $notifications
     * @return Collection<int, User>
     */
    private function peopleFor(Collection $notifications): Collection
    {
        $ids = $notifications->map(fn ($notification) => $this->personId($notification))->filter()->unique();

        return User::whereIn('id', $ids)->get()->keyBy('id');
    }

    /**
     * A banner on screen is a read alert, the same "seeing it is reading
     * it" rule as opening the list.
     */
    #[On('notification-shown')]
    public function markShown(string $id): void
    {
        auth()->user()->unreadNotifications()->whereKey($id)->update(['read_at' => now()]);

        unset($this->items, $this->unreadCount, $this->people, $this->groups);
    }

    #[Computed]
    public function items()
    {
        return auth()->user()->notifications()->limit(15)->get();
    }

    /**
     * The friends the listed notifications are about, by id.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function people(): Collection
    {
        return $this->peopleFor($this->items);
    }

    /**
     * Today's alerts first, then the rest, in the learner's own time zone.
     *
     * @return array{today: Collection<int, DatabaseNotification>, earlier: Collection<int, DatabaseNotification>}
     */
    #[Computed]
    public function groups(): array
    {
        $zone = auth()->user()->displayTimezone();

        [$today, $earlier] = $this->items->partition(fn ($item) => $item->created_at->setTimezone($zone)->isToday());

        return ['today' => $today, 'earlier' => $earlier];
    }

    #[Computed]
    public function unreadCount(): int
    {
        return auth()->user()->unreadNotifications()->count();
    }

    /**
     * Fires the moment the dropdown opens (see the button below) — viewing
     * the list IS reading it, same "open = read" pattern the DM thread
     * already uses. The badge clears immediately, but the rows that were
     * new keep a highlight (via $freshIds) so the user can still tell which
     * ones they are.
     */
    public function markAllAsRead(): void
    {
        $unread = auth()->user()->unreadNotifications;

        $this->freshIds = $unread->pluck('id')->all();
        $unread->markAsRead();

        unset($this->items, $this->unreadCount, $this->people, $this->groups);
    }

    /**
     * Who still has a pending request to this user — a follow-request
     * notification only shows Accept/Decline while its request is open
     * (it may have been answered from the Friends page in the meantime).
     *
     * @return list<int>
     */
    #[Computed]
    public function pendingRequesterIds(): array
    {
        return auth()->user()->pendingFollowRequests()->pluck('id')->all();
    }

    public function acceptRequest(string $notificationId): void
    {
        $this->answerRequest($notificationId, accept: true);
    }

    public function rejectRequest(string $notificationId): void
    {
        $this->answerRequest($notificationId, accept: false);
    }

    /**
     * The notification is looked up through the signed-in user's own
     * notifications, and the request must still be pending — a stale or
     * forged id can't act on anyone else's requests. Answered either way,
     * the notification has done its job and is removed.
     */
    private function answerRequest(string $notificationId, bool $accept): void
    {
        $notification = auth()->user()->notifications()->findOrFail($notificationId);
        $followerId = $notification->data['follower_id'] ?? null;

        if ($followerId !== null && in_array($followerId, $this->pendingRequesterIds, true)) {
            $follower = User::findOrFail($followerId);

            if ($accept) {
                auth()->user()->acceptFollowRequest($follower);
            } else {
                auth()->user()->rejectFollowRequest($follower);
            }
        }

        $notification->delete();

        unset($this->items, $this->unreadCount, $this->pendingRequesterIds, $this->people, $this->groups);
    }

    /**
     * No history is kept on purpose — a cleared bell is a deleted bell.
     */
    public function clearAll(): void
    {
        auth()->user()->notifications()->delete();

        $this->freshIds = [];

        unset($this->items, $this->unreadCount, $this->people, $this->groups);
    }
};
?>

<div class="relative" x-data="{ open: false }" x-on:click.outside="open = false" wire:init="checkNew" wire:poll.30s.visible="checkNew">
    {{-- The app icon's number on the home screen follows the bell. --}}
    <span wire:key="app-badge-{{ $this->unreadCount }}" x-data x-init="'setAppBadge' in navigator && ({{ $this->unreadCount }} ? navigator.setAppBadge({{ $this->unreadCount }}) : navigator.clearAppBadge()).catch(() => {})" hidden></span>

    <button
        type="button"
        x-on:click="open = !open"
        wire:click="markAllAsRead"
        wire:loading.attr="disabled"
        wire:target="markAllAsRead"
        title="Notifications"
        class="relative inline-flex h-8 w-8 cursor-pointer items-center justify-center rounded-full text-ink-faint transition-colors hover:bg-surface-sunken hover:text-ink dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark dark:hover:text-ink-dark disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50"
    >
        @svg('heroicon-o-bell', 'h-4 w-4')
        @if ($this->unreadCount)
            <span class="absolute -top-0.5 -right-0.5 inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-accent px-1 text-[10px] font-bold text-white dark:bg-accent-dark">{{ $this->unreadCount }}</span>
        @endif
    </button>

    <x-menu-panel width="sm:w-80" aria-label="Notifications">
        <div class="flex items-center justify-between px-4 pt-2 pb-2">
            <p class="font-display text-lg font-bold text-ink dark:text-ink-dark">Notifications</p>
            @if ($this->items->isNotEmpty())
                <button type="button" wire:click="clearAll" class="cursor-pointer text-xs font-bold text-accent-ink transition-colors hover:underline dark:text-accent-ink-dark">Clear all</button>
            @endif
        </div>

        {{-- Phone alerts are off: one row, one tap. When they are on, nothing
             is shown; the switch lives in Profile › Reminders. --}}
        <div
            x-data="{ push: window.eosPush ?? {} }"
            x-on:eos-push-state.window="push = $event.detail"
            x-show="push.ready && push.supported && ! push.subscribed && ! push.denied"
            x-cloak
            dir="rtl"
            class="mx-3 mb-1 flex items-center gap-2 rounded-2xl bg-accent-soft px-3 py-2.5 text-xs font-bold text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark"
        >
            <span class="flex-1">یادآورهای گوشی خاموش است</span>
            <button type="button" x-on:click="$dispatch('eos-push-enable')" :disabled="push.busy" class="cursor-pointer rounded-full bg-accent px-3 py-1 text-xs font-bold text-white transition-opacity hover:opacity-90 disabled:opacity-50 dark:bg-accent-dark">روشن کن</button>
        </div>

        <div class="max-h-96 overflow-y-auto pb-2">
            @foreach (['today' => 'Today', 'earlier' => 'Earlier'] as $group => $heading)
                @if ($this->groups[$group]->isNotEmpty())
                    <p class="px-4 pt-2 pb-0.5 text-[10px] font-extrabold tracking-wider text-ink-faint uppercase dark:text-ink-faint-dark">{{ $heading }}</p>
                    @foreach ($this->groups[$group] as $item)
                        @php
                            $person = $this->people->get($item->data['sender_id'] ?? $item->data['follower_id'] ?? null);
                            $isFresh = in_array($item->id, $this->freshIds, true);
                            $isRequest = isset($item->data['follower_id']) && in_array($item->data['follower_id'], $this->pendingRequesterIds, true);
                        @endphp
                        <div wire:key="notification-{{ $item->id }}" class="relative flex items-center gap-3 px-4 py-2.5">
                            @if ($isFresh)
                                <span aria-label="New" class="absolute top-1/2 left-1.5 h-1.5 w-1.5 -translate-y-1/2 rounded-full bg-accent dark:bg-accent-dark"></span>
                            @endif
                            <a href="{{ $item->data['url'] }}" wire:navigate x-on:click="open = false" class="flex min-w-0 flex-1 items-center gap-3">
                                @if ($person)
                                    <x-user-avatar :user="$person" class="h-10 w-10 text-sm" />
                                @else
                                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">
                                        @svg($item->data['icon'], 'h-5 w-5')
                                    </span>
                                @endif
                                <span class="min-w-0 flex-1">
                                    <span dir="auto" @class(['block text-sm leading-snug text-ink dark:text-ink-dark', 'font-bold' => $isFresh, 'font-semibold' => ! $isFresh])>{{ $item->data['title'] }}</span>
                                    <span class="block text-[11px] font-semibold text-ink-faint dark:text-ink-faint-dark">{{ $item->created_at->diffForHumans(['short' => true, 'parts' => 1]) }}</span>
                                </span>
                            </a>
                            @if ($isRequest)
                                <div class="flex shrink-0 gap-1.5">
                                    <button
                                        type="button"
                                        wire:click="acceptRequest('{{ $item->id }}')"
                                        wire:loading.attr="disabled"
                                        aria-label="Accept"
                                        class="inline-flex h-9 w-9 cursor-pointer items-center justify-center rounded-full bg-success text-white transition-opacity hover:opacity-90 disabled:pointer-events-none disabled:opacity-50 dark:bg-success-dark"
                                    >@svg('heroicon-s-check', 'h-4 w-4')</button>
                                    <button
                                        type="button"
                                        wire:click="rejectRequest('{{ $item->id }}')"
                                        wire:loading.attr="disabled"
                                        aria-label="Decline"
                                        class="inline-flex h-9 w-9 cursor-pointer items-center justify-center rounded-full bg-surface-sunken text-ink-soft transition-colors hover:text-ink dark:bg-surface-sunken-dark dark:text-ink-soft-dark disabled:pointer-events-none disabled:opacity-50"
                                    >@svg('heroicon-s-x-mark', 'h-4 w-4')</button>
                                </div>
                            @endif
                        </div>
                    @endforeach
                @endif
            @endforeach

            @if ($this->items->isEmpty())
                <div class="flex flex-col items-center gap-2 px-4 py-10 text-ink-faint dark:text-ink-faint-dark">
                    @svg('heroicon-o-bell', 'h-8 w-8')
                    <p class="text-sm font-semibold">Nothing yet</p>
                </div>
            @endif
        </div>
    </x-menu-panel>

    <livewire:notifications.push-toggle />
</div>
