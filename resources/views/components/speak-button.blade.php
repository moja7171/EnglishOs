{{--
    Reads $text aloud (window.eosVoice, SpeechSynthesis — see resources/js/app.js)
    only when the learner explicitly clicks it.

    @param string|null $text The question/prompt to speak.
    @param string|null $voice "teddy" to use Teddy's own voice (Practice with AI).
--}}
@props(['text', 'voice' => null])

<button
    type="button"
    x-data
    x-on:click="$el.dataset.voice === 'teddy' ? window.eosVoice?.teddy($el.dataset.text) : window.eosVoice?.speak($el.dataset.text)"
    data-text="{{ $text }}"
    data-voice="{{ $voice }}"
    title="Read aloud"
    class="inline-flex cursor-pointer items-center justify-center rounded-full border border-line p-1.5 text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
>
    <span class="sr-only">Read aloud</span>
    @svg('heroicon-o-speaker-wave', 'h-3.5 w-3.5')
</button>
