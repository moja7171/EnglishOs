{{--
    The button and sheet of mutual-follow friends — the common part of every
    "do this with a friend" bridge (see <x-practice-with-friend> and
    <x-practice-session-with-friend>), which differ only in WHERE each
    friend link actually goes.

    @param string $label Button text, e.g. "Practice this with a friend".
    @param \Closure(\App\Models\User): string $hrefFor Builds the link for one friend.
--}}
@props(['label', 'hrefFor'])

@php $friends = auth()->user()->friendsForPicking(); @endphp

<div x-data="{ open: false }" class="inline-block">
    <button
        type="button"
        x-on:click="open = true"
        class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-line px-3 py-1.5 text-xs font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
    >
        @svg('heroicon-o-user-group', 'h-3.5 w-3.5')
        {{ $label }}
    </button>

    @if ($friends->isNotEmpty())
        <x-friend-sheet show="open" :label="$label" :friends="$friends" :href-for="$hrefFor" />
    @else
        <x-bottom-sheet show="open" :label="$label">
            <p class="text-sm text-ink-faint dark:text-ink-faint-dark">No friends to practice with yet.</p>
            <a href="{{ route('friends.index') }}" wire:navigate class="text-sm font-semibold text-accent-ink dark:text-accent-ink-dark">Go to Friends</a>
        </x-bottom-sheet>
    @endif
</div>
