{{--
    The one follow button next to a person who isn't a friend yet: what it
    says and does follows where the two of you stand.

    @param \App\Models\User $user
    @param string $pill The page's pill classes.
--}}
@props(['user', 'pill'])

@if (auth()->user()->isFollowing($user))
    <span class="{{ $pill }} cursor-default bg-surface-sunken text-ink-faint dark:bg-surface-sunken-dark dark:text-ink-faint-dark">Following</span>
@elseif (auth()->user()->hasPendingRequestTo($user))
    <button type="button" wire:click="unfollow({{ $user->id }})" wire:loading.attr="disabled" title="Cancel request" class="{{ $pill }} bg-surface-sunken text-ink-faint dark:bg-surface-sunken-dark dark:text-ink-faint-dark">Requested</button>
@elseif (auth()->user()->hasPendingRequestFrom($user))
    <button type="button" wire:click="acceptRequest({{ $user->id }})" wire:loading.attr="disabled" class="{{ $pill }} bg-success text-white dark:bg-success-dark">Accept request</button>
@else
    <button type="button" wire:click="follow({{ $user->id }})" wire:loading.attr="disabled" class="{{ $pill }} bg-accent text-white dark:bg-accent-dark">Follow</button>
@endif
