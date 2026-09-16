{{--
    S6 of [[project_growth_without_discouragement_stories]]: a difficulty
    axis a learner can pick up, never one handed to them. Everything the
    silent taper (S4) already removes stays removed silently; this is the
    genuinely intimidating layer on top — no starters at all, no time to
    prepare — and it only ever appears in the last third of the roadmap,
    offered as a choice.

    Purely client-side: no wire:click, no server round-trip, because
    accepting or declining changes nothing about what the step requires
    (T6.4) and there is nothing here worth a round-trip for. $model is the
    name of a boolean already declared in an ANCESTOR x-data (not this
    component's own — accepting has to be visible to whatever content the
    challenge actually affects, which lives outside this component).
--}}
@props(['model', 'label', 'acceptLabel' => 'Try it', 'declineLabel' => 'No thanks'])

<div
    x-data="{ dismissed: false }"
    x-show="! {{ $model }} && ! dismissed"
    x-cloak
    class="rounded-xl border border-dashed border-line bg-surface-sunken p-3 dark:border-line-dark dark:bg-surface-sunken-dark"
>
    <p class="inline-flex items-center gap-1.5 text-sm font-semibold text-ink dark:text-ink-dark">
        @svg('heroicon-o-bolt', 'h-4 w-4 text-ink-faint dark:text-ink-faint-dark')
        {{ $label }}
    </p>
    <div class="mt-2 flex gap-2">
        <button
            type="button"
            x-on:click="{{ $model }} = true"
            class="cursor-pointer rounded-full bg-ink px-3 py-1 text-xs font-semibold text-ground transition-colors hover:opacity-85 dark:bg-ink-dark dark:text-ground-dark"
        >{{ $acceptLabel }}</button>
        <button
            type="button"
            x-on:click="dismissed = true"
            class="cursor-pointer rounded-full border border-line px-3 py-1 text-xs font-semibold text-ink-soft transition-colors hover:bg-surface dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-dark"
        >{{ $declineLabel }}</button>
    </div>
</div>
