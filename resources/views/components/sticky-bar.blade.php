{{--
    The action row that ends a step — Continue, Finish Mission, and the
    like — with a short hint beside it while the action isn't available
    yet.

    It sits in the page like any ordinary button and scrolls away with
    the rest of the content. It used to be two other things, both asked
    for and then reversed by the learner: first a bar that hid itself
    until the section was finished (the action appeared out of nowhere),
    then a sticky bar pinned to the bottom of the viewport with its own
    full-width translucent strip (which read as a band sliding up and
    down behind the button as the page scrolled). The name stays for
    every caller's sake; there is nothing sticky about it any more.

    $readyWhen drives whether the action is enabled, and whether the
    hint shows — see <x-continue-button>, which wires both for you. A
    one-time accent ring glow (900ms) marks the moment the action
    becomes available, but only on a real false → true flip, so an
    always-enabled action never pulses on page load.

    @param string $readyWhen Raw Alpine expression (or the literal
        string 'true') — evaluated in the caller's own x-data scope,
        same convention as <x-substep-nav>'s $nextDisabled. When the
        caller's readiness can only be known server-side (e.g. a file
        upload), render that condition into this expression as a literal
        (see Activation) rather than hiding the action behind an @if.
    @param string|null $hint Short "what's still missing" text, shown
        beside the action only while $readyWhen is false.
--}}
@props(['readyWhen' => 'true', 'hint' => null])

<div
    x-data="{ justAppeared: false }"
    x-init="$watch(() => ({{ $readyWhen }}), (ready) => {
        if (ready) { justAppeared = true; setTimeout(() => (justAppeared = false), 900) }
    })"
    :class="justAppeared ? 'ring-2 ring-accent ring-offset-4 ring-offset-ground dark:ring-accent-dark dark:ring-offset-ground-dark' : 'ring-0'"
    class="flex flex-wrap items-center gap-x-3 gap-y-1.5 rounded-full transition-shadow duration-700"
>
    {{ $slot }}

    @if ($hint)
        {{-- text-ink-soft, not text-ink-faint (Epic H): this is the answer
             to "why can't I continue" for every step in the app — the
             single most load-bearing instructional string on the page,
             not metadata that can afford low contrast. --}}
        <p
            x-show="! ({{ $readyWhen }})"
            x-cloak
            class="text-xs text-ink-soft dark:text-ink-soft-dark"
        >{{ $hint }}</p>
    @endif
</div>
