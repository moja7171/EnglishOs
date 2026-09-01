{{-- Shown after 3 failed check attempts on one field — see
     App\Livewire\Concerns\TracksCheckAttempts. The one place in the app
     that offers to write the correction directly, and only once the
     learner has genuinely tried three times. --}}
@props(['show', 'revealMethod', 'declineMethod', 'index', 'wireTarget'])

@if ($show)
    <div class="mt-2 rounded-xl border border-accent-soft bg-accent-soft/60 px-3 py-2 dark:border-accent-soft-dark dark:bg-accent-soft-dark/60">
        <p class="text-sm text-accent-ink dark:text-accent-ink-dark">Want me to write the correct one for you?</p>
        <div class="mt-2 flex gap-2">
            <button
                type="button"
                wire:click="{{ $revealMethod }}({{ $index }})"
                wire:loading.attr="disabled"
                wire:target="{{ $wireTarget }}"
                class="cursor-pointer rounded-full bg-ink px-3 py-1 text-xs font-semibold text-ground transition-colors hover:opacity-85 disabled:pointer-events-none disabled:opacity-50 dark:bg-ink-dark dark:text-ground-dark"
            >Yes, show me</button>
            <button
                type="button"
                wire:click="{{ $declineMethod }}({{ $index }})"
                wire:loading.attr="disabled"
                wire:target="{{ $wireTarget }}"
                class="cursor-pointer rounded-full border border-accent-ink/30 px-3 py-1 text-xs font-semibold text-accent-ink transition-colors hover:bg-accent-soft disabled:pointer-events-none disabled:opacity-50 dark:border-accent-ink-dark/30 dark:text-accent-ink-dark dark:hover:bg-accent-soft-dark"
            >No, I'll keep trying</button>
        </div>
    </div>
@endif
