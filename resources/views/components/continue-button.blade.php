@props(['onClick', 'wireTarget', 'loadingLabel' => 'Please wait…', 'readyWhen' => 'true', 'hint' => null])

{{--
    The Continue that ends a step — full width and tall, like the main
    button of a lesson in a learning app. An ordinary in-page button that
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
        class="w-full cursor-pointer rounded-2xl bg-accent py-3.5 text-base font-bold text-white shadow-[0_4px_0_color-mix(in_srgb,var(--color-accent)_60%,black)] transition-colors hover:opacity-90 disabled:pointer-events-none disabled:cursor-not-allowed disabled:bg-line disabled:text-ink-faint disabled:shadow-none dark:bg-accent-dark dark:disabled:bg-line-dark dark:disabled:text-ink-faint-dark"
    >
        <span wire:loading.remove wire:target="{{ $wireTarget }}">Continue</span>
        <span wire:loading wire:target="{{ $wireTarget }}">{{ $loadingLabel }}</span>
    </button>
</x-sticky-bar>
