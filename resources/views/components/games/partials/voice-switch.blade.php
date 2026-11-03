{{--
    "Feedback on my sentences": records this learner's own speaking and
    checks it after the game. On unless they turn it off; kept for next
    time. Needs a component with setVoiceFeedback(bool) and $me.
--}}
<label class="flex cursor-pointer items-center gap-3 rounded-2xl bg-surface px-3.5 py-3 text-start shadow-sm dark:bg-surface-dark">
    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">@svg('heroicon-o-microphone', 'h-5 w-5')</span>
    <span class="min-w-0 flex-1">
        <span class="block text-sm font-bold text-ink dark:text-ink-dark">Feedback on my sentences</span>
        <span class="block text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">Your voice is recorded, checked after the game</span>
    </span>
    <input type="checkbox" @checked($me->game_voice_feedback) wire:change="setVoiceFeedback($event.target.checked)" class="h-5 w-5 shrink-0 accent-accent">
</label>
