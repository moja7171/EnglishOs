@props(['onClick', 'wireTarget', 'loadingLabel' => 'Please wait…'])

<button
    x-on:click="{{ $onClick }}"
    wire:loading.attr="disabled"
    wire:target="{{ $wireTarget }}"
    class="cursor-pointer rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 dark:bg-accent-dark"
>
    <span wire:loading.remove wire:target="save">Continue</span>
    <span wire:loading wire:target="save">{{ $loadingLabel }}</span>
</button>
