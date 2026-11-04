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
    {{-- Follows the live store, so a new photo shows here at once. --}}
    <span data-shell-avatar class="inline-flex" x-data x-effect="$store.shell.avatar && $el.innerHTML !== $store.shell.avatar && ($el.innerHTML = $store.shell.avatar)">{!! app(App\Services\AppShell::class)->avatar(auth()->user()) !!}</span>
</a>
