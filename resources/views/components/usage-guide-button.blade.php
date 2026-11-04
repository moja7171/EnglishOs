{{--
    The ⓘ that opens the page's <livewire:usage-guide> again.

    @param bool $onPhoto On a mission banner's photo (white, no accent chip).
    @param string $label The button's accessible name.
--}}
@props(['onPhoto' => false, 'label' => 'How to use this'])

<button
    type="button"
    x-data
    x-on:click="$dispatch('usage-guide-open')"
    aria-label="{{ $label }}"
    {{ $attributes->class([
        'inline-flex h-9 w-9 shrink-0 cursor-pointer items-center justify-center rounded-full transition-colors',
        'text-white/85 hover:bg-white/15 hover:text-white' => $onPhoto,
        'text-ink-soft hover:bg-surface-sunken hover:text-ink dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark dark:hover:text-ink-dark' => ! $onPhoto,
    ]) }}
>@svg('heroicon-o-information-circle', 'h-6 w-6')</button>
