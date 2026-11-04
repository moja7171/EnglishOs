{{--
    One friend in <x-friend-sheet>: photo, name and @username.

    @param \App\Models\User $friend
--}}
@props(['friend'])

<x-user-avatar :user="$friend" class="h-10 w-10 text-xs" />
<span class="min-w-0 flex-1">
    <span class="block truncate text-sm font-extrabold text-ink dark:text-ink-dark">{{ $friend->name }}</span>
    <span class="block truncate text-xs font-semibold text-ink-faint dark:text-ink-faint-dark"><bdi>{{ $friend->handle() }}</bdi></span>
</span>
