{{--
    The avatar in the header: one tap opens Profile. Progress sits on top of
    the Profile page (and in the streak sheet), the guides, the theme and
    sound switches, Admin and Sign out are inside it too, so the header
    stays calm. Friends is a bottom-nav tab with its own count (see
    <x-bottom-nav>).
--}}
<a
    href="{{ route('profile') }}"
    wire:navigate
    aria-label="Profile"
    class="inline-flex h-9 w-9 items-center justify-center rounded-full transition-opacity hover:opacity-80"
>
    <x-user-avatar :user="auth()->user()" class="h-8 w-8 text-xs" />
</a>
