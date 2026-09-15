@props(['onClick', 'wireTarget', 'loadingLabel' => 'Please wait…', 'readyWhen' => 'true', 'hint' => null])

{{--
    The Continue that ends a step. An ordinary in-page button that
    scrolls with the content (it was pinned to the bottom of the viewport
    for a while — the learner found the strip behind it distracting as it
    tracked the scroll).

    Always rendered, and disabled until $readyWhen is true (see
    <x-sticky-bar>): the learner can see where the step leads from the
    start instead of the button materialising the moment they finish, and
    a click before then can't reach a save that would only fail anyway.
    $hint says what's still missing while it's disabled.
--}}
<x-sticky-bar :ready-when="$readyWhen" :hint="$hint">
    <button
        type="button"
        x-on:click="{{ $onClick }}"
        x-bind:disabled="! ({{ $readyWhen }})"
        wire:loading.attr="disabled"
        wire:target="{{ $wireTarget }}"
        class="cursor-pointer rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 dark:bg-accent-dark"
    >
        <span wire:loading.remove wire:target="{{ $wireTarget }}">Continue</span>
        <span wire:loading wire:target="{{ $wireTarget }}">{{ $loadingLabel }}</span>
    </button>
</x-sticky-bar>
