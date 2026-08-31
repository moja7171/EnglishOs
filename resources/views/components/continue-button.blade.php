@props(['onClick', 'wireTarget', 'loadingLabel' => 'Please wait…'])

<button
    x-on:click="{{ $onClick }}"
    wire:loading.attr="disabled"
    wire:target="{{ $wireTarget }}"
    class="cursor-pointer rounded bg-neutral-900 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-neutral-700 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 dark:bg-white dark:text-neutral-900 dark:hover:bg-neutral-200"
>
    <span wire:loading.remove wire:target="save">Continue</span>
    <span wire:loading wire:target="save">{{ $loadingLabel }}</span>
</button>
