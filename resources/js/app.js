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
     * to its top so it never fights its own scrolling, and never from inside
     * [data-no-swipe-close] (a photo being dragged). Moves `transform`, not
     * `translate`, so it composes with the open/close transition's own.
     */
    Alpine.directive('swipe-close', (el, { expression }, { evaluate, cleanup }) => {
        const phone = window.matchMedia('(max-width: 639px)');
        let startY = null;
        let pull = 0;

        const start = (event) => {
            if (!phone.matches || el.scrollTop > 0 || event.target.closest('[data-no-swipe-close]')) return;

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

        const showStepBack = () => {
            Alpine.store('stepBack').open = open.some((overlay) => overlay.step);
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

            showStepBack();
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

        /**
         * Leaving for another page with an overlay still open (a tab tapped on
         * a Profile screen or mid-step, a person picked in a sheet). The usual
         * history.back() would land after Livewire has pushed the new page and
         * undo that push, leaving the new page under the old address. So the
         * placeholder simply becomes the page's entry again, and Livewire's
         * Back copy is stored on it as on any other page.
         */
        document.addEventListener('livewire:navigating', () => {
            if (!history.state?.[placeholder]) return;

            open.length = 0;
            history.replaceState(pageState, '');
            showStepBack();
        });

        return {
            sync,
            forget(overlay) {
                const index = open.indexOf(overlay);

                if (index === -1) return;

                open.splice(index, 1);
                releaseEntry();
                showStepBack();
            },
        };
    })();

    /**
     * x-back-close.step marks the overlay as a lesson step's own "previous
     * screen". While one is open, $store.stepBack.open is true and the
     * step header's ‹ shows; tapping it is the phone's Back, so the sheet
     * or dictionary on top closes first and the step goes back one screen
     * after that.
     */
    Alpine.store('stepBack', { open: false });

    Alpine.directive('back-close', (el, { expression, modifiers }, { evaluate, effect, cleanup }) => {
        const overlay = {
            step: modifiers.includes('step'),
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
 * this from an explicit learner click, and by Practice with AI, where the
 * classmate says its turn aloud. Silently does nothing on a browser without
 * SpeechSynthesis support, or if speech synthesis throws — the question's
 * text is always shown regardless, so this is a pure bonus, never
 * something a step depends on.
 */
window.eosVoice = {
    // options.rate: slower for the dictation card's 🐢 (default 0.95).
    // options.onEnd: called once the whole text has been read (or stopped).
    // options.onSentence: called with the piece's number as each one starts
    // (the reading passage lights the sentence being read).
    // A long text (a reading passage, a story) is queued sentence by
    // sentence — some browsers cut one long utterance off after ~15 s.
    speak(text, options = {}) {
        try {
            if (!('speechSynthesis' in window) || !text) {
                options.onEnd?.();
                return;
            }
            window.speechSynthesis.cancel();
            const pieces = text.length > 180 || options.onSentence ? text.match(/[^.!?]+[.!?]+["”’)]*\s*|[^.!?]+$/g) ?? [text] : [text];
            pieces.forEach((piece, i) => {
                const utterance = new SpeechSynthesisUtterance(piece.trim());
                utterance.lang = 'en-US';
                utterance.rate = options.rate ?? 0.95;
                if (options.voice) {
                    utterance.voice = options.voice;
                    utterance.lang = options.voice.lang;
                }
                if (options.pitch) {
                    utterance.pitch = options.pitch;
                }
                if (options.onSentence) {
                    utterance.onstart = () => options.onSentence(i);
                }
                if (i === pieces.length - 1 && options.onEnd) {
                    utterance.onend = () => options.onEnd();
                    utterance.onerror = () => options.onEnd();
                }
                window.speechSynthesis.speak(utterance);
            });
        } catch (e) {
            options.onEnd?.();
        }
    },
    stop() {
        try {
            window.speechSynthesis?.cancel();
        } catch (e) {}
    },
    /**
     * The most natural-sounding English voice this phone has, a male one
     * when there is one: "natural"/"neural"/"online" voices first, then a
     * known male name, then US English. Null when the browser lists none
     * (the default voice is then used, with a lower pitch).
     */
    teddyVoice() {
        try {
            const voices = (window.speechSynthesis?.getVoices() ?? []).filter((voice) => /^en[-_]/i.test(voice.lang));
            if (voices.length === 0) {
                return null;
            }
            const score = (voice) => {
                const label = `${voice.name} ${voice.voiceURI}`;
                return (/natural|neural|online|enhanced|premium/i.test(label) ? 4 : 0)
                    + (/(?<!fe)male|daniel|alex\b|aaron|fred|guy|david|mark|james|arthur|oliver|thomas|evan|nathan|tom\b|rishi|gordon|iom|iol|tpd/i.test(label) ? 3 : 0)
                    + (/en[-_]US/i.test(voice.lang) ? 1 : 0);
            };
            return voices.reduce((best, voice) => (score(voice) > score(best) ? voice : best), voices[0]);
        } catch (e) {
            return null;
        }
    },
    /**
     * Teddy's own voice for Practice with AI: the voice above, a little
     * slower and lower, after a short pause like a person who has just
     * thought of the answer. options.delay: the pause in ms (default 0);
     * options.onEnd: once he has finished.
     */
    teddy(text, options = {}) {
        const say = () => {
            const voice = this.teddyVoice();
            this.speak(text, { rate: 0.92, pitch: voice ? 0.95 : 0.85, voice, onEnd: options.onEnd });
        };
        if (options.delay) {
            setTimeout(say, options.delay);
        } else {
            say();
        }
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
    /**
     * A short run of soft notes: [[frequency, startsAfterSeconds, lastsSeconds], ...].
     * Quiet and rounded, never harsh: a wrong answer is not a punishment.
     */
    play(notes, wave = 'sine', volume = 0.13) {
        try {
            if (!this.enabled()) return;
            const Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return;
            const ctx = new Ctx();
            const now = ctx.currentTime;
            let end = 0;

            notes.forEach(([frequency, after, lasts]) => {
                const oscillator = ctx.createOscillator();
                const gain = ctx.createGain();
                oscillator.type = wave;
                oscillator.frequency.value = frequency;
                const start = now + after;
                gain.gain.setValueAtTime(0, start);
                gain.gain.linearRampToValueAtTime(volume, start + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.001, start + lasts);
                oscillator.connect(gain).connect(ctx.destination);
                oscillator.start(start);
                oscillator.stop(start + lasts);
                end = Math.max(end, after + lasts);
            });

            setTimeout(() => ctx.close(), end * 1000 + 200);
        } catch (e) {}
    },
    playWrong() {
        this.play([[330, 0, 0.18], [247, 0.12, 0.28]], 'triangle');
    },
    playPoint() {
        this.play([[659.25, 0, 0.14]]);
    },
    playWin() {
        this.play([[523.25, 0, 0.18], [659.25, 0.12, 0.18], [783.99, 0.24, 0.18], [1046.5, 0.36, 0.4]]);
    },
    playLose() {
        this.play([[392, 0, 0.22], [349.23, 0.2, 0.22], [293.66, 0.4, 0.4]], 'triangle');
    },
    playDone() {
        this.play([[523.25, 0, 0.16], [783.99, 0.14, 0.4]]);
    },
};

/**
 * One call per moment of a game: its sound and its buzz together. Used by
 * <x-feedback-cue>, which fires it when a result shows on screen.
 */
window.eosFeedback = {
    right() {
        window.eosSound?.playSuccess();
        window.eosHaptic?.correct();
    },
    wrong() {
        window.eosSound?.playWrong();
        window.eosHaptic?.wrong();
    },
    point() {
        window.eosSound?.playPoint();
        window.eosHaptic?.correct();
    },
    win() {
        window.eosSound?.playWin();
        window.eosHaptic?.win();
    },
    lose() {
        window.eosSound?.playLose();
        window.eosHaptic?.lose();
    },
    done() {
        window.eosSound?.playDone();
        window.eosHaptic?.done();
    },
};

/**
 * The voice recorder of games and Pair Practice — no buttons. The page
 * tells it, on every render, whether this phone's owner is speaking right
 * now (sync(): a "part" name like "turn-2", or '' when not). It records
 * that stretch only and, when it ends, sends it quietly in the
 * background, one piece at a time, trying again on a weak connection
 * (the page's Livewire component keeps it: upload into `voiceClip`, then
 * saveVoiceClip(part, label)). If the mic can't be opened — refused, or
 * busy with a phone or WhatsApp call — recording just stays off for this
 * game. Lives on window so a re-render (or the game ending) never loses a
 * piece still on its way.
 */
window.eosGameVoice = {
    part: null,
    label: null,
    recorder: null,
    stream: null,
    queue: [],
    busy: false,
    off: false,
    wire: null,
    tries: 0,

    /** Asks for the mic early (on the waiting screen), so no prompt pops up mid-turn. */
    async warmUp() {
        if (this.off || !navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) return;
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            stream.getTracks().forEach((track) => track.stop());
        } catch (e) {
            this.off = true;
        }
    },

    sync(wire, enabled, part, label) {
        this.wire = wire;
        const wanted = enabled && !this.off ? part || '' : '';
        if (wanted === (this.part || '')) {
            this.pump();
            return;
        }
        this.stop();
        if (wanted) this.start(wanted, label);
    },

    async start(part, label) {
        this.part = part;
        this.label = label;
        if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) {
            this.off = true;
            this.part = null;
            return;
        }
        let stream;
        try {
            stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        } catch (e) {
            this.off = true;
            this.part = null;
            return;
        }
        if (this.part !== part) {
            stream.getTracks().forEach((track) => track.stop());
            return;
        }
        const mimeType = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4'].find((type) => MediaRecorder.isTypeSupported?.(type));
        let recorder;
        try {
            recorder = new MediaRecorder(stream, { audioBitsPerSecond: 32000, ...(mimeType ? { mimeType } : {}) });
        } catch (e) {
            stream.getTracks().forEach((track) => track.stop());
            this.off = true;
            this.part = null;
            return;
        }
        const chunks = [];
        recorder.ondataavailable = (event) => event.data.size && chunks.push(event.data);
        recorder.onstop = () => {
            stream.getTracks().forEach((track) => track.stop());
            const type = (recorder.mimeType || 'audio/webm').split(';')[0];
            const blob = new Blob(chunks, { type });
            if (blob.size > 2000) this.enqueue({ part, label, blob, type });
        };
        this.stream = stream;
        this.recorder = recorder;
        recorder.start(1000);
    },

    stop() {
        if (this.recorder && this.recorder.state !== 'inactive') {
            this.recorder.stop();
        } else {
            this.stream?.getTracks().forEach((track) => track.stop());
        }
        this.recorder = null;
        this.stream = null;
        this.part = null;
    },

    isRecording() {
        return !!this.recorder && this.recorder.state === 'recording';
    },

    enqueue(item) {
        this.queue.push(item);
        this.pump();
    },

    pump() {
        if (this.busy || !this.queue.length || !this.wire) return;
        this.busy = true;
        const item = this.queue[0];
        const extension = item.type.includes('mp4') ? 'm4a' : 'webm';
        const file = new File([item.blob], `${item.part}.${extension}`, { type: item.type });
        const retry = () => {
            this.busy = false;
            this.tries++;
            if (this.tries >= 6) {
                this.queue.shift();
                this.tries = 0;
            }
            setTimeout(() => this.pump(), 4000);
        };
        this.wire.upload(
            'voiceClip',
            file,
            () => {
                this.wire
                    .saveVoiceClip(item.part, item.label)
                    .then(() => {
                        this.queue.shift();
                        this.tries = 0;
                        this.busy = false;
                        this.pump();
                    })
                    .catch(retry);
            },
            retry,
        );
    },

    idle() {
        return !this.recorder && !this.busy && this.queue.length === 0;
    },

    /** Runs cb once every piece is sent (or after timeoutMs, whatever is left). */
    whenIdle(cb, timeoutMs = 25000) {
        const started = Date.now();
        const timer = setInterval(() => {
            if (this.idle() || Date.now() - started > timeoutMs) {
                clearInterval(timer);
                cb();
            }
        }, 500);
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
 *
 * `memoryKey` (localStorage) keeps the heard seconds across a reload or a
 * learner who leaves mid-way and comes back where they left off (see
 * eosResume): without it, the half heard before leaving was forgotten and a
 * resumed listen could never reach 90%. Storage may be blocked; the
 * tracker then simply works per page load as before.
 *
 * `onProgress(heard)` runs on every update with the fraction of the
 * recording heard so far (0-1), so a page can show how close a listen is
 * to counting.
 */
window.eosListenTracker = function (media, onListened, threshold = 0.9, onFinished = null, finishedThreshold = 0.6, memoryKey = null, onProgress = null) {
    const heard = new Set();
    let last = null;
    let waitingForRestart = false;
    let unsaved = 0;

    const remember = () => {
        if (!memoryKey) return;
        unsaved = 0;
        try { localStorage.setItem(memoryKey, JSON.stringify([...heard])); } catch (e) {}
    };

    if (memoryKey) {
        try { JSON.parse(localStorage.getItem(memoryKey) ?? '[]').forEach((second) => heard.add(second)); } catch (e) {}
        media.addEventListener('pause', remember);
        document.addEventListener('visibilitychange', () => { if (document.hidden) remember(); });
    }

    const reset = () => {
        heard.clear();
        last = null;
        waitingForRestart = false;
        if (memoryKey) {
            try { localStorage.removeItem(memoryKey); } catch (e) {}
        }
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

        // Only fully played seconds are marked, from continuous playback: a seek fires 'seeking' first and clears
        // `last`. The gap allowed between two updates is generous because a phone with its screen off sends them
        // only every few seconds — those seconds were really heard.
        if (last !== null && now >= last && now - last <= 15) {
            for (let second = Math.floor(last); second < Math.floor(now); second++) {
                heard.add(second);
            }

            if (++unsaved >= 10) remember();
        }

        last = now;

        const share = heard.size / Math.ceil(duration);

        if (onProgress) onProgress(share);

        if (share >= threshold) {
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
 * The frame around every page stays current. The tab numbers and the header
 * avatar read the Alpine "shell" store: <body data-shell> seeds it on every
 * page the server drew, and the bell's "shell-state" events update it
 * (App\Services\AppShell). When the friend lists' fingerprint moves, the open
 * list redraws (eos-friends-changed).
 *
 * Back shows Livewire's copy of the page as it was left: instant, but maybe
 * old. So the store is NOT seeded from that copy, and the copy is drawn again
 * from the server only if it can be out of date: something changed since the
 * page was left (dataVersion: an action of the learner's, or new numbers from
 * the server), or it was left over a minute ago. Plain browsing back and forth
 * stays instant. Each page writes down the moment it is left on its own
 * history entry (eosLeft).
 *
 * Livewire has one way to redraw without adding a history entry: a Back to a
 * page it has no copy of. refreshPage() reports exactly that with a popstate
 * carrying an unknown snapshot id. Any return through history is quiet: no
 * entrance animation (body[data-quiet]) and no progress bar for the redraw
 * (html[data-refreshing]). Pages mid-task ([data-step-header]) or that poll by
 * themselves ([data-stay]) are left alone, so nothing typed or recorded is
 * lost. The same redraw runs when a page comes out of the browser's own back
 * cache, and after a while away from the app on the overview pages
 * ([data-refresh-on-return]).
 */
(() => {
    const redrawAfterAway = 60000;
    const longestBellWait = 1500;

    /** Their requests only keep the page current, so they aren't a change. */
    const backgroundComponents = ['notifications.bell', 'streak.ring'];

    let dataVersion = 0;
    let throughHistory = false;
    let cameFromSnapshot = false;
    let refreshing = false;
    let copyToCheck = null;
    let scrollToKeep = null;
    let hiddenAt = null;

    const takeShellValues = (values) => {
        const shell = Alpine.store('shell');

        if (Object.keys(values).some((key) => key !== 'avatar' && shell[key] !== values[key])) dataVersion++;

        Object.assign(shell, values);
    };

    const seedShell = () => {
        const seed = document.body.dataset.shell;

        if (!seed) return;

        takeShellValues({ ...JSON.parse(seed), avatar: document.querySelector('[data-shell-avatar]')?.innerHTML ?? null });
    };

    const isMidTask = () => document.body.hasAttribute('data-stay') || document.querySelector('[data-step-header]') !== null;

    const overlayIsOpen = () => history.state?.eosBackClose || history.state?.eosBackCloseParked;

    const hasTypedSomething = () =>
        [...document.querySelectorAll('input, textarea')].some(
            (field) => field.value && !['hidden', 'checkbox', 'radio', 'range', 'file', 'submit', 'button'].includes(field.type),
        );

    const refreshPage = () => {
        if (overlayIsOpen()) return;

        window.dispatchEvent(new PopStateEvent('popstate', { state: { alpine: { snapshotIdx: 'eos-refresh', url: location.href } } }));
        refreshing = true;
        document.documentElement.setAttribute('data-refreshing', '');
    };

    const isOutOfDate = (left) => !left || left.version !== dataVersion || Date.now() - left.at >= redrawAfterAway;

    /**
     * The server stops counting the learner as in the app the moment it goes
     * to the background, so a message arriving now buzzes the phone.
     */
    const reportLeftApp = () => {
        const url = document.body.dataset.leftAppUrl;

        if (!url) return;

        const form = new FormData();

        form.append('_token', document.querySelector('meta[name=csrf-token]')?.content ?? '');
        navigator.sendBeacon(url, form);
    };

    const stopCheckingCopy = () => {
        clearTimeout(copyToCheck?.timer);
        copyToCheck = null;
    };

    /**
     * The restored copy's bell asks the server as it wakes up. Its answer can
     * show the copy is old, and swapping the page under that request would
     * land the answer on a removed component, so the decision waits for it;
     * pages without a bell don't wait long.
     */
    const checkCopy = () => {
        const left = copyToCheck.left;

        stopCheckingCopy();

        if (isOutOfDate(left)) refreshPage();
    };

    document.addEventListener('alpine:init', () => {
        Alpine.store('shell', { review: 0, notebook: false, friends: 0, live: null, avatar: null });
        seedShell();
    });

    document.addEventListener('livewire:init', () => {
        Livewire.interceptAction(({ action }) => {
            // A hidden app isn't in the app: the bell's check would say it is.
            if (document.hidden && action.metadata.type === 'poll' && action.component.name === 'notifications.bell') {
                action.cancel();

                return;
            }

            const isBackground = action.metadata.type === 'poll' || action.name === '$refresh' || backgroundComponents.includes(action.component.name);

            if (!isBackground) dataVersion++;
        });
    });

    window.addEventListener('eos-word-saved', () => dataVersion++);

    window.addEventListener('shell-state', ({ detail }) => {
        const listsMoved = detail.live !== Alpine.store('shell').live;

        takeShellValues(detail);

        if (copyToCheck !== null) {
            checkCopy();
        } else if (listsMoved) {
            window.dispatchEvent(new CustomEvent('eos-friends-changed'));
        }
    });

    document.addEventListener('livewire:navigate', ({ detail }) => {
        stopCheckingCopy();
        refreshing = false;
        document.documentElement.removeAttribute('data-refreshing');
        throughHistory = detail.history;
        cameFromSnapshot = detail.history && detail.cached;
    });

    document.addEventListener('livewire:navigating', ({ detail }) => {
        if (refreshing) scrollToKeep = window.scrollY;

        // After Back the browser is already on the entry of the next page.
        if (!throughHistory) history.replaceState({ ...history.state, eosLeft: { version: dataVersion, at: Date.now() } }, '');

        detail.onSwap(() => {
            if (!cameFromSnapshot) seedShell();
            if (throughHistory) document.body.setAttribute('data-quiet', '');
        });
    });

    document.addEventListener('livewire:navigated', () => {
        refreshing = false;
        document.documentElement.removeAttribute('data-refreshing');

        if (scrollToKeep !== null) {
            const top = scrollToKeep;

            scrollToKeep = null;
            requestAnimationFrame(() => window.scrollTo({ top, behavior: 'instant' }));
        }

        if (!cameFromSnapshot) return;

        cameFromSnapshot = false;

        if (isMidTask()) return;

        copyToCheck = { left: history.state?.eosLeft, timer: setTimeout(checkCopy, longestBellWait) };
    });

    window.addEventListener('pageshow', ({ persisted }) => {
        if (!persisted) return;

        if (isMidTask()) {
            Livewire.dispatch('shell-changed');
        } else {
            refreshPage();
        }
    });

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            hiddenAt = Date.now();
            reportLeftApp();

            return;
        }

        if (hiddenAt === null) return;

        const away = Date.now() - hiddenAt;

        hiddenAt = null;

        if (away >= redrawAfterAway && document.body.hasAttribute('data-refresh-on-return') && !isMidTask() && !overlayIsOpen() && !hasTypedSomething()) {
            refreshPage();
        } else {
            Livewire.dispatch('shell-changed');
        }
    });
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
            if (localStorage.getItem('eosVibrationEnabled') === 'false') return;

            navigator.vibrate?.(pattern);
        } catch (e) {}
    };

    window.eosHaptic = {
        buzz,
        correct: () => buzz(12),
        wrong: () => buzz([10, 40, 10]),
        done: () => buzz([18, 50, 28]),
        win: () => buzz([20, 40, 20, 40, 60]),
        lose: () => buzz([60, 80, 60]),
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

        const around = this.sentenceAround(node, start);

        return { word, sentence: around.sentence, range, context: around.context };
    },

    /**
     * The sentence of the nearest block that holds the word at `start` in
     * `node`, and its words with where each one sits in the block — so the
     * sheet can let the learner widen the tapped word into a phrase and
     * mark that phrase in the text (see rangeIn()).
     */
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

        const tokens = Array.from(full.slice(from, to).matchAll(/[A-Za-z]+(?:['’-][A-Za-z]+)*/g), (match) => ({
            text: match[0],
            start: from + match.index,
            end: from + match.index + match[0].length,
        }));
        const tapped = tokens.findIndex((token) => token.start <= index && index < token.end);

        return {
            sentence: full.slice(from, to).replace(/\s+/g, ' ').trim().slice(0, 300),
            context: tapped === -1 ? null : { block, tokens, index: tapped },
        };
    },

    /** A range over the text of `block` from character `start` to `end`, across however many text nodes it holds. */
    rangeIn(block, start, end) {
        const walker = document.createTreeWalker(block, NodeFilter.SHOW_TEXT);
        const range = document.createRange();
        let passed = 0;
        let node;
        let started = false;

        while ((node = walker.nextNode())) {
            const length = node.textContent.length;

            if (!started && start < passed + length) {
                range.setStart(node, start - passed);
                started = true;
            }

            if (started && end <= passed + length) {
                range.setEnd(node, end - passed);

                return range;
            }

            passed += length;
        }

        return null;
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
     *
     * The words of the sentence around the tapped one show as chips: a tap
     * on a neighbour widens the lookup to a phrase ("gave" + "up"), a tap on
     * an end word of the phrase drops it again. When the tapped word is part
     * of a phrasal verb or fixed phrase the dictionary knows, that phrase is
     * offered ready-made (`suggestion`).
     */
    /**
 * The sentence-building review card (see resources/views/components/review):
 * the sentence is heard, its words are tapped in order into the line, and the
 * answer sheet shows once the line is right (or the learner gave up). Counts
 * the full lines that were wrong so the grade can say how easy it was.
 */
Alpine.data('reviewBuild', (words, order, sentence) => ({
    words,
    order,
    sentence,
    picked: [],
    mistakes: 0,
    wrong: false,
    done: false,
    gaveUp: false,

    say(rate = 0.95) {
        window.eosVoice?.speak(this.sentence, { rate });
    },

    pick(index) {
        if (this.done || this.picked.includes(index)) return;

        this.picked.push(index);
        this.wrong = false;

        if (this.picked.length === this.words.length) this.finish();
    },

    unpick(index) {
        if (this.done) return;

        this.picked = this.picked.filter((i) => i !== index);
        this.wrong = false;
    },

    finish() {
        if (this.picked.map((i) => this.words[i]).join(' ') === this.words.join(' ')) {
            this.done = true;
            window.eosFeedback?.right?.();
            return;
        }

        this.mistakes++;
        this.wrong = true;
        window.eosFeedback?.wrong?.();
    },

    reset() {
        this.picked = [];
        this.wrong = false;
    },

    giveUp() {
        this.gaveUp = true;
        this.done = true;
    },
}));

/**
 * The shared word sheet of the Notebook's Words tab (see
 * resources/views/components/notebook/partials/word-sheet.blade.php): shows
 * the tapped word from the data the page carries, at once. It is read again
 * from the page after each call to the server, which re-renders the page
 * with the new sentence.
 */
Alpine.data('notebookWordSheet', () => ({
    word: null,
    active: false,
    writing: '',
    result: null,
    checking: false,
    making: false,
    noneMade: false,
    raw: '',
    all: {},
    fetched: {},
    loading: false,
    sourceLabels: { own: 'Your sentence', ai: 'Made for you ✦', lesson: 'From the lesson' },

    /** Called with the page's `sheet` value: 'w12' is word 12, 'n3' a notebook line, false is closed. */
    open(sheet) {
        if (typeof sheet !== 'string') return;

        this.active = sheet[0] === 'w';

        if (!this.active) return;

        const id = sheet.slice(1);

        if (this.word && String(this.word.id) !== id) {
            this.writing = '';
            this.result = null;
            this.noneMade = false;
        }

        this.word = this.read(id) ?? this.fetched[id] ?? null;

        if (!this.word) this.fetchSheet(id);
    },

    /** The word's data, from the page as it is now (only the words most likely tapped ride on it). */
    read(id) {
        const raw = this.$root.dataset.words ?? '{}';

        if (raw !== this.raw) {
            this.raw = raw;
            this.all = JSON.parse(raw);
        }

        return this.all[id] ?? null;
    },

    /** A word whose sheet is not on the page: asked for from the server when it is tapped. */
    async fetchSheet(id) {
        this.loading = true;

        try {
            this.fetched[id] = await this.$wire.wordSheet(Number(id));

            if (this.active && String(this.word?.id ?? id) === String(id)) this.word = this.fetched[id];
        } catch (e) {
            // The sheet stays empty; tapping the word again asks again.
        }

        this.loading = false;
    },

    async refresh() {
        if (!this.word) return;

        const onPage = this.read(this.word.id);

        if (onPage) {
            this.word = onPage;
        } else {
            await this.fetchSheet(this.word.id);
        }
    },

    get statusDot() {
        return { new: 'bg-ink-faint dark:bg-ink-faint-dark', learning: 'bg-warning', known: 'bg-success dark:bg-success-dark' }[this.word?.status] ?? 'bg-line';
    },

    async another() {
        this.making = true;
        this.noneMade = false;
        const made = await this.$wire.makeSentence(this.word.id);
        this.making = false;
        this.noneMade = made === null;
        await this.refresh();
    },

    async check() {
        if (this.writing.trim() === '') return;

        this.checking = true;
        this.result = await this.$wire.checkSentence(this.word.id, this.writing);
        this.checking = false;
        window.eosFeedback?.[this.result.ok ? 'right' : 'wrong']?.();
        if (this.result.ok) this.writing = '';
        await this.refresh();
    },
}));

Alpine.data('dictionarySheet', (urls) => ({
        isOpen: false,
        loading: false,
        failed: false,
        word: '',
        sentence: '',
        entry: null,
        block: null,
        tokens: [],
        anchor: 0,
        from: 0,
        to: 0,
        suggestion: null,
        lookupTimer: null,
        saved: false,
        saving: false,
        addFailed: false,
        chosen: 0,
        showOthers: false,
        improving: false,
        persianFailed: false,
        meaningPersian: null,
        meaningLoading: false,
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

        /** The words being looked up: the chosen chips, or the tapped word when there are none. */
        get selectedText() {
            return this.tokens.length
                ? this.tokens.slice(this.from, this.to + 1).map((token) => token.text).join(' ')
                : this.word;
        },

        /** The chips on show: the chosen words and up to four on each side of them. */
        get chips() {
            const first = Math.max(0, this.from - 4);
            const last = Math.min(this.tokens.length - 1, this.to + 4);

            return this.tokens.slice(first, last + 1).map((token, offset) => ({ ...token, index: first + offset }));
        },

        /** The suggested phrase, unless it is the one already on show. */
        get offeredPhrase() {
            if (!this.suggestion) return null;

            const from = this.anchor + this.suggestion.from;
            const to = this.anchor + this.suggestion.to;

            return from === this.from && to === this.to ? null : this.suggestion;
        },

        /** The Persian lines to show: the picked meaning's own Persian, else one per part of speech once Gemini has answered, else the quick translation. */
        get persianLines() {
            if (this.chosen !== 0 && this.meaningPersian) {
                return [{ label: '', words: this.meaningPersian }];
            }

            if (this.entry?.persian_senses?.length) {
                return this.entry.persian_senses.map((line) => ({
                    label: this.entry.persian_senses.length > 1 ? (this.persianPos[line.pos] ?? '') : '',
                    words: line.words,
                }));
            }

            return this.entry?.translation_fa ? [{ label: '', words: this.entry.translation_fa }] : [];
        },

        /** The commonness line: dots filled and words, or null while there is no rating. */
        get commonnessLine() {
            const level = this.entry?.commonness;

            return level ? { dots: level, text: ['Less common', 'Common', 'Very common'][level - 1] } : null;
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
            phrase: 'عبارت',
        },

        /**
         * A pick from the other meanings: the Persian line above follows it
         * (asked once per meaning, kept on the server). While it is on its way,
         * or if it never comes, the line stays the word's general Persian.
         */
        async pickMeaning(index) {
            this.chosen = index;
            this.showOthers = false;
            this.meaningPersian = null;

            const sense = this.senses[index];

            if (index === 0 || !sense) return;

            const ticket = this.ticket;
            this.meaningLoading = true;

            try {
                const params = new URLSearchParams({ word: this.word, meaning: sense.definition, pos: sense.pos ?? '' });
                const response = await fetch(`${urls.meaning}?${params}`, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });

                if (!response.ok || ticket !== this.ticket || this.chosen !== index) return;

                this.meaningPersian = (await response.json()).data.translation_fa;
            } catch (error) {
                // Keep the general Persian.
            } finally {
                if (ticket === this.ticket && this.chosen === index) this.meaningLoading = false;
            }
        },

        firstClause(text) {
            const head = text.split(/;\s/)[0].trim();

            return head.length >= 12 ? head : text;
        },

        show({ word, sentence, range, context }) {
            this.word = word;
            this.sentence = sentence ?? '';
            this.block = context?.block ?? null;
            this.tokens = context?.tokens ?? [];
            this.anchor = context?.index ?? 0;
            this.from = this.anchor;
            this.to = this.anchor;
            this.suggestion = null;
            this.isOpen = true;

            this.pauseMedia();
            this.lookup(range);
        },

        /**
         * A tap on a chip: a word outside the phrase widens it up to that word
         * (at most six words), an end word of the phrase drops off, and a
         * word in its middle becomes the only word. Looked up after a short
         * pause, so tapping a few chips in a row asks only once.
         */
        pick(index) {
            if (index < this.from || index > this.to) {
                const from = Math.min(this.from, index);
                const to = Math.max(this.to, index);

                if (to - from + 1 > 6) return;

                this.from = from;
                this.to = to;
            } else if (this.from === this.to) {
                return;
            } else if (index === this.from) {
                this.from++;
            } else if (index === this.to) {
                this.to--;
            } else {
                this.from = index;
                this.to = index;
            }

            this.lookupSoon();
        },

        usePhrase() {
            if (!this.suggestion) return;

            this.from = this.anchor + this.suggestion.from;
            this.to = this.anchor + this.suggestion.to;
            this.lookup();
        },

        lookupSoon() {
            this.ticket++;
            this.loading = true;
            this.entry = null;
            this.markSelection();
            clearTimeout(this.lookupTimer);
            this.lookupTimer = setTimeout(() => this.lookup(), 350);
        },

        markSelection(range = null) {
            if (!window.CSS?.highlights || !window.Highlight) return;

            const marked = range ?? (this.block && this.tokens.length
                ? window.eosLookup.rangeIn(this.block, this.tokens[this.from].start, this.tokens[this.to].end)
                : null);

            if (marked) CSS.highlights.set('eos-lookup', new Highlight(marked));
        },

        async lookup(range = null) {
            clearTimeout(this.lookupTimer);

            const ticket = ++this.ticket;
            const single = this.from === this.to;
            const params = new URLSearchParams({ word: this.selectedText, sentence: this.sentence });

            if (single && this.tokens.length) {
                params.set('before', this.tokens.slice(Math.max(0, this.from - 3), this.from).map((token) => token.text).join(' '));
                params.set('after', this.tokens.slice(this.to + 1, this.to + 4).map((token) => token.text).join(' '));
            }

            this.entry = null;
            this.saved = false;
            this.failed = false;
            this.addFailed = false;
            this.chosen = 0;
            this.meaningPersian = null;
            this.meaningLoading = false;
            this.persianFailed = false;
            this.showOthers = false;
            this.loading = true;
            this.markSelection(range);

            try {
                const response = await fetch(`${urls.lookup}?${params}`, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });

                if (!response.ok) throw new Error(`lookup ${response.status}`);

                const json = await response.json();
                if (ticket !== this.ticket) return;

                this.entry = json.data;
                this.saved = json.saved;
                this.word = json.data.word;

                if (single && this.from === this.anchor) {
                    this.suggestion = json.phrase ?? null;
                }

                if (json.data.english_status !== 'unavailable' && ! json.data.translation_only
                    && (! json.data.persian_senses || (json.data.commonness === null && json.data.word.includes(' ')))) {
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
            this.persianFailed = false;

            try {
                const response = await fetch(`${urls.persian}?word=${encodeURIComponent(this.word)}`, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });

                if (ticket !== this.ticket) return;

                if (! response.ok) {
                    this.persianFailed = true;

                    return;
                }

                this.entry = (await response.json()).data;
            } catch (error) {
                // Keep the quick translation; with none, the sheet says so and offers a retry.
                if (ticket === this.ticket) this.persianFailed = true;
            } finally {
                if (ticket === this.ticket) this.improving = false;
            }
        },

        close() {
            this.isOpen = false;
            this.ticket++;
            clearTimeout(this.lookupTimer);
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
                        mission: window.location.pathname.match(/\/missions\/(M\d{2})/i)?.[1] ?? null,
                    }),
                });

                if (!response.ok) throw new Error(`store ${response.status}`);

                this.saved = true;
                window.dispatchEvent(new CustomEvent('eos-word-saved'));
                Livewire.dispatch('shell-changed');
            } catch (error) {
                this.addFailed = true;
            } finally {
                this.saving = false;
            }
        },
    }));
});

/**
 * Keeps the phone's screen on while a learner listens or watches: any
 * sound or video that is playing out loud (muted looping backgrounds don't
 * count) asks the Screen Wake Lock for the screen, and the lock is let go
 * when everything has paused or ended, the element left the page, or the
 * tab is hidden (the browser drops it then, so it is asked for again when
 * the learner comes back). Browsers without the API just carry on.
 */
(() => {
    if (!('wakeLock' in navigator)) {
        return;
    }

    const playing = new Set();
    let lock = null;
    let asking = false;

    const stillPlaying = () => {
        for (const media of [...playing]) {
            if (!media.isConnected || media.paused || media.ended) {
                playing.delete(media);
            }
        }

        return playing.size > 0;
    };

    const sync = async () => {
        if (stillPlaying()) {
            if (lock || asking || document.visibilityState !== 'visible') {
                return;
            }

            asking = true;

            try {
                lock = await navigator.wakeLock.request('screen');
                lock.addEventListener('release', () => {
                    lock = null;
                });
            } catch {
                lock = null;
            } finally {
                asking = false;
            }

            if (!stillPlaying()) {
                sync();
            }

            return;
        }

        if (lock) {
            const held = lock;
            lock = null;
            held.release().catch(() => {});
        }
    };

    const started = (event) => {
        const media = event.target;

        if (!(media instanceof HTMLMediaElement) || media.muted) {
            return;
        }

        playing.add(media);
        sync();
    };

    ['play', 'playing'].forEach((name) => document.addEventListener(name, started, true));
    ['pause', 'ended', 'emptied', 'abort'].forEach((name) => document.addEventListener(name, sync, true));
    document.addEventListener('visibilitychange', sync);
    document.addEventListener('livewire:navigated', sync);
})();

/**
 * Audio first, text second: a question or an answer is heard, and its text
 * stays blurred until the learner taps it (see <x-heard-text>, <x-teddy-says
 * blur> and <x-show-text-toggle>). "Always show text" is the learner's own
 * choice, kept in this browser. A phone with no English voice has nothing to
 * hear, so its texts open by themselves.
 */
document.addEventListener('alpine:init', () => {
    const englishVoices = () => (window.speechSynthesis?.getVoices() ?? []).filter((voice) => /^en/i.test(voice.lang));

    Alpine.store('heardText', {
        always: (() => {
            try {
                return localStorage.getItem('eos-show-text') === '1';
            } catch (e) {
                return false;
            }
        })(),
        noVoice: !('speechSynthesis' in window),

        init() {
            if (this.noVoice) return;

            const check = () => {
                if (englishVoices().length > 0) this.noVoice = false;
            };

            window.speechSynthesis.addEventListener?.('voiceschanged', check);
            check();
            // Some phones list their voices late, some never: after a moment, none means none.
            setTimeout(() => {
                if (englishVoices().length === 0) this.noVoice = true;
            }, 2500);
        },

        set(on) {
            this.always = on;

            try {
                localStorage.setItem('eos-show-text', on ? '1' : '0');
            } catch (e) {}
        },
    });

    /** The blurred-until-tapped state of one text. */
    Alpine.data('heardReveal', () => ({
        revealed: false,

        get visible() {
            const store = Alpine.store('heardText');

            return this.revealed || store.always || store.noVoice;
        },

        reveal() {
            this.revealed = true;
        },
    }));

    /** One text that is read aloud (say) and blurred until tapped. */
    Alpine.data('heardText', (text) => ({
        text,
        revealed: false,

        get visible() {
            const store = Alpine.store('heardText');

            return this.revealed || store.always || store.noVoice;
        },

        reveal() {
            this.revealed = true;
        },

        say(rate) {
            // A page that reads several texts in a row stops its own reading when one is played by hand.
            window.dispatchEvent(new CustomEvent('eos-heard-text-played'));
            window.eosVoice?.speak(this.text, rate ? { rate } : {});
        },
    }));
});
