{{-- Light/dark switch for the header. The .dark class on <html> drives every
     dark: variant (see @custom-variant in resources/css/app.css); the inline
     script in the layout <head> applies the saved choice (or the OS
     preference) before first paint, and this button just flips it and
     remembers the choice. --}}
<button
    type="button"
    x-data="{ dark: document.documentElement.classList.contains('dark') }"
    x-on:click="
        dark = ! dark;
        document.documentElement.classList.toggle('dark', dark);
        try { localStorage.setItem('eosTheme', dark ? 'dark' : 'light') } catch (e) {}
        document.querySelector('meta[name=theme-color]')?.setAttribute('content', dark ? '#0f0d21' : '#211d3f');
    "
    x-bind:title="dark ? 'Switch to light mode' : 'Switch to dark mode'"
    x-bind:aria-label="dark ? 'Switch to light mode' : 'Switch to dark mode'"
    {{ $attributes->class('inline-flex h-8 w-8 cursor-pointer items-center justify-center rounded-full text-ink-faint transition-colors hover:bg-surface-sunken hover:text-ink dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark dark:hover:text-ink-dark') }}
>
    <span x-show="! dark">@svg('heroicon-o-moon', 'h-4 w-4')</span>
    <span x-show="dark" x-cloak>@svg('heroicon-o-sun', 'h-4 w-4')</span>
</button>
