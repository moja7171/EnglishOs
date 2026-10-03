{{--
    Wraps a vocabulary word/phrase so double-tapping it (mobile) or
    double-clicking it (desktop) speaks it aloud — reuses window.eosVoice
    (see resources/js/app.js and <x-speak-button>), the same SpeechSynthesis
    wrapper already used for reading questions aloud: en-US, fails
    completely silently on a browser without support. A different trigger
    gesture (double-tap on the word itself, not a separate button) suits a
    short word/phrase a learner is already looking at, rather than a whole
    question.

    Used on every standalone word/phrase chip, card, or list row across the
    app (Vocabulary Builder, My Words, Daily Review, Listening/Reading new-
    words lists, Writing's word bank, etc.) — but deliberately NOT wired
    into continuous prose (reading passages, AI-conversation messages,
    full sentences), where wrapping every word would need splitting running
    text apart and risks colliding with text selection/zoom gestures.

    @param string $word The word or phrase to speak when tapped.
--}}
@props(['word'])

<span
    {{ $attributes->class(['cursor-pointer']) }}
    style="touch-action: manipulation;"
    title="Double-tap to hear it"
    x-data="{
        lastTap: 0,
        maybeSpeak() {
            const now = Date.now();
            if (now - this.lastTap < 350) { window.eosVoice?.speak({{ json_encode($word) }}); }
            this.lastTap = now;
        },
    }"
    x-on:touchend="maybeSpeak()"
    x-on:dblclick="window.eosVoice?.speak({{ json_encode($word) }})"
>{{ $word }}</span>
