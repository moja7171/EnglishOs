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
 * Reads a question/prompt aloud via the browser's built-in
 * SpeechSynthesis — no TTS API call, no audio file, no new backend surface.
 * Used by <x-speak-button> (see that component), which only ever calls
 * this from an explicit learner click — the AI Instructor never speaks on
 * its own anywhere in the app. Silently does nothing on a browser without
 * SpeechSynthesis support, or if speech synthesis throws — the question's
 * text is always shown regardless, so this is a pure bonus, never
 * something a step depends on.
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

/**
 * A tiny two-note "success" chime, synthesized with the Web Audio API —
 * no audio file, no licensing question, nothing to download. Used by
 * <x-quick-round> on a correct pick, the single most-reused low-pressure
 * check across the app (see EOS-009 §8), instead of wiring a sound into
 * every individual AI-checked field (which would fire on every retry and
 * get noisy fast). Respects a per-viewer localStorage mute preference —
 * see the sound-toggle button in layouts/app.blade.php. Silently does
 * nothing without Web Audio support, or if it throws for any reason.
 */
window.eosSound = {
    enabled() {
        try {
            return localStorage.getItem('eosSoundEnabled') !== 'false';
        } catch (e) {
            return true;
        }
    },
    playSuccess() {
        try {
            if (!this.enabled()) return;
            const Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return;
            const ctx = new Ctx();
            const now = ctx.currentTime;

            [523.25, 783.99].forEach((frequency, i) => {
                const oscillator = ctx.createOscillator();
                const gain = ctx.createGain();
                oscillator.type = 'sine';
                oscillator.frequency.value = frequency;
                const start = now + i * 0.09;
                gain.gain.setValueAtTime(0, start);
                gain.gain.linearRampToValueAtTime(0.15, start + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.001, start + 0.25);
                oscillator.connect(gain).connect(ctx.destination);
                oscillator.start(start);
                oscillator.stop(start + 0.25);
            });

            setTimeout(() => ctx.close(), 500);
        } catch (e) {}
    },
};

/**
 * A short, pure-Canvas confetti burst — no external library, nothing to
 * load. Fired exactly once by <x-mission-result> the moment a mission
 * first completes (see that component's own docblock for why the
 * condition it's gated behind can only ever be true once). Respects
 * prefers-reduced-motion by doing nothing at all.
 */
window.eosConfetti = {
    burst() {
        try {
            if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) return;

            const canvas = document.createElement('canvas');
            canvas.style.cssText = 'position:fixed;inset:0;width:100vw;height:100vh;pointer-events:none;z-index:9999;';
            canvas.width = window.innerWidth;
            canvas.height = window.innerHeight;
            document.body.appendChild(canvas);
            const ctx = canvas.getContext('2d');

            const colors = ['#f59e0b', '#10b981', '#3b82f6', '#ef4444', '#8b5cf6'];
            const pieces = Array.from({ length: 120 }, () => ({
                x: Math.random() * canvas.width,
                y: -20 - Math.random() * canvas.height * 0.5,
                size: 4 + Math.random() * 4,
                color: colors[Math.floor(Math.random() * colors.length)],
                speedY: 2 + Math.random() * 3,
                speedX: -1 + Math.random() * 2,
                rotation: Math.random() * 360,
                spin: -6 + Math.random() * 12,
            }));

            const start = performance.now();
            const duration = 2600;

            const frame = (now) => {
                const elapsed = now - start;
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                pieces.forEach((p) => {
                    p.y += p.speedY;
                    p.x += p.speedX;
                    p.rotation += p.spin;
                    ctx.save();
                    ctx.translate(p.x, p.y);
                    ctx.rotate((p.rotation * Math.PI) / 180);
                    ctx.fillStyle = p.color;
                    ctx.fillRect(-p.size / 2, -p.size / 2, p.size, p.size * 0.6);
                    ctx.restore();
                });
                if (elapsed < duration) {
                    requestAnimationFrame(frame);
                } else {
                    canvas.remove();
                }
            };

            requestAnimationFrame(frame);
        } catch (e) {}
    },
};
