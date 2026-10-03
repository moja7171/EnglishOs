{{--
    Shown in place of the report form once a report has been sent, so the
    reporter knows it went through and can block right away (a report is
    only a paper trail; a block is the immediate relief).

    @param \App\Models\User $friend
    @param string $blockAction Livewire call that blocks them.
    @param string $dismissAction Livewire call that hides this notice.
--}}
@props(['friend', 'blockAction', 'dismissAction'])

<div class="mt-2.5 flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl border border-success/40 bg-success/5 p-3 dark:border-success-dark/40 dark:bg-success-dark/10" role="status">
    <p class="flex flex-1 items-center gap-1.5 text-sm text-ink dark:text-ink-dark">
        @svg('heroicon-o-check-circle', 'h-4 w-4 shrink-0 text-success dark:text-success-dark')
        Report sent — thank you. We'll take a look.
    </p>
    <button
        type="button"
        wire:click="{{ $blockAction }}"
        wire:loading.attr="disabled"
        wire:target="{{ $blockAction }}"
        wire:confirm="Block {{ $friend->name }}? They won't be able to message you."
        class="min-h-10 cursor-pointer rounded-full border border-danger-line px-3 text-xs font-semibold text-danger-ink transition-colors hover:bg-danger-soft disabled:pointer-events-none disabled:opacity-50"
    >Also block {{ $friend->name }}</button>
    <button type="button" wire:click="{{ $dismissAction }}" aria-label="Dismiss" class="inline-flex h-10 w-10 cursor-pointer items-center justify-center rounded-full text-ink-faint transition-colors hover:bg-surface-sunken hover:text-ink dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark dark:hover:text-ink-dark">@svg('heroicon-o-x-mark', 'h-4 w-4')</button>
</div>
