{{-- The body of a GivesConversationFeedback result: title row with
     refresh + close, then the three feedback cards (or the error). The
     caller owns the wrapper — a sheet over the chat, an inline card on a
     page. `action` is the Livewire method that (re)asks for feedback. --}}
@props(['feedback' => null, 'error' => null, 'action'])

<div class="space-y-3">
    <div class="flex items-center justify-between gap-2">
        <p class="font-fa text-sm font-semibold text-ink dark:text-ink-dark">بازخورد</p>
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
        <div class="rounded-xl border-l-4 border-success bg-success/5 p-3 dark:border-success-dark dark:bg-success-dark/10">
            <p class="flex items-center gap-1.5 text-xs font-semibold text-success uppercase dark:text-success-dark">
                @svg('heroicon-o-check-circle', 'h-4 w-4')
                One thing you did well
            </p>
            <p class="font-fa mt-1 text-sm text-ink dark:text-ink-dark" dir="rtl">{{ $feedback['strength'] }}</p>
        </div>

        <div class="rounded-xl border-l-4 border-accent bg-accent/5 p-3 dark:border-accent-dark dark:bg-accent-dark/10">
            <p class="flex items-center gap-1.5 text-xs font-semibold text-accent uppercase dark:text-accent-dark">
                @svg('heroicon-o-book-open', 'h-4 w-4')
                A good expression you used
            </p>
            <p class="font-fa mt-1 text-sm text-ink dark:text-ink-dark" dir="rtl">{{ $feedback['expression'] }}</p>
        </div>

        @if ($feedback['correction'])
            <div class="rounded-xl border-l-4 border-warning bg-warning-soft p-3">
                <p class="flex items-center gap-1.5 text-xs font-semibold text-warning-ink uppercase">
                    @svg('heroicon-o-exclamation-triangle', 'h-4 w-4')
                    Something to fix
                </p>
                <p class="mt-2 text-sm text-danger-ink line-through decoration-danger">{{ $feedback['correction']['original'] }}</p>
                <p class="mt-1 text-sm text-success dark:text-success-dark">{{ $feedback['correction']['corrected'] }}</p>
                @if ($feedback['correction']['why'])
                    <p class="font-fa mt-2 text-sm text-ink dark:text-ink-dark" dir="rtl">{{ $feedback['correction']['why'] }}</p>
                @endif
                @if ($feedback['correction']['suggestion'])
                    <p class="font-fa mt-1 flex items-start gap-1.5 text-sm text-ink-soft dark:text-ink-soft-dark" dir="rtl">
                        @svg('heroicon-o-arrow-trending-up', 'mt-0.5 h-4 w-4 shrink-0')
                        {{ $feedback['correction']['suggestion'] }}
                    </p>
                @endif
            </div>
        @else
            <p class="text-sm text-ink-soft dark:text-ink-soft-dark">Nothing to fix — nice work.</p>
        @endif
    @else
        <p class="text-sm text-danger-ink">{{ $error }}</p>
    @endif
</div>
