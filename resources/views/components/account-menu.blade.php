{{--
    The avatar in the header and the dropdown it opens (anchored under the
    avatar, like the account menu it replaces — not a bottom sheet): everything
    secondary to the three bottom-nav tabs — Friends, Listening, profile,
    guides, the theme and sound switches and Sign out.

    Friends lives here because the social side is secondary, so its
    unread-message and follow-request count rides on the avatar as a dot
    instead of being lost. Listening lives here (it is also the "Listen
    today" card on Home) now that it is no longer a tab.

    It lives in the header, not the bottom nav, so it stays reachable on
    the focus screens (a mission, a chat) where the bottom nav is hidden.
--}}
@php
    $user = auth()->user();

    // Unread *conversations* (not messages) plus pending requests — the same
    // unit the bell uses, and never counting someone you can no longer message.
    $friendsBadge = $user->friendsBadgeCount();

    $menuLinkClass = 'flex items-center gap-3 px-4 py-3 text-sm font-semibold text-ink-soft transition-colors hover:bg-surface-sunken hover:text-ink dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark dark:hover:text-ink-dark';
@endphp

<div class="relative" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false" x-data="{ open: false, soundEnabled: true, dark: window.eosTheme.dark }" x-init="soundEnabled = (() => { try { return localStorage.getItem('eosSoundEnabled') !== 'false' } catch (e) { return true } })()">
    <button
        type="button"
        x-on:click="open = ! open"
        aria-haspopup="menu"
        aria-label="Account menu"
        class="relative inline-flex h-8 w-8 cursor-pointer items-center justify-center rounded-full transition-opacity hover:opacity-80"
    >
        <x-user-avatar :user="$user" class="h-7 w-7 text-[11px]" />
        @if ($friendsBadge)
            <span class="absolute -top-0.5 -right-0.5 h-2.5 w-2.5 rounded-full border-2 border-ground bg-accent dark:border-ground-dark dark:bg-accent-dark" title="{{ $friendsBadge }} new in Friends"></span>
        @endif
    </button>

    <div
        x-show="open"
        x-cloak
        x-transition.opacity.duration.150ms
        role="menu"
        aria-label="Account menu"
        class="absolute right-0 z-40 mt-2 w-64 overflow-hidden rounded-xl border border-line bg-surface py-1 text-left shadow-lg dark:border-line-dark dark:bg-surface-dark"
    >
        <div class="flex items-center gap-3 border-b border-line px-4 py-2.5 dark:border-line-dark">
            <x-user-avatar :user="$user" class="h-8 w-8 text-xs" />
            <p class="flex-1 truncate text-sm font-semibold text-ink dark:text-ink-dark">{{ $user->name }}</p>
        </div>

        <a href="{{ route('friends.index') }}" wire:navigate x-on:click="open = false" class="{{ $menuLinkClass }}">
            @svg('heroicon-o-user-group', 'h-5 w-5')
            <span class="flex-1">Friends</span>
            @if ($friendsBadge)
                <span class="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-accent px-1.5 text-[11px] font-bold text-white dark:bg-accent-dark">{{ $friendsBadge }}</span>
            @endif
        </a>
        <a href="{{ route('listening.show') }}" wire:navigate x-on:click="open = false" class="{{ $menuLinkClass }}">
            @svg('heroicon-o-speaker-wave', 'h-5 w-5') Listening
        </a>
        <a href="{{ route('pi.practice') }}" wire:navigate x-on:click="open = false" class="{{ $menuLinkClass }}">
            @svg('heroicon-o-microphone', 'h-5 w-5') Voice practice
        </a>
        <a href="{{ route('profile') }}" wire:navigate x-on:click="open = false" class="{{ $menuLinkClass }}">
            @svg('heroicon-o-user-circle', 'h-5 w-5') Profile &amp; settings
        </a>
        <a href="{{ route('learner.guide') }}" wire:navigate x-on:click="open = false" class="{{ $menuLinkClass }}">
            @svg('heroicon-o-book-open', 'h-5 w-5') Learner guide
        </a>
        <a href="{{ route('pi.setup') }}" wire:navigate x-on:click="open = false" class="{{ $menuLinkClass }}">
            @svg('heroicon-o-chat-bubble-left-right', 'h-5 w-5') Voice practice setup
        </a>

        <div class="my-1 border-t border-line dark:border-line-dark"></div>

        <button
            type="button"
            x-on:click="dark = ! dark; window.eosTheme.set(dark)"
            class="{{ $menuLinkClass }} w-full cursor-pointer text-left"
        >
            <span x-show="! dark">@svg('heroicon-o-moon', 'h-5 w-5')</span>
            <span x-show="dark" x-cloak>@svg('heroicon-o-sun', 'h-5 w-5')</span>
            <span x-text="dark ? 'Switch to light mode' : 'Switch to dark mode'"></span>
        </button>
        <button
            type="button"
            x-on:click="soundEnabled = ! soundEnabled; try { localStorage.setItem('eosSoundEnabled', soundEnabled) } catch (e) {}"
            class="{{ $menuLinkClass }} w-full cursor-pointer text-left"
        >
            <span x-show="soundEnabled">@svg('heroicon-o-speaker-wave', 'h-5 w-5')</span>
            <span x-show="! soundEnabled" x-cloak>@svg('heroicon-o-speaker-x-mark', 'h-5 w-5')</span>
            <span x-text="soundEnabled ? 'Mute sound effects' : 'Unmute sound effects'"></span>
        </button>

        <div class="my-1 border-t border-line dark:border-line-dark"></div>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button
                type="submit"
                class="flex w-full cursor-pointer items-center gap-3 px-4 py-3 text-left text-sm font-semibold text-ink-soft transition-colors hover:bg-danger-soft hover:text-danger-ink dark:text-ink-soft-dark"
            >@svg('heroicon-o-arrow-right-start-on-rectangle', 'h-5 w-5') Sign out</button>
        </form>
    </div>
</div>
