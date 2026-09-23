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

        /**
         * Restores a multi-substep step's current page/section index after
         * a refresh — x-draft above only ever persisted typed TEXT (a
         * Livewire property), never a plain Alpine-local number like
         * activeSubstep/activeSection, so a refresh used to always drop the
         * learner back to page 1 even though their answers on later pages
         * were themselves still safe. Pair with persistIndex() below via
         * $watch; both use the same eos-draft:{run}:{step}: prefix as
         * x-draft, so the server's one 'clear-draft' event on real success
         * clears this too, no extra wiring needed.
         */
        restoreIndex(key, fallback) {
            try {
                const saved = localStorage.getItem(key);

                return saved === null ? fallback : Number(saved);
            } catch (e) {
                return fallback;
            }
        },

        persistIndex(key, value) {
            try {
                localStorage.setItem(key, value);
            } catch (e) {}
        },
    };
});

// Dispatched server-side (`$this->dispatch('clear-draft', prefix: ...)`) only
// on a step's actual success path — never guessed client-side, since a
// failed save (validation errors) must never wipe the learner's local backup.
document.addEventListener('livewire:init', () => {
    Livewire.on('clear-draft', ({ prefix }) => window.eosDraft.clearPrefix(prefix));

    window.eosProgress.attach();

    // Livewire's morph copies every attribute of the freshly-rendered
    // server HTML onto the live element — x-cloak included, because
    // Alpine only strips x-cloak a microtask later, after the morph has
    // already run synchronously. Livewire's own injected
    // `[x-cloak]{display:none!important}` then hid a currently-visible
    // x-show block for a few ms on every round-trip: the page collapsed
    // to viewport height, the browser clamped scrollY to 0, and the
    // learner was thrown to the top (Vocabulary Builder's story, on every
    // word pick, on any viewport where the rest of the page is shorter
    // than the window). Once Alpine is running, x-show owns display via
    // inline style and x-cloak has no job left on an existing element, so
    // drop it from the incoming node before its attributes are patched.
    // Newly ADDED nodes are left alone: there x-cloak still does its real
    // job of hiding until Alpine evaluates their x-show.
    Livewire.hook('morph.updating', ({ toEl }) => {
        if (toEl.hasAttribute?.('x-cloak')) toEl.removeAttribute('x-cloak');
    });
});

/**
 * A thin progress bar pinned to the top of the viewport, shown for the
 * duration of ANY Livewire request (every wire:click / wire:submit /
 * wire:model round-trip) — so a click that has to wait on the server is
 * never silent, even on buttons that don't carry their own
 * wire:loading state. On the shared host a plain Livewire round-trip is
 * often a full second, which without this read as "nothing happened".
 * Buttons that are slow or must not be double-clicked (sign in, AI
 * generation, saves) ALSO disable themselves and swap their label via
 * wire:loading — this bar is the app-wide baseline underneath that, not
 * a replacement for it. Only appears once a request has taken longer
 * than a short delay, so fast responses never flash a bar.
 */
window.eosProgress = {
    pending: 0,
    element: null,
    showTimer: null,
    hideTimer: null,

    attach() {
        if (typeof Livewire === 'undefined' || !Livewire.hook) return;

        Livewire.hook('request', ({ respond, fail }) => {
            this.start();
            let finished = false;
            const done = () => {
                if (finished) return;
                finished = true;
                this.finish();
            };
            respond(done);
            fail(done);
        });
    },

    bar() {
        if (this.element) return this.element;
        const el = document.createElement('div');
        el.setAttribute('role', 'progressbar');
        el.setAttribute('aria-hidden', 'true');
        el.style.cssText = [
            'position:fixed', 'top:0', 'left:0', 'height:3px', 'width:0',
            'background:var(--color-accent, #f97316)', 'z-index:10000',
            'opacity:0', 'pointer-events:none',
            'transition:width 400ms ease-out, opacity 200ms ease-in',
        ].join(';');
        document.body.appendChild(el);
        this.element = el;
        return el;
    },

    start() {
        this.pending += 1;
        if (this.pending > 1) return;
        clearTimeout(this.hideTimer);
        this.showTimer = setTimeout(() => {
            const el = this.bar();
            el.style.transition = 'none';
            el.style.width = '0';
            el.style.opacity = '1';
            // Two frames so the width reset above lands before the
            // animated grow — otherwise the browser coalesces both.
            requestAnimationFrame(() => requestAnimationFrame(() => {
                el.style.transition = 'width 6s cubic-bezier(0.1, 0.6, 0.2, 1), opacity 200ms ease-in';
                el.style.width = '85%';
            }));
        }, 150);
    },

    finish() {
        this.pending = Math.max(0, this.pending - 1);
        if (this.pending > 0) return;
        clearTimeout(this.showTimer);
        if (!this.element || this.element.style.opacity !== '1') return;
        const el = this.element;
        el.style.transition = 'width 200ms ease-out, opacity 300ms ease-in 150ms';
        el.style.width = '100%';
        el.style.opacity = '0';
        this.hideTimer = setTimeout(() => { el.style.width = '0'; }, 500);
    },
};

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
