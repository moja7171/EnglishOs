{{--
    Shared compact "sub-step" pager for a step page with 2+ distinct
    phases (e.g. Listening's First/Second/Third listening, Active Recall's
    3 sections) — lets a step page show one focused phase at a time
    instead of stacking everything into one long scroll.

    Deliberately styled as a small muted grouped pill, NOT the app's bold
    filled-pill "Next" language used for the mission-level Previous/Next
    at the bottom of the page — that's what keeps the two from being
    confused with each other (see EOS-009 §8, and the Grammar in Context
    lesson stepper this pattern was lifted from).

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
        class="inline-flex cursor-pointer items-center gap-1 rounded-full px-3 py-1.5 text-xs font-semibold text-ink-soft transition-colors hover:bg-surface disabled:pointer-events-none disabled:opacity-30 dark:text-ink-soft-dark dark:hover:bg-surface-dark"
    >@svg('heroicon-o-chevron-left', 'h-3.5 w-3.5') Back</button>

    <button
        type="button"
        x-show="{{ $indexVar }} < {{ $total - 1 }}"
        x-on:click="{{ $indexVar }}++"
        :disabled="{{ $nextDisabled }}"
        class="inline-flex cursor-pointer items-center gap-1 rounded-full px-3 py-1.5 text-xs font-semibold text-ink-soft transition-colors hover:bg-surface disabled:pointer-events-none disabled:opacity-30 dark:text-ink-soft-dark dark:hover:bg-surface-dark"
    >Next @svg('heroicon-o-chevron-right', 'h-3.5 w-3.5')</button>
</div>
