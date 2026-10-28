{{--
    A password <input> with a show/hide toggle — used by every password
    field (login, register, profile's change-password form) so learners
    can check what they actually typed before submitting.

    @param string $wireModel The Livewire property to bind (e.g. "password").
    @param bool $bare Skip the small default field look; the caller's own
        classes style the input (the big sign-in / sign-up fields).
--}}
@props(['wireModel', 'bare' => false])

<div class="relative" x-data="{ showPassword: false }">
    <input
        type="password"
        x-bind:type="showPassword ? 'text' : 'password'"
        wire:model="{{ $wireModel }}"
        {{ $bare ? $attributes : $attributes->merge(['class' => 'mt-1 w-full rounded-lg border border-line bg-transparent px-2 py-1 pr-9 text-sm text-ink dark:border-line-dark dark:text-ink-dark']) }}
    >
    <button
        type="button"
        x-on:click="showPassword = !showPassword"
        tabindex="-1"
        title="Show/hide password"
        class="absolute inset-y-0 top-1 right-3 flex cursor-pointer items-center text-ink-faint transition-colors hover:text-ink dark:text-ink-faint-dark dark:hover:text-ink-dark"
    >
        <span x-show="!showPassword">@svg('heroicon-o-eye', 'h-4 w-4')</span>
        <span x-show="showPassword" x-cloak>@svg('heroicon-o-eye-slash', 'h-4 w-4')</span>
    </button>
</div>
