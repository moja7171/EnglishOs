{{--
    Shared compact "sub-step" pager for a step page with 2+ distinct
    phases (e.g. Listening's First/Second/Third listening) — lets a step
    page show one focused phase at a time instead of stacking everything
    into one long scroll.

    Icon-only (no "Next"/"Back" words, just a title tooltip) — the
    mission-level Previous/Next at the bottom of the page uses those exact
    words for a completely different scope (moving between mission STEPS,
    not sub-steps within one), and having both say "Next" is what made the
    two easy to confuse (see EOS-009 §8's button-philosophy pass). The
    adjacent "Part X of Y" label this pairs with already gives the count/
    context a word would otherwise carry.

    @param string $indexVar The Alpine variable name (in the caller's own
        x-data, on the same element or an ancestor) that holds the current
        0-based sub-step index — this component reads/writes it directly
        by name, so it must already exist there.
    @param int $total How many sub-steps there are. Next hides itself
        entirely once the last one is reached (nothing further to advance
        to) rather than sitting there disabled next to a real Continue/
        submit action.
    @param string $nextDisabled Raw Alpine expression (evaluated in
        addition to "not yet at the last sub-step") — e.g. "!gistDone" to
        also require some in-page condition before advancing. Optional;
        defaults to always allowed.
--}}
@props(['indexVar', 'total', 'nextDisabled' => 'false'])

<div class="inline-flex items-center gap-1 rounded-full border border-line bg-surface-sunken p-1 dark:border-line-dark dark:bg-surface-sunken-dark">
    <button
        type="button"
        x-on:click="{{ $indexVar }}--"
        :disabled="{{ $indexVar }} === 0"
        title="Back"
        class="inline-flex h-8 w-8 cursor-pointer items-center justify-center rounded-full text-ink-soft transition-colors hover:bg-surface disabled:pointer-events-none disabled:opacity-30 dark:text-ink-soft-dark dark:hover:bg-surface-dark"
    >@svg('heroicon-o-chevron-left', 'h-3.5 w-3.5')</button>

    <button
        type="button"
        x-show="{{ $indexVar }} < {{ $total - 1 }}"
        x-on:click="{{ $indexVar }}++"
        :disabled="{{ $nextDisabled }}"
        title="Next"
        class="inline-flex h-8 w-8 cursor-pointer items-center justify-center rounded-full text-ink-soft transition-colors hover:bg-surface disabled:pointer-events-none disabled:opacity-30 dark:text-ink-soft-dark dark:hover:bg-surface-dark"
    >@svg('heroicon-o-chevron-right', 'h-3.5 w-3.5')</button>
</div>
