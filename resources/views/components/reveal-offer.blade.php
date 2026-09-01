{{-- Shown after 3 failed check attempts on one field — see
     App\Livewire\Concerns\TracksCheckAttempts. The one place in the app
     that offers to write the correction directly, and only once the
     learner has genuinely tried three times. --}}
@props(['show', 'revealMethod', 'declineMethod', 'index', 'wireTarget'])

@if ($show)
    <div class="mt-2 rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 dark:border-blue-900 dark:bg-blue-950">
        <p class="text-sm text-blue-700 dark:text-blue-400">Want me to write the correct one for you?</p>
        <div class="mt-2 flex gap-2">
            <button
                type="button"
                wire:click="{{ $revealMethod }}({{ $index }})"
                wire:loading.attr="disabled"
                wire:target="{{ $wireTarget }}"
                class="cursor-pointer rounded bg-blue-600 px-3 py-1 text-xs font-semibold text-white transition-colors hover:bg-blue-700 disabled:pointer-events-none disabled:opacity-50"
            >Yes, show me</button>
            <button
                type="button"
                wire:click="{{ $declineMethod }}({{ $index }})"
                wire:loading.attr="disabled"
                wire:target="{{ $wireTarget }}"
                class="cursor-pointer rounded border border-blue-300 px-3 py-1 text-xs font-semibold text-blue-700 transition-colors hover:bg-blue-100 disabled:pointer-events-none disabled:opacity-50 dark:border-blue-800 dark:text-blue-400 dark:hover:bg-blue-900"
            >No, I'll keep trying</button>
        </div>
    </div>
@endif
