document.addEventListener('alpine:init', () => {
    /**
     * Recovers in-progress typed answers after a browser refresh — nothing
     * in the app auto-saves to the server until Continue is pressed, so a
     * refresh used to silently wipe whatever the learner had typed. Scoped
     * to plain text fields only (never the voice recordings) per the
     * explicit decision in EOS-009 §8.
     *
     * Usage: x-draft="{ key: 'a unique localStorage key', field: 'the Livewire property path, e.g. sentences.0' }"
     */
    Alpine.directive('draft', (el, { expression }, { evaluate, cleanup }) => {
        const { key, field } = evaluate(expression) || {};

        if (!key || !field) return;

        const wire = Alpine.evaluate(el, '$wire');

        try {
            const saved = localStorage.getItem(key);
            if (saved && !wire.get(field)) {
                wire.set(field, saved);
            }
        } catch (e) {}

        const handler = () => {
            try {
                const value = wire.get(field);
                if (!value) {
                    localStorage.removeItem(key);
                } else {
                    localStorage.setItem(key, value);
                }
            } catch (e) {}
        };

        el.addEventListener('input', handler);
        cleanup(() => el.removeEventListener('input', handler));
    });

    window.eosDraft = {
        clearPrefix(prefix) {
            try {
                Object.keys(localStorage)
                    .filter((k) => k.startsWith(prefix))
                    .forEach((k) => localStorage.removeItem(k));
            } catch (e) {}
        },
    };
});

// Dispatched server-side (`$this->dispatch('clear-draft', prefix: ...)`) only
// on a step's actual success path — never guessed client-side, since a
// failed save (validation errors) must never wipe the learner's local backup.
document.addEventListener('livewire:init', () => {
    Livewire.on('clear-draft', ({ prefix }) => window.eosDraft.clearPrefix(prefix));
});

/**
 * Reads an AI Conversation question aloud via the browser's built-in
 * SpeechSynthesis — no TTS API call, no audio file, no new backend surface.
 * Used by <x-speak-on-change> (see that component) so a scripted question
 * feels genuinely "asked", not just silently displayed as text. Silently
 * does nothing on a browser without SpeechSynthesis support, or if speech
 * synthesis throws (e.g. blocked before any page interaction) — the
 * question's text is always shown regardless, so this is a pure bonus,
 * never something a step depends on.
 */
window.eosVoice = {
    speak(text) {
        try {
            if (!('speechSynthesis' in window) || !text) return;
            window.speechSynthesis.cancel();
            const utterance = new SpeechSynthesisUtterance(text);
            utterance.lang = 'en-US';
            utterance.rate = 0.95;
            window.speechSynthesis.speak(utterance);
        } catch (e) {}
    },
};
