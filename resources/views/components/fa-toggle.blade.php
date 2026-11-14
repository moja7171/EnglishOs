{{--
    The "ترجمه فارسی" chip: shows or hides the Persian line under every
    English sentence of a synced text (Alpine store "fa", kept per browser).
    Put it beside "Hide text"; render it only when the text has translations
    (App\Services\WebVttParser::hasTranslation).

    @param bool $cloak Hidden until Alpine starts (its text starts closed).
--}}
@props(['cloak' => false])

<button
    type="button"
    @if ($cloak) x-cloak @endif
    x-on:click="$store.fa.toggle()"
    x-bind:aria-pressed="$store.fa.on"
    x-bind:class="$store.fa.on ? 'border-sky-700 bg-sky-100 text-sky-800 dark:border-sky-300 dark:bg-sky-950 dark:text-sky-300' : 'border-line text-ink-soft hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark'"
    {{ $attributes->class('font-fa inline-flex cursor-pointer items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold transition-colors') }}
>
    <span x-show="$store.fa.on" x-cloak>@svg('heroicon-s-check', 'h-3.5 w-3.5')</span>
    ترجمه فارسی
</button>
