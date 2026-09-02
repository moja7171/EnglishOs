{{--
    A user's real avatar — their uploaded photo if they have one, their
    chosen color+initial circle otherwise. The one place that decision is
    made, so every list (Friends, a conversation header, the app header)
    shows the same avatar without re-implementing the photo/initial
    fallback each time.

    @param \App\Models\User $user
--}}
@props(['user'])

@if ($user->avatar_path)
    <img
        src="{{ $user->avatarUrl() }}"
        alt=""
        {{ $attributes->class(['shrink-0 rounded-full object-cover']) }}
    >
@else
    <x-avatar-initial :name="$user->name" :color="$user->avatar_color" {{ $attributes }} />
@endif
