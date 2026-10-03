{{--
    A click-to-select pill tab bar — same grouped-pill visual family as
    <x-substep-nav>, but for jumping directly to any section instead of
    only stepping through them in order (a settings page's sections
    aren't a sequence, so Back/Next doesn't fit the way it does for a
    mission sub-step).

    @param string $tabVar The Alpine variable name (in the caller's own
        x-data, on the same element or an ancestor) holding the active
        tab's key — this component reads/writes it directly by name, so
        it must already exist there.
    @param array<string, string> $tabs Tab key => label.
--}}
@props(['tabVar', 'tabs'])

{{-- One row that never wraps: tabs share the width and tighten their padding
     on a phone, and if there are still too many for the screen the row
     scrolls sideways instead of dropping the last tab onto a second line. --}}
<div class="flex w-full items-center gap-1 overflow-x-auto rounded-full border border-line bg-surface-sunken p-1 [scrollbar-width:none] sm:w-auto sm:inline-flex dark:border-line-dark dark:bg-surface-sunken-dark">
    @foreach ($tabs as $key => $label)
        <button
            type="button"
            x-on:click="{{ $tabVar }} = '{{ $key }}'"
            :class="{{ $tabVar }} === '{{ $key }}'
                ? 'bg-surface text-ink shadow-sm dark:bg-surface-dark dark:text-ink-dark'
                : 'text-ink-soft hover:text-ink dark:text-ink-soft-dark dark:hover:text-ink-dark'"
            class="flex-1 cursor-pointer rounded-full px-2 py-1.5 text-center text-xs font-semibold whitespace-nowrap transition-colors sm:flex-none sm:px-3"
        >{{ $label }}</button>
    @endforeach
</div>
