@props(['onClick', 'wireTarget', 'loadingLabel' => 'Please wait…', 'readyWhen' => 'true', 'hint' => null])

{{--
    Sticky to the viewport bottom (not just the end of the page content)
    so a long single-scroll step (Reading Comprehension, Video Shadowing)
    never buries its own Continue button somewhere the learner has to go
    hunting for — see EOS-009 §8's UI/UX review.

    Always visible, and disabled until $readyWhen is true (see
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
