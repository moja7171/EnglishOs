{{--
    "Feedback on my sentences": records this learner's own speaking and
    checks it after the game; the choice is kept for next time. Needs a
    component with setVoiceFeedback(bool), and $voiceOn.
--}}
<label class="flex cursor-pointer items-center gap-3 rounded-2xl bg-surface px-3.5 py-3 text-start shadow-sm dark:bg-surface-dark">
    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">@svg('heroicon-o-microphone', 'h-5 w-5')</span>
    <span class="min-w-0 flex-1">
        <span class="block text-sm font-bold text-ink dark:text-ink-dark">Feedback on my sentences</span>
        <span class="block text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">{{ $voiceOn ? 'Your voice is recorded, checked after the game' : 'Off · your voice isn’t recorded' }}</span>
    </span>
    <input type="checkbox" @checked($voiceOn) wire:change="setVoiceFeedback($event.target.checked)" class="h-5 w-5 shrink-0 accent-accent">
</label>
