{{-- Teddy among the friends in "Play with": one tap starts the game with him. --}}
<button type="button" wire:click="playWithAi" wire:loading.attr="disabled" wire:target="playWithAi" class="flex w-24 shrink-0 cursor-pointer flex-col items-center gap-1.5 rounded-2xl border-2 border-line px-2 py-2.5 disabled:opacity-50 dark:border-line-dark">
    <x-teddy-avatar class="h-11 w-11" />
    <span class="w-full truncate text-center text-[13px] font-bold text-ink dark:text-ink-dark">Teddy</span>
    <span class="text-[11px] font-semibold text-accent-ink dark:text-accent-ink-dark">Play now</span>
</button>
