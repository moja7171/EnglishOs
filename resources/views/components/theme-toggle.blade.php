{{-- Light/dark switch for the header. window.eosTheme (defined by the inline
     script in the layout <head>) owns the state: it flips the .dark class on
     <html> — which drives every dark: variant, see @custom-variant in
     resources/css/app.css — and remembers the choice. --}}
<button
    type="button"
    x-data="{ dark: window.eosTheme.dark }"
    x-on:click="dark = ! dark; window.eosTheme.set(dark)"
    x-bind:title="dark ? 'Switch to light mode' : 'Switch to dark mode'"
    x-bind:aria-label="dark ? 'Switch to light mode' : 'Switch to dark mode'"
    {{ $attributes->class('inline-flex h-8 w-8 cursor-pointer items-center justify-center rounded-full text-ink-faint transition-colors hover:bg-surface-sunken hover:text-ink dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark dark:hover:text-ink-dark') }}
>
    <span x-show="! dark">@svg('heroicon-o-moon', 'h-4 w-4')</span>
    <span x-show="dark" x-cloak>@svg('heroicon-o-sun', 'h-4 w-4')</span>
</button>
