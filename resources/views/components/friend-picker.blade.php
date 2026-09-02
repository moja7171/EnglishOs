{{--
    Shared dropdown of mutual-follow friends — the common part of every
    "do this with a friend" bridge (see <x-practice-with-friend> and
    <x-practice-session-with-friend>), which differ only in WHERE each
    friend link actually goes.

    @param string $label Button text, e.g. "Practice this with a friend".
    @param \Closure(\App\Models\User): string $hrefFor Builds the link for one friend.
--}}
@props(['label', 'hrefFor'])

@php $friends = auth()->user()->mutualFriends(); @endphp

<div x-data="{ open: false }" class="relative inline-block">
    <button
        type="button"
        x-on:click="open = !open"
        class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-line px-3 py-1.5 text-xs font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
    >
        @svg('heroicon-o-user-group', 'h-3.5 w-3.5')
        {{ $label }}
    </button>

    <div
        x-show="open"
        x-cloak
        x-on:click.outside="open = false"
        x-transition.opacity.duration.150ms
        class="absolute z-10 mt-1 w-56 space-y-1 rounded-xl border border-line bg-surface p-2 shadow-lg dark:border-line-dark dark:bg-surface-dark"
    >
        @forelse ($friends as $friend)
            <a
                href="{{ $hrefFor($friend) }}"
                wire:navigate
                class="block rounded-lg px-2 py-1.5 text-sm text-ink transition-colors hover:bg-surface-sunken dark:text-ink-dark dark:hover:bg-surface-sunken-dark"
            >{{ $friend->name }}</a>
        @empty
            <p class="px-2 py-1 text-xs text-ink-faint dark:text-ink-faint-dark">No friends to practice with yet.</p>
            <a href="{{ route('friends.index') }}" wire:navigate class="block px-2 py-1 text-xs font-semibold text-accent-ink dark:text-accent-ink-dark">Go to Friends</a>
        @endforelse
    </div>
</div>
