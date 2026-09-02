{{--
    Reads $text aloud (window.eosVoice, SpeechSynthesis — see resources/js/app.js)
    only when the learner explicitly clicks it — the AI Instructor never
    speaks on its own anywhere in the app, only on request.

    @param string|null $text The question/prompt to speak.
--}}
@props(['text'])

<button
    type="button"
    x-data
    x-on:click="window.eosVoice?.speak($el.dataset.text)"
    data-text="{{ $text }}"
    class="inline-flex cursor-pointer items-center gap-1 rounded-full border border-line px-2.5 py-1 text-xs font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
>
    @svg('heroicon-o-speaker-wave', 'h-3.5 w-3.5')
    Read aloud
</button>
