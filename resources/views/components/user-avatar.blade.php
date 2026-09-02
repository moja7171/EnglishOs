{{--
    A user's real avatar — their uploaded photo if they have one, their
    chosen illustrated style if they have one, their plain color+initial
    circle otherwise. The one place that decision is made, so every list
    (Friends, a conversation header, the app header) shows the same
    avatar without re-implementing the fallback chain each time.

    @param \App\Models\User $user
--}}
@props(['user'])

@if ($user->avatar_path)
    <img
        src="{{ $user->avatarUrl() }}"
        alt=""
        {{ $attributes->class(['shrink-0 rounded-full object-cover']) }}
    >
@elseif ($user->avatar_style && $user->avatar_style !== 'initial')
    <x-illustrated-avatar :style="$user->avatar_style" :color="$user->avatar_color" {{ $attributes }} />
@else
    <x-avatar-initial :name="$user->name" :color="$user->avatar_color" {{ $attributes }} />
@endif
