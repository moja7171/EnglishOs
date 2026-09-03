{{--
    A bottom action bar that stays anchored to the viewport as the page
    scrolls past it — hidden entirely until $readyWhen is true (the
    learner asked for this explicitly: a Continue that's always visible
    but might just error out on click "نگاه‌داشتنی نیست", felt like
    noise). Once it appears, a short fade/slide-in plus a one-time accent
    ring glow (900ms) makes the "you can move on now" moment obvious,
    without looping forever like a nagging animation would.

    @param string $readyWhen Raw Alpine expression (or the literal
        string 'true') — evaluated in the caller's own x-data scope,
        same convention as <x-substep-nav>'s $nextDisabled. When the
        caller's readiness can only be known server-side (e.g. a file
        upload), wrap this component in a plain @if instead and leave
        readyWhen at its 'true' default — see EOS-009 §8.

    The negative margins unwind the mission runner's own `p-6` wrapper
    exactly (see ⚡runner.blade.php) so the bar's edges line up with the
    app's actual content column instead of just the button's own — a
    step rendered somewhere with different ancestor padding would need
    a different offset, but every step currently only ever renders
    inside that one wrapper.
--}}
@props(['readyWhen' => 'true'])

@php
    // Fires the reveal pulse both when this bar mounts already-ready
    // (a plain @if-gated caller — the element simply didn't exist in the
    // DOM before, so there's no earlier "false" state to watch for) and
    // when a live Alpine condition flips from false to true later.
    $pulseOnce = "justAppeared = true; setTimeout(() => (justAppeared = false), 900)";
@endphp
<div
    x-data="{ justAppeared: false }"
    x-init="if ({{ $readyWhen }}) { {{ $pulseOnce }} }; $watch(() => ({{ $readyWhen }}), (ready) => { if (ready) { {{ $pulseOnce }} } })"
    x-show="{{ $readyWhen }}"
    x-transition:enter="transition ease-out duration-300"
    x-transition:enter-start="translate-y-3 opacity-0"
    x-transition:enter-end="translate-y-0 opacity-100"
    x-cloak
    :class="justAppeared ? 'ring-2 ring-accent dark:ring-accent-dark' : 'ring-0'"
    class="sticky bottom-0 z-10 -mx-6 -mb-6 border-t border-line bg-ground/90 px-6 pt-4 pb-6 backdrop-blur-sm transition-shadow duration-700 dark:border-line-dark dark:bg-ground-dark/90"
>
    {{ $slot }}
</div>
