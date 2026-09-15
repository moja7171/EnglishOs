{{--
    A bottom action bar that stays anchored to the viewport as the page
    scrolls past it.

    It is always visible. It used to hide itself entirely until
    $readyWhen became true, so the action appeared out of nowhere the
    moment a section was finished; the learner asked for the opposite —
    the button should always be there, just disabled until they've
    actually completed the section, so they can see from the start what
    finishing this step leads to. $readyWhen therefore now drives the
    button's disabled state (and the hint beside it), not the bar's
    existence — see <x-continue-button>, which wires both for you.

    A one-time accent ring glow (900ms) still marks the moment the action
    becomes available, but only on a real false → true flip: an
    always-enabled bar must not pulse on every page load.

    @param string $readyWhen Raw Alpine expression (or the literal
        string 'true') — evaluated in the caller's own x-data scope,
        same convention as <x-substep-nav>'s $nextDisabled. When the
        caller's readiness can only be known server-side (e.g. a file
        upload), wrap this component in a plain @if instead and leave
        readyWhen at its 'true' default — see EOS-009 §8.
    @param string|null $hint Short "what's still missing" text, shown
        beside the action only while $readyWhen is false.

    The negative margins unwind the mission runner's own `p-6` wrapper
    exactly (see ⚡runner.blade.php) so the bar's edges line up with the
    app's actual content column instead of just the button's own — a
    step rendered somewhere with different ancestor padding would need
    a different offset, but every step currently only ever renders
    inside that one wrapper.
--}}
@props(['readyWhen' => 'true', 'hint' => null])

<div
    x-data="{ justAppeared: false }"
    x-init="$watch(() => ({{ $readyWhen }}), (ready) => {
        if (ready) { justAppeared = true; setTimeout(() => (justAppeared = false), 900) }
    })"
    :class="justAppeared ? 'ring-2 ring-accent dark:ring-accent-dark' : 'ring-0'"
    class="sticky bottom-0 z-10 -mx-6 -mb-6 border-t border-line bg-ground/90 px-6 pt-4 pb-6 backdrop-blur-sm transition-shadow duration-700 dark:border-line-dark dark:bg-ground-dark/90"
>
    <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
        {{ $slot }}

        @if ($hint)
            <p
                x-show="! ({{ $readyWhen }})"
                x-cloak
                class="text-xs text-ink-faint dark:text-ink-faint-dark"
            >{{ $hint }}</p>
        @endif
    </div>
</div>
