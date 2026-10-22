{{-- The body of a GivesConversationFeedback result: title row with
     refresh + close, then <x-learner-feedback> (or the error). The
     caller owns the wrapper — a sheet over the chat, an inline card on a
     page. `action` is the Livewire method that (re)asks for feedback. --}}
@props(['feedback' => null, 'error' => null, 'action'])

<div class="space-y-3">
    <div class="flex items-center justify-between gap-2">
        <p class="text-sm font-semibold text-ink dark:text-ink-dark">Feedback</p>
        <div class="flex items-center gap-1">
            @if ($feedback)
                <button
                    type="button"
                    wire:click="{{ $action }}"
                    wire:loading.attr="disabled"
                    wire:target="{{ $action }}"
                    aria-label="Refresh feedback"
                    title="Refresh feedback"
                    class="inline-flex h-10 w-10 shrink-0 cursor-pointer items-center justify-center rounded-full text-ink-faint transition-colors hover:bg-surface-sunken hover:text-ink dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark dark:hover:text-ink-dark disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="{{ $action }}">@svg('heroicon-o-arrow-path', 'h-4 w-4')</span>
                    <span wire:loading wire:target="{{ $action }}">@svg('heroicon-o-arrow-path', 'h-4 w-4 animate-spin')</span>
                </button>
            @endif
            <button
                type="button"
                wire:click="dismissFeedback"
                aria-label="Close feedback"
                title="Close"
                class="inline-flex h-10 w-10 shrink-0 cursor-pointer items-center justify-center rounded-full text-ink-faint transition-colors hover:bg-surface-sunken hover:text-ink dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark dark:hover:text-ink-dark"
            >@svg('heroicon-o-x-mark', 'h-4 w-4')</button>
        </div>
    </div>

    @if ($feedback)
        <x-learner-feedback :feedback="$feedback" />
    @else
        <p class="text-sm text-danger-ink">{{ $error }}</p>
    @endif
</div>
