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

<div class="inline-flex flex-wrap items-center gap-1 rounded-full border border-line bg-surface-sunken p-1 dark:border-line-dark dark:bg-surface-sunken-dark">
    @foreach ($tabs as $key => $label)
        <button
            type="button"
            x-on:click="{{ $tabVar }} = '{{ $key }}'"
            :class="{{ $tabVar }} === '{{ $key }}'
                ? 'bg-surface text-ink shadow-sm dark:bg-surface-dark dark:text-ink-dark'
                : 'text-ink-soft hover:text-ink dark:text-ink-soft-dark dark:hover:text-ink-dark'"
            class="cursor-pointer rounded-full px-3 py-1.5 text-xs font-semibold transition-colors"
        >{{ $label }}</button>
    @endforeach
</div>
