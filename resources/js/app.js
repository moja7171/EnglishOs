document.addEventListener('alpine:init', () => {
    /**
     * $scrollToStepTop() — after a step swaps one of its sections in place
     * (the shared sub-step pager, Grammar's lesson → practice), the page keeps
     * its old scroll offset, so the learner lands at the BOTTOM of the new,
     * often shorter, section. This brings the top of the step back into view.
     *
     * Only scrolls when the top of the step has actually scrolled out of
     * view, so someone already at the top never sees the page jump; smooth
     * unless the OS asks for reduced motion. The 80px keeps the top clear
     * of the app's header.
     */
    Alpine.magic('scrollToStepTop', (el) => () => {
        const step = el.closest('[wire\\:id]');
        if (!step) return;

        const top = step.getBoundingClientRect().top;
        if (top >= 0) return;

        const calm = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        window.scrollTo({ top: Math.max(0, window.scrollY + top - 80), behavior: calm ? 'auto' : 'smooth' });
    });

    /**
     * x-swipe-close="open = false" — lets a bottom sheet be pulled down to
     * dismiss it. The sheet follows the finger, and past a short distance it
     * closes; let go early and it springs back. Only on phone-width screens
     * (where <x-menu-panel> is a sheet), and only when the sheet is scrolled
     * to its top so it never fights its own scrolling. Moves `transform`, not
     * `translate`, so it composes with the open/close transition's own.
     */
    Alpine.directive('swipe-close', (el, { expression }, { evaluate, cleanup }) => {
        const phone = window.matchMedia('(max-width: 639px)');
        let startY = null;
        let pull = 0;

        const start = (event) => {
            if (!phone.matches || el.scrollTop > 0) return;

            startY = event.touches[0].clientY;
            pull = 0;
            el.style.transition = 'none';
        };

        const move = (event) => {
            if (startY === null) return;

            pull = Math.max(0, event.touches[0].clientY - startY);
            el.style.transform = `translateY(${pull}px)`;
        };

        const end = () => {
            if (startY === null) return;

            startY = null;
            el.style.transition = '';

            if (pull > 90) {
                evaluate(expression);
                setTimeout(() => (el.style.transform = ''), 300);
            } else {
                el.style.transform = '';
            }
        };

        el.addEventListener('touchstart', start, { passive: true });
        el.addEventListener('touchmove', move, { passive: true });
        el.addEventListener('touchend', end);
        el.addEventListener('touchcancel', end);

        cleanup(() => {
            el.removeEventListener('touchstart', start);
            el.removeEventListener('touchmove', move);
            el.removeEventListener('touchend', end);
            el.removeEventListener('touchcancel', end);
        });
    });

    /**
     * x-back-close="{ open: isOpen, close: () => close() }" — makes the phone's
     * back button close an in-page overlay instead of leaving the page. The
     * players' "full screen" and the dictionary sheet are only restyled
     * elements, so the browser has no history entry for them and Back would go
     * to the previous mission step.
     * Each open overlay keeps one placeholder entry on top of the history.
     * Back pops the newest one and runs only that overlay's `close`, so a
     * dictionary opened over a full screen closes first, then the full screen
     * (an overlay still open afterwards, e.g. a video full screen opened from
     * the text view, re-arms for the next Back). Closing any other way (✕,
     * Esc) removes the overlay's entry again.
     *
     * The page's own entry holds a wire:navigate snapshot, and Livewire swaps
     * the whole page (and the player with it) for that copy whenever a
     * popstate lands on an entry carrying one. Its listener is registered
     * before ours and cannot be pre-empted, so instead the page's entry is
     * parked with a plain marker state while any overlay is open and its real
     * state is put back once the last placeholder is gone.
     */
    const backClose = (() => {
        const placeholder = 'eosBackClose';
        const parked = 'eosBackCloseParked';
        const open = [];
        let pageState = null;
        let ownPops = 0;

        const restorePageState = () => {
            if (open.length === 0 && history.state?.[parked]) history.replaceState(pageState, '');
        };

        const releaseEntry = () => {
            if (!history.state?.[placeholder]) return;

            ownPops++;
            history.back();
        };

        const sync = (overlay) => {
            const isOpen = overlay.isOpen();
            const index = open.indexOf(overlay);

            if (isOpen && index === -1) {
                if (!history.state?.[parked] && !history.state?.[placeholder]) {
                    pageState = history.state;
                    history.replaceState({ [parked]: true }, '');
                }

                open.push(overlay);
                history.pushState({ [placeholder]: true }, '');
            } else if (!isOpen && index !== -1) {
                open.splice(index, 1);
                releaseEntry();
            }
        };

        window.addEventListener('popstate', () => {
            if (ownPops > 0) {
                ownPops--;
            } else if (open.length > 0) {
                const overlay = open.pop();

                overlay.close();
                sync(overlay);
            }

            restorePageState();
        });

        return {
            sync,
            forget(overlay) {
                const index = open.indexOf(overlay);

                if (index === -1) return;

                open.splice(index, 1);
                releaseEntry();
            },
        };
    })();

    Alpine.directive('back-close', (el, { expression }, { evaluate, effect, cleanup }) => {
        const overlay = {
            isOpen: () => evaluate(expression).open,
            close: () => evaluate(expression).close(),
        };

        effect(() => backClose.sync(overlay));
        cleanup(() => backClose.forget(overlay));
    });

    /**
     * x-count-up="1234" — counts the number up from zero when it first
     * appears, so a streak or a total reads as something earned rather than
     * something printed. The server-rendered text is already the final
     * number, so a browser without JS, or a reduced-motion preference, just
     * keeps it. Runs once on init; later Livewire updates change the text
     * in place without replaying it.
     */
    Alpine.directive('count-up', (el, { expression }, { evaluate }) => {
        const target = Number(evaluate(expression));
        const calm = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        if (!Number.isFinite(target) || target <= 0 || calm) return;

        const duration = Math.min(900, 300 + target * 25);
        const start = performance.now();

        el.textContent = '0';

        const tick = (now) => {
            const t = Math.min(1, (now - start) / duration);
            el.textContent = String(Math.round(target * (1 - Math.pow(1 - t, 3))));

            if (t < 1) requestAnimationFrame(tick);
        };

        requestAnimationFrame(tick);
    });

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

    /**
     * One vocabulary review card (<x-vocabulary-review-card>): the front asks
     * "do you remember it?", the back shows the answer and takes the grade.
     * The flip, a swipe, a key press and the leave animation all happen right
     * here in the browser — the only server round-trip is the final
     * gradeWord(quality, remembered), which matters on a slow connection.
     *
     * Front: swipe right / Space / Enter / ArrowRight = "I remember",
     *        swipe left / ArrowLeft = "Not sure".
     * Back:  swipe right / ArrowRight = Good, swipe left / ArrowLeft = Again,
     *        1 / 2 / 3 = Again / Good / Easy. Someone who wasn't sure can only
     *        ever answer Again.
     */
    Alpine.data('recallCard', () => ({
        flipped: false,
        recalled: false,
        busy: false,
        leaving: 0,
        dx: 0,
        dragging: false,
        startX: null,
        startY: null,
        pointerId: null,
        reduceMotion: window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false,

        get hint() {
            if (Math.abs(this.dx) < 40) return null;

            return this.dx > 0 ? 'right' : 'left';
        },

        get hintLabel() {
            if (this.hint === null) return '';
            if (!this.flipped) return this.hint === 'right' ? 'I remember' : 'Not sure';
            if (!this.recalled) return 'Again';

            return this.hint === 'right' ? 'Good' : 'Again';
        },

        get cardStyle() {
            if (this.leaving) {
                return `transform: translateX(${this.leaving * 120}%) rotate(${this.leaving * 12}deg); opacity: 0; transition: transform .22s ease-in, opacity .22s ease-in;`;
            }
            if (this.dx !== 0) {
                return `transform: translateX(${this.dx}px) rotate(${this.dx / 18}deg); transition: none;`;
            }

            return 'transition: transform .2s ease-out;';
        },

        get tintStyle() {
            const strength = Math.min(Math.abs(this.dx) / 140, 1) * 0.16;

            return `background: var(${this.dx > 0 ? '--color-success' : '--color-danger'}); opacity: ${strength};`;
        },

        buzz() {
            try {
                navigator.vibrate?.(12);
            } catch (e) {}
        },

        flip(remembered) {
            if (this.flipped || this.busy) return;

            this.recalled = remembered;
            this.flipped = true;
            this.buzz();
        },

        grade(quality) {
            if (this.busy || !this.flipped) return;

            this.busy = true;
            this.buzz();

            const send = () => this.$wire.gradeWord(quality, this.recalled).catch(() => {
                this.leaving = 0;
                this.busy = false;
            });

            if (this.reduceMotion) {
                send();

                return;
            }

            this.leaving = quality === 1 ? -1 : 1;
            setTimeout(send, 200);
        },

        pointerDown(event) {
            if (this.busy || event.button > 0) return;
            if (event.target.closest('button, a, input, textarea, select')) return;

            this.startX = event.clientX;
            this.startY = event.clientY;
            this.pointerId = event.pointerId;
            this.dragging = false;
        },

        pointerMove(event) {
            if (this.startX === null || event.pointerId !== this.pointerId) return;

            const dx = event.clientX - this.startX;
            const dy = event.clientY - this.startY;

            if (!this.dragging) {
                // A mostly-vertical gesture is the page scrolling, not a swipe.
                if (Math.abs(dx) < 8 || Math.abs(dx) < Math.abs(dy)) return;

                this.dragging = true;
                try {
                    this.$el.setPointerCapture(event.pointerId);
                } catch (e) {}
            }

            this.dx = dx;
        },

        pointerUp() {
            if (this.startX === null) return;

            const dx = this.dx;
            const wasDragging = this.dragging;

            this.pointerCancel();

            if (wasDragging && Math.abs(dx) >= 90) {
                this.swipe(dx > 0);
            }
        },

        pointerCancel() {
            this.startX = null;
            this.startY = null;
            this.pointerId = null;
            this.dragging = false;
            this.dx = 0;
        },

        swipe(toRight) {
            if (!this.flipped) {
                this.flip(toRight);
            } else {
                this.grade(toRight && this.recalled ? 4 : 1);
            }
        },

        key(event) {
            if (event.metaKey || event.ctrlKey || event.altKey || this.busy) return;
            if (event.target.closest?.('input, textarea, select, [contenteditable]')) return;

            if (!this.flipped) {
                if (event.key === ' ' || event.key === 'Enter' || event.key === 'ArrowRight') {
                    event.preventDefault();
                    this.flip(true);
                } else if (event.key === 'ArrowLeft') {
                    event.preventDefault();
                    this.flip(false);
                }

                return;
            }

            if (!this.recalled) {
                if ([' ', 'Enter', '1', 'ArrowLeft', 'ArrowRight'].includes(event.key)) {
                    event.preventDefault();
                    this.grade(1);
                }

                return;
            }

            const quality = { 1: 1, 2: 4, 3: 5, ArrowLeft: 1, ArrowRight: 4 }[event.key];

            if (quality !== undefined) {
                event.preventDefault();
                this.grade(quality);
            }
        },
    }));

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
    window.eosConnectionNotice.attach();

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
 * When the shared host is overloaded (LiteSpeed answers 502/503/504) or
 * the learner's connection drops mid-request, Livewire's default is to
 * paint the server's raw error page over the whole app in a modal — on a
 * phone with a weak signal that was a full-screen "503 Service
 * Unavailable" with nothing to do but close it, and a dropped connection
 * showed nothing at all. Instead: one short line at the top that fades by
 * itself. A widget with its own inline message for the same failure (the
 * voice recorder) calls skip() so the learner isn't told twice. Every
 * other status (419 page expired, a 500's debug page locally) keeps
 * Livewire's own handling.
 */
window.eosConnectionNotice = {
    element: null,
    pendingTimer: null,
    hideTimer: null,

    attach() {
        if (typeof Livewire === 'undefined' || !Livewire.interceptRequest) return;

        Livewire.interceptRequest(({ onError, onFailure }) => {
            onError(({ response, preventDefault }) => {
                if (![502, 503, 504].includes(response.status)) return;
                preventDefault();
                this.queue();
            });
            onFailure(() => this.queue());
        });
    },

    // Deferred a tick: Livewire runs a component's own handlers for the
    // same failure right after this one, and they may still call skip().
    queue() {
        clearTimeout(this.pendingTimer);
        this.pendingTimer = setTimeout(() => this.show(), 0);
    },

    skip() {
        clearTimeout(this.pendingTimer);
    },

    show() {
        // isConnected: a wire:navigate page swap replaces <body>.
        if (!this.element || !this.element.isConnected) {
            const el = document.createElement('div');
            el.setAttribute('role', 'status');
            el.style.cssText = [
                'position:fixed', 'top:12px', 'left:50%', 'transform:translateX(-50%)',
                'max-width:calc(100% - 32px)', 'padding:8px 16px', 'border-radius:9999px',
                'background:var(--color-ink, #1d1b3a)', 'color:var(--color-ground, #f6f5fb)',
                'font-size:14px', 'z-index:10001', 'pointer-events:none',
                'opacity:0', 'transition:opacity 200ms ease-in',
            ].join(';');
            el.textContent = 'Connection problem. Please try again.';
            document.body.appendChild(el);
            this.element = el;
        }
        clearTimeout(this.hideTimer);
        this.element.style.opacity = '1';
        this.hideTimer = setTimeout(() => { this.element.style.opacity = '0'; }, 4000);
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
 * A pannable/zoomable square photo framer for the avatar upload flow — the
 * learner picks exactly what the server's center-crop (see
 * processAvatarUpload() in ⚡profile.blade.php) will otherwise guess blindly.
 * Pure Pointer Events + Canvas, no cropper library, matching every other
 * eosXxx widget here. One instance is attached per crop session (a fresh
 * photo pick); the panel's Alpine scope throws it away on cancel/save.
 */
window.eosAvatarCropper = {
    /**
     * viewport: the square, overflow-hidden preview element.
     * img: the <img> being panned/zoomed inside it (object-fit: none,
     * positioned via translate+scale so natural pixel math stays exact).
     * Returns a controller with setZoom()/crop() and a cleanup().
     */
    attach(viewport, img) {
        const state = { scale: 1, minScale: 1, x: 0, y: 0 };
        const pointers = new Map();
        let pinchStartDistance = 0;
        let pinchStartScale = 1;

        const clamp = () => {
            const size = viewport.clientWidth;
            const w = img.naturalWidth * state.scale;
            const h = img.naturalHeight * state.scale;
            const minX = Math.min(0, size - w);
            const minY = Math.min(0, size - h);
            state.x = Math.min(0, Math.max(minX, state.x));
            state.y = Math.min(0, Math.max(minY, state.y));
        };

        const render = () => {
            img.style.transform = `translate(${state.x}px, ${state.y}px) scale(${state.scale})`;
        };

        const recenter = () => {
            const size = viewport.clientWidth;
            state.minScale = size / Math.min(img.naturalWidth, img.naturalHeight);
            state.scale = state.minScale;
            state.x = (size - img.naturalWidth * state.scale) / 2;
            state.y = (size - img.naturalHeight * state.scale) / 2;
            render();
        };

        const setZoom = (scale, anchorX, anchorY) => {
            const size = viewport.clientWidth;
            const ax = anchorX ?? size / 2;
            const ay = anchorY ?? size / 2;
            const next = Math.min(state.minScale * 4, Math.max(state.minScale, scale));
            const ratio = next / state.scale;
            state.x = ax - (ax - state.x) * ratio;
            state.y = ay - (ay - state.y) * ratio;
            state.scale = next;
            clamp();
            render();
        };

        const onPointerDown = (e) => {
            // Capture is a pure enhancement (keeps dragging past the
            // viewport's edge) — the Map-based tracking below still works
            // without it, so a browser that balks at this pointerId never
            // breaks the drag itself.
            try {
                viewport.setPointerCapture(e.pointerId);
            } catch (err) {}
            pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
            if (pointers.size === 2) {
                const [a, b] = [...pointers.values()];
                pinchStartDistance = Math.hypot(a.x - b.x, a.y - b.y) || 1;
                pinchStartScale = state.scale;
            }
        };

        const onPointerMove = (e) => {
            if (!pointers.has(e.pointerId)) return;
            const previous = pointers.get(e.pointerId);
            const current = { x: e.clientX, y: e.clientY };
            pointers.set(e.pointerId, current);

            if (pointers.size === 2) {
                const [a, b] = [...pointers.values()];
                const distance = Math.hypot(a.x - b.x, a.y - b.y) || 1;
                const rect = viewport.getBoundingClientRect();
                setZoom(
                    pinchStartScale * (distance / pinchStartDistance),
                    (a.x + b.x) / 2 - rect.left,
                    (a.y + b.y) / 2 - rect.top,
                );
                return;
            }

            state.x += current.x - previous.x;
            state.y += current.y - previous.y;
            clamp();
            render();
        };

        const onPointerUp = (e) => {
            pointers.delete(e.pointerId);
        };

        const onWheel = (e) => {
            e.preventDefault();
            const rect = viewport.getBoundingClientRect();
            setZoom(state.scale * (e.deltaY < 0 ? 1.1 : 1 / 1.1), e.clientX - rect.left, e.clientY - rect.top);
        };

        viewport.addEventListener('pointerdown', onPointerDown);
        viewport.addEventListener('pointermove', onPointerMove);
        viewport.addEventListener('pointerup', onPointerUp);
        viewport.addEventListener('pointercancel', onPointerUp);
        viewport.addEventListener('wheel', onWheel, { passive: false });

        const ready = () => recenter();
        if (img.complete && img.naturalWidth) {
            ready();
        } else {
            img.addEventListener('load', ready, { once: true });
        }

        return {
            setZoomFraction(fraction) {
                setZoom(state.minScale + (state.minScale * 3) * fraction);
            },
            zoomFraction() {
                return (state.scale - state.minScale) / (state.minScale * 3);
            },
            crop(outputSize = 512) {
                return new Promise((resolve, reject) => {
                    try {
                        const size = viewport.clientWidth;
                        const canvas = document.createElement('canvas');
                        canvas.width = outputSize;
                        canvas.height = outputSize;
                        const ctx = canvas.getContext('2d');
                        const sx = -state.x / state.scale;
                        const sy = -state.y / state.scale;
                        const sSize = size / state.scale;
                        ctx.drawImage(img, sx, sy, sSize, sSize, 0, 0, outputSize, outputSize);
                        canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error('toBlob failed'))), 'image/jpeg', 0.9);
                    } catch (e) {
                        reject(e);
                    }
                });
            },
            cleanup() {
                viewport.removeEventListener('pointerdown', onPointerDown);
                viewport.removeEventListener('pointermove', onPointerMove);
                viewport.removeEventListener('pointerup', onPointerUp);
                viewport.removeEventListener('pointercancel', onPointerUp);
                viewport.removeEventListener('wheel', onWheel);
                img.removeEventListener('load', ready);
            },
        };
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

            window.eosHaptic?.done();

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

/**
 * Decides when an <audio>/<video> element has really been listened to, for
 * the "Listens: N" counter (see the listen-counter Livewire component).
 *
 * A listen counts once at least `threshold` (90%) of the recording's seconds
 * have genuinely played — measured by distinct whole seconds, so replaying a
 * line or rewinding 5 seconds never counts the same second twice, and
 * jumping the seek bar forward (to the very end, say) credits nothing: only
 * continuous playback marks seconds. Pausing and resuming is fine, and
 * speed doesn't matter because it counts seconds of the recording, not of
 * the clock.
 *
 * After a listen is counted the tracker stays idle until the recording
 * ends or the learner goes back near the start, so the last 10% of the
 * same play-through can't be credited towards the next listen.
 *
 * `onFinished` is the forgiving companion for gating a "Continue" button
 * (it never touches the counter): when the recording plays to its natural
 * end and at least `finishedThreshold` of its seconds were genuinely heard,
 * it fires even though the strict 90% rule fell short — pauses, replays and
 * auto-pauses can cost a few seconds, but a learner who clearly listened
 * through to the end must not stay locked out. Jumping straight to the end
 * credits nothing, so it still can't be skipped.
 */
window.eosListenTracker = function (media, onListened, threshold = 0.9, onFinished = null, finishedThreshold = 0.6) {
    const heard = new Set();
    let last = null;
    let waitingForRestart = false;

    const reset = () => {
        heard.clear();
        last = null;
        waitingForRestart = false;
    };

    media.addEventListener('seeking', () => {
        last = null;
    });
    media.addEventListener('ended', () => {
        const duration = media.duration;

        if (onFinished && ! waitingForRestart && Number.isFinite(duration) && duration > 0 && heard.size / Math.ceil(duration) >= finishedThreshold) {
            onFinished();
        }

        reset();
    });

    media.addEventListener('timeupdate', () => {
        const duration = media.duration;

        if (!Number.isFinite(duration) || duration <= 0 || media.paused || media.seeking) {
            return;
        }

        const now = media.currentTime;

        if (waitingForRestart) {
            if (now < duration * 0.1) {
                reset();
            }

            return;
        }

        // Only fully played seconds are marked. Continuous playback only: a gap this big between two updates is a
        // jump (a seek the 'seeking' event didn't flag first), not listening.
        if (last !== null && now >= last && now - last <= 2.5) {
            for (let second = Math.floor(last); second < Math.floor(now); second++) {
                heard.add(second);
            }
        }

        last = now;

        if (heard.size / Math.ceil(duration) >= threshold) {
            reset();
            waitingForRestart = true;
            onListened(duration);
        }
    });
};

/**
 * Picks an <audio>/<video> element back up where the learner left it — a
 * reload, a closed tab or the phone dropping the page used to send them
 * back to 0:00. The position lives in this browser only (localStorage
 * under `key`), saved every couple of seconds, on pause and when the page
 * is hidden; it is forgotten once the recording plays to its end.
 *
 * On the way back the playhead lands REWIND seconds before the saved spot
 * (a little context to get back into it), passed through `snapTo` first so
 * a caller can move it to the start of the line it falls in; nothing
 * plays by itself. `onRestored(time)` runs only when it actually moved. A
 * spot within the last few seconds counts as finished: back to the start.
 *
 * Returns a cleanup for the caller's destroy(): it saves one last time (a
 * wire:navigate away never fires pagehide) and drops the page listeners.
 */
window.eosResume = function (media, key, { snapTo = (time) => time, onRestored = () => {} } = {}) {
    const REWIND = 3;
    const SAVE_EVERY = 2;
    const NEAR_END = 5;

    let ready = false;
    let lastSaved = null;

    const forget = () => {
        try { localStorage.removeItem(key); } catch (e) {}
    };

    const save = () => {
        if (!ready || media.ended) return;

        const time = media.currentTime;

        try {
            if (time < 1) {
                localStorage.removeItem(key);
            } else {
                localStorage.setItem(key, time.toFixed(1));
            }
            lastSaved = time;
        } catch (e) {}
    };

    const restore = () => {
        if (ready) return;
        ready = true;

        let saved = null;
        try { saved = Number(localStorage.getItem(key)); } catch (e) {}

        const duration = media.duration;

        if (!saved || !Number.isFinite(duration) || duration <= 0) return;

        if (saved >= duration - NEAR_END) {
            forget();
            return;
        }

        const time = Math.max(snapTo(Math.max(saved - REWIND, 0)), 0);
        media.currentTime = time;
        lastSaved = time;
        onRestored(time);
    };

    if (media.readyState >= 1) {
        restore();
    } else {
        media.addEventListener('loadedmetadata', restore, { once: true });
    }

    media.addEventListener('timeupdate', () => {
        if (lastSaved === null || Math.abs(media.currentTime - lastSaved) >= SAVE_EVERY) save();
    });
    media.addEventListener('pause', save);
    media.addEventListener('seeked', save);
    media.addEventListener('ended', forget);

    const saveWhenHidden = () => { if (document.hidden) save(); };
    document.addEventListener('visibilitychange', saveWhenHidden);
    window.addEventListener('pagehide', save);

    return () => {
        save();
        document.removeEventListener('visibilitychange', saveWhenHidden);
        window.removeEventListener('pagehide', save);
    };
};

/**
 * The link that was just tapped says so. A wire:navigate lands only after the
 * next page has been fetched, so the tapped link/card is marked aria-busy the
 * moment the navigation starts (see a[aria-busy] in app.css): it holds its
 * pressed look, so the tap visibly "took" even when the page is slow. Nothing
 * else on the page changes. The thin bar at the top is Livewire's own (config/livewire.php). The mark is cleared just before the
 * swap (livewire:navigating) because Livewire snapshots the page being left
 * right after that event for the Back button: a still-busy link in the
 * snapshot would come back dead. A navigation that never lands is cleaned up
 * after 10 seconds.
 */
(() => {
    let tapped = null;
    let tappedAt = 0;
    let giveUp = null;

    const clear = () => {
        clearTimeout(giveUp);
        document.querySelectorAll('a[aria-busy]').forEach((link) => link.removeAttribute('aria-busy'));
    };

    document.addEventListener(
        'click',
        (event) => {
            if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

            tapped = event.target.closest?.('a[wire\\:navigate], a[wire\\:navigate\\.hover]') ?? null;
            tappedAt = Date.now();
        },
        true,
    );

    document.addEventListener('livewire:navigate', () => {
        clear();

        if (!tapped || Date.now() - tappedAt > 1000) return;

        tapped.setAttribute('aria-busy', 'true');
        giveUp = setTimeout(clear, 10000);
    });

    document.addEventListener('livewire:navigating', clear);
    document.addEventListener('livewire:navigated', clear);
    window.addEventListener('pageshow', clear);
})();

/**
 * Haptics: a short buzz when an answer is marked right or wrong, wherever the
 * app draws one (.choice carries data-state — see app.css), and a longer one
 * for a finished mission. Android only in practice (iOS Safari has no
 * vibrate()), so it is a bonus, never the only signal; reduced-motion users
 * get none. When one update marks both the wrong pick and the revealed
 * right one, only the "wrong" buzz plays.
 */
(() => {
    const buzz = (pattern) => {
        try {
            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

            navigator.vibrate?.(pattern);
        } catch (e) {}
    };

    window.eosHaptic = {
        correct: () => buzz(12),
        wrong: () => buzz([10, 40, 10]),
        done: () => buzz([18, 50, 28]),
    };

    new MutationObserver((mutations) => {
        let right = false;
        let wrong = false;

        mutations.forEach((mutation) => {
            const el = mutation.target;

            if (!el.matches?.('.choice')) return;

            const state = el.getAttribute('data-state');

            if (state === mutation.oldValue) return;

            right ||= state === 'correct';
            wrong ||= state === 'wrong';
        });

        if (wrong) window.eosHaptic.wrong();
        else if (right) window.eosHaptic.correct();
    }).observe(document.documentElement, {
        subtree: true,
        attributes: true,
        attributeFilter: ['data-state'],
        attributeOldValue: true,
    });
})();

/**
 * The tap-a-word dictionary. x-lookup (below) turns a tap on a word inside
 * an element into an `eos-lookup` event; <x-dictionary-sheet> in the layout
 * listens for it and shows the meaning. Both are plain DOM — nothing here
 * touches Livewire, so looking a word up never re-renders the step being read.
 */
window.eosLookup = {
    /**
     * The word under a tap, with the sentence around it and the range it
     * covers — or null when the tap landed on whitespace, a number, the
     * margin of a line or anything outside `root`. Looks inside one text
     * node, which is where a transcript line or a passage keeps its words.
     */
    wordAt(x, y, root) {
        let node;
        let offset;

        if (document.caretPositionFromPoint) {
            const position = document.caretPositionFromPoint(x, y);
            node = position?.offsetNode;
            offset = position?.offset;
        } else if (document.caretRangeFromPoint) {
            const range = document.caretRangeFromPoint(x, y);
            node = range?.startContainer;
            offset = range?.startOffset;
        }

        if (!node || node.nodeType !== Node.TEXT_NODE || !root.contains(node)) return null;

        const text = node.textContent;
        const isWordChar = (char) => /[A-Za-z'’-]/.test(char);
        let start = offset;
        let end = offset;

        while (start > 0 && isWordChar(text[start - 1])) start--;
        while (end < text.length && isWordChar(text[end])) end++;

        const word = text.slice(start, end).replace(/^['’-]+|['’-]+$/g, '');

        if (!/[A-Za-z]/.test(word)) return null;

        const range = document.createRange();
        range.setStart(node, start);
        range.setEnd(node, end);

        // The caret lookup snaps to the nearest character, so a tap in the
        // empty space after a line would still "find" its last word.
        const landedOnWord = Array.from(range.getClientRects()).some((rect) => (
            x >= rect.left - 2 && x <= rect.right + 2 && y >= rect.top - 2 && y <= rect.bottom + 2
        ));

        if (!landedOnWord) return null;

        return { word, sentence: this.sentenceAround(node, start), range };
    },

    /** The sentence of the nearest block that holds the word at `start` in `node`. */
    sentenceAround(node, start) {
        const block = node.parentElement.closest('p, li, blockquote, h1, h2, h3, h4') || node.parentElement;
        const walker = document.createTreeWalker(block, NodeFilter.SHOW_TEXT);
        let before = 0;
        let current;

        while ((current = walker.nextNode()) && current !== node) before += current.textContent.length;

        const full = block.textContent;
        const index = before + start;
        const head = full.slice(0, index).match(/[\s\S]*[.!?…]["”’')\]]*\s+/);
        const from = head ? head[0].length : 0;
        const tail = /[.!?…]["”’')\]]*(?=\s|$)/.exec(full.slice(index));
        const to = tail ? index + tail.index + tail[0].length : full.length;

        return full.slice(from, to).replace(/\s+/g, ' ').trim().slice(0, 300);
    },

    open(detail) {
        window.dispatchEvent(new CustomEvent('eos-lookup', { detail }));
    },
};

document.addEventListener('alpine:init', () => {
    /**
     * x-lookup — a tap on a word inside this element opens the dictionary.
     * Listens in the capture phase and stops the tap there, so a transcript
     * line that seeks the audio on tap does NOT also seek when its word is
     * tapped; a tap beside a word (or on a button or link) is left alone and
     * does whatever it did before. Dragging to select text is not a tap.
     */
    Alpine.directive('lookup', (el, _directive, { cleanup }) => {
        const onTap = (event) => {
            if (event.button > 0 || event.target.closest('button, a, input, textarea, select, [data-no-lookup]')) return;

            const selection = window.getSelection();
            if (selection && !selection.isCollapsed) return;

            const hit = window.eosLookup.wordAt(event.clientX, event.clientY, el);
            if (!hit) return;

            event.stopPropagation();
            window.eosLookup.open(hit);
        };

        el.addEventListener('click', onTap, true);
        cleanup(() => el.removeEventListener('click', onTap, true));
    });

    /**
     * The state of <x-dictionary-sheet>: fetches the word, lets the learner
     * pick which meaning to keep, and saves it to My Words. A tapped word
     * pauses whatever audio or video is playing and resumes it on close.
     * Late answers for a word the learner has already moved on from are
     * dropped (`ticket`).
     */
    Alpine.data('dictionarySheet', (urls) => ({
        isOpen: false,
        loading: false,
        failed: false,
        word: '',
        sentence: '',
        entry: null,
        saved: false,
        saving: false,
        addFailed: false,
        chosen: 0,
        showOthers: false,
        improving: false,
        pausedMedia: [],
        ticket: 0,

        /**
         * Every meaning of the entry as one flat list, in the order shown.
         * A dictionary sense often runs on after a semicolon with a second
         * and third way of saying the same thing — the sheet keeps the first.
         */
        get senses() {
            return (this.entry?.meanings ?? []).flatMap((group) => (
                group.definitions.map((sense) => ({
                    pos: group.pos,
                    example: sense.example,
                    definition: this.firstClause(sense.definition),
                }))
            ));
        },

        /** The meaning on show: the first one, or the one picked from the other meanings. */
        get current() {
            return this.senses[this.chosen] ?? null;
        },

        /** The dictionary's example for that meaning, else the sentence the word was tapped in. */
        get example() {
            return this.current?.example || this.sentence;
        },

        /** The Persian lines to show: one per part of speech once Gemini has answered, else the quick translation. */
        get persianLines() {
            if (this.entry?.persian_senses?.length) {
                return this.entry.persian_senses.map((line) => ({
                    label: this.entry.persian_senses.length > 1 ? (this.persianPos[line.pos] ?? '') : '',
                    words: line.words,
                }));
            }

            return this.entry?.translation_fa ? [{ label: '', words: this.entry.translation_fa }] : [];
        },

        persianPos: {
            noun: 'اسم',
            verb: 'فعل',
            adjective: 'صفت',
            adverb: 'قید',
            pronoun: 'ضمیر',
            preposition: 'حرف اضافه',
            conjunction: 'حرف ربط',
            interjection: 'شبه‌جمله',
            determiner: 'حرف تعریف',
        },

        firstClause(text) {
            const head = text.split(/;\s/)[0].trim();

            return head.length >= 12 ? head : text;
        },

        async show({ word, sentence, range }) {
            const ticket = ++this.ticket;

            this.word = word;
            this.sentence = sentence ?? '';
            this.entry = null;
            this.saved = false;
            this.failed = false;
            this.addFailed = false;
            this.chosen = 0;
            this.showOthers = false;
            this.loading = true;
            this.isOpen = true;

            this.pauseMedia();
            if (range && window.CSS?.highlights && window.Highlight) {
                CSS.highlights.set('eos-lookup', new Highlight(range));
            }

            try {
                const response = await fetch(`${urls.lookup}?word=${encodeURIComponent(word)}`, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });

                if (!response.ok) throw new Error(`lookup ${response.status}`);

                const json = await response.json();
                if (ticket !== this.ticket) return;

                this.entry = json.data;
                this.saved = json.saved;
                this.word = json.data.word;

                if (json.data.english_status !== 'unavailable' && ! json.data.persian_senses) {
                    this.improvePersian(ticket);
                }
            } catch (error) {
                if (ticket === this.ticket) this.failed = true;
            } finally {
                if (ticket === this.ticket) this.loading = false;
            }
        },

        /**
         * The second step of a tap: the quick answer is already on screen, so
         * ask for the better Persian meanings (one line per part of speech)
         * and swap them in. If they never come, the quick translation stays.
         */
        async improvePersian(ticket) {
            this.improving = true;

            try {
                const response = await fetch(`${urls.persian}?word=${encodeURIComponent(this.word)}`, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });

                if (! response.ok || ticket !== this.ticket) return;

                this.entry = (await response.json()).data;
            } catch (error) {
                // Keep the quick translation.
            } finally {
                if (ticket === this.ticket) this.improving = false;
            }
        },

        close() {
            this.isOpen = false;
            this.ticket++;
            window.CSS?.highlights?.delete('eos-lookup');
            this.pausedMedia.forEach((media) => media.play().catch(() => {}));
            this.pausedMedia = [];
        },

        pauseMedia() {
            if (this.pausedMedia.length) return;

            this.pausedMedia = Array.from(document.querySelectorAll('audio, video')).filter((media) => !media.paused);
            this.pausedMedia.forEach((media) => media.pause());
        },

        speak() {
            if (this.entry?.audio_url) {
                new Audio(this.entry.audio_url).play().catch(() => window.eosVoice?.speak(this.word));
            } else {
                window.eosVoice?.speak(this.word);
            }
        },

        async add() {
            if (this.saving || this.saved) return;

            const sense = this.senses[this.chosen];

            this.saving = true;
            this.addFailed = false;

            try {
                const response = await fetch(urls.store, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        word: this.word,
                        meaning: sense?.definition ?? null,
                        pos: sense?.pos ?? null,
                        example: this.sentence || sense?.example || null,
                    }),
                });

                if (!response.ok) throw new Error(`store ${response.status}`);

                this.saved = true;
            } catch (error) {
                this.addFailed = true;
            } finally {
                this.saving = false;
            }
        },
    }));
});
