<?php

use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
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
     * already uses. The rows themselves don't need a separate read/unread
     * style since the badge is already cleared by the time they're shown.
     */
    public function markAllAsRead(): void
    {
        auth()->user()->unreadNotifications->markAsRead();

        unset($this->items, $this->unreadCount);
    }
};
?>

<div class="relative" x-data="{ open: false }" x-on:click.outside="open = false">
    <button
        type="button"
        x-on:click="open = !open"
        wire:click="markAllAsRead"
        title="Notifications"
        class="relative inline-flex h-8 w-8 cursor-pointer items-center justify-center rounded-full text-ink-faint transition-colors hover:bg-surface-sunken hover:text-ink dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark dark:hover:text-ink-dark"
    >
        @svg('heroicon-o-bell', 'h-4 w-4')
        @if ($this->unreadCount)
            <span class="absolute -top-0.5 -right-0.5 inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-accent px-1 text-[10px] font-bold text-white dark:bg-accent-dark">{{ $this->unreadCount }}</span>
        @endif
    </button>

    <div
        x-show="open"
        x-cloak
        x-transition.opacity.duration.150ms
        class="absolute right-0 z-20 mt-2 w-72 overflow-hidden rounded-xl border border-line bg-surface shadow-lg dark:border-line-dark dark:bg-surface-dark"
    >
        <p class="border-b border-line px-3 py-2 text-xs font-semibold tracking-wide text-ink-faint uppercase dark:border-line-dark dark:text-ink-faint-dark">Notifications</p>

        <div class="max-h-80 overflow-y-auto">
            @forelse ($this->items as $item)
                <a
                    href="{{ $item->data['url'] }}"
                    wire:navigate
                    x-on:click="open = false"
                    class="flex items-start gap-2.5 border-b border-line px-3 py-2.5 text-xs transition-colors last:border-b-0 hover:bg-surface-sunken dark:border-line-dark dark:hover:bg-surface-sunken-dark"
                >
                    <span class="mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">
                        @svg($item->data['icon'], 'h-3.5 w-3.5')
                    </span>
                    <span>
                        <span class="block font-semibold text-ink dark:text-ink-dark">{{ $item->data['title'] }}</span>
                        <span class="mt-0.5 block text-ink-faint dark:text-ink-faint-dark">{{ $item->created_at->diffForHumans() }}</span>
                    </span>
                </a>
            @empty
                <p class="px-3 py-6 text-center text-xs text-ink-faint dark:text-ink-faint-dark">Nothing yet — you'll see friend activity and streak badges here.</p>
            @endforelse
        </div>
    </div>
</div>
