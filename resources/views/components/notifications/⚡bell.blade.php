<?php

use App\Models\User;
use Livewire\Attributes\Computed;
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

    #[Computed]
    public function items()
    {
        return auth()->user()->notifications()->limit(15)->get();
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

        unset($this->items, $this->unreadCount);
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

        unset($this->items, $this->unreadCount, $this->pendingRequesterIds);
    }

    /**
     * No history is kept on purpose — a cleared bell is a deleted bell.
     */
    public function clearAll(): void
    {
        auth()->user()->notifications()->delete();

        $this->freshIds = [];

        unset($this->items, $this->unreadCount);
    }
};
?>

<div class="relative" x-data="{ open: false }" x-on:click.outside="open = false" wire:poll.30s.visible>
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

    <div
        x-show="open"
        x-cloak
        x-transition.origin.top.right.scale.95.opacity.duration.150ms
        class="absolute right-0 z-20 mt-2 w-72 overflow-hidden rounded-xl border border-line bg-surface shadow-lg dark:border-line-dark dark:bg-surface-dark"
    >
        <div class="flex items-center justify-between border-b border-line px-3 py-2 dark:border-line-dark">
            <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Notifications</p>
            @if ($this->items->isNotEmpty())
                <button type="button" wire:click="clearAll" class="cursor-pointer text-xs font-semibold text-accent-ink transition-colors hover:underline dark:text-accent-ink-dark">Clear all</button>
            @endif
        </div>

        <div class="max-h-80 overflow-y-auto">
            @forelse ($this->items as $item)
                <div
                    wire:key="notification-{{ $item->id }}"
                    @class([
                        'border-b border-line px-3 py-2.5 text-xs transition-colors last:border-b-0 dark:border-line-dark',
                        'bg-accent-soft/40 dark:bg-accent-soft-dark/40' => in_array($item->id, $this->freshIds, true),
                    ])
                >
                    <a
                        href="{{ $item->data['url'] }}"
                        wire:navigate
                        x-on:click="open = false"
                        class="flex items-start gap-2.5"
                    >
                        <span class="mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">
                            @svg($item->data['icon'], 'h-3.5 w-3.5')
                        </span>
                        <span>
                            <span class="block font-semibold text-ink dark:text-ink-dark">{{ $item->data['title'] }}</span>
                            <span class="mt-0.5 block text-ink-faint dark:text-ink-faint-dark">{{ $item->created_at->diffForHumans() }}</span>
                        </span>
                    </a>
                    @if (isset($item->data['follower_id']) && in_array($item->data['follower_id'], $this->pendingRequesterIds, true))
                        <div class="mt-2 flex gap-2 pl-8">
                            <button
                                type="button"
                                wire:click="acceptRequest('{{ $item->id }}')"
                                wire:loading.attr="disabled"
                                class="cursor-pointer rounded-full bg-accent px-3 py-1 text-xs font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50"
                            >Accept</button>
                            <button
                                type="button"
                                wire:click="rejectRequest('{{ $item->id }}')"
                                wire:loading.attr="disabled"
                                class="cursor-pointer rounded-full border border-line px-3 py-1 text-xs font-semibold text-ink-faint transition-colors hover:bg-surface-sunken dark:border-line-dark dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50"
                            >Decline</button>
                        </div>
                    @endif
                </div>
            @empty
                <p class="px-3 py-6 text-center text-xs text-ink-faint dark:text-ink-faint-dark">Nothing yet — you'll see friend activity and streak badges here.</p>
            @endforelse
        </div>

        <livewire:notifications.push-toggle />
    </div>
</div>
