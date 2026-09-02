{{--
    A lightweight bridge from a mission's conversation question to the
    Friends messaging system — deliberately NOT part of Evidence Before
    Progress (Article 3): it never records anything for the mission run,
    just opens the friend's conversation page with the question pre-filled
    in the composer, ready to edit or send. The learner still has to
    answer the AI themselves for the step to advance; this is purely an
    optional way to also practice the same question with a real person.

    @param string $text The question/prompt to share.
--}}
@props(['text'])

@php $friends = auth()->user()->mutualFriends(); @endphp

<div x-data="{ open: false }" class="relative inline-block">
    <button
        type="button"
        x-on:click="open = !open"
        class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-line px-3 py-1.5 text-xs font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
    >
        @svg('heroicon-o-user-group', 'h-3.5 w-3.5')
        Practice this with a friend
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
                href="{{ route('friends.conversation', ['user' => $friend, 'prefill' => "Hey — want to help me practice this: \"{$text}\""]) }}"
                wire:navigate
                class="block rounded-lg px-2 py-1.5 text-sm text-ink transition-colors hover:bg-surface-sunken dark:text-ink-dark dark:hover:bg-surface-sunken-dark"
            >{{ $friend->name }}</a>
        @empty
            <p class="px-2 py-1 text-xs text-ink-faint dark:text-ink-faint-dark">No friends to practice with yet.</p>
            <a href="{{ route('friends.index') }}" wire:navigate class="block px-2 py-1 text-xs font-semibold text-accent-ink dark:text-accent-ink-dark">Go to Friends</a>
        @endforelse
    </div>
</div>
