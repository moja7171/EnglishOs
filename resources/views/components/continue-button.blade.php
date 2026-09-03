@props(['onClick', 'wireTarget', 'loadingLabel' => 'Please wait…'])

{{--
    Sticky to the viewport bottom (not just the end of the page content)
    so a long single-scroll step (Reading Comprehension, Video Shadowing)
    never buries its own Continue button somewhere the learner has to go
    hunting for — see EOS-009 §8's UI/UX review. The negative margins
    unwind the runner's own p-6 wrapper exactly, so the bar's edges line
    up with the app's content column, not the raw button's.
--}}
<x-sticky-bar>
    <button
        x-on:click="{{ $onClick }}"
        wire:loading.attr="disabled"
        wire:target="{{ $wireTarget }}"
        class="cursor-pointer rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 dark:bg-accent-dark"
    >
        <span wire:loading.remove wire:target="save">Continue</span>
        <span wire:loading wire:target="save">{{ $loadingLabel }}</span>
    </button>
</x-sticky-bar>
