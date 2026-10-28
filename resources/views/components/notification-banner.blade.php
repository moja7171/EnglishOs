{{--
    New alerts while the app is open, one at a time at the top of the
    screen — fed by <livewire:notifications.bell> ("notification-banner"
    events) and kept across wire:navigate by @persist, so the queue
    survives page changes. Smart about when:
    - on a focus screen (<body data-no-interrupt>: a mission step, a Pair
      Practice round, shadowing) it waits until the learner leaves it,
      except a live Pair Practice invite;
    - an alert about the page already on screen is never shown;
    - each one shown is reported back, which marks it read.
    A card that drops in — the friend's avatar (or the alert's icon) and one
    short line, a thin bar running down the time it stays. A tap opens it, a
    swipe up (or Esc) dismisses it, a touch pauses it; only a live Pair
    Practice invite carries a green Join button.
--}}
@persist('notification-banner')
<div
    x-data="{
        queue: [],
        seen: new Set(),
        current: null,
        timer: null,
        duration: 6000,
        startedAt: 0,
        left: 0,
        paused: false,
        dragY: 0,
        touchY: null,
        add(items) {
            items.forEach((item) => {
                if (this.seen.has(item.id)) {
                    return;
                }
                this.seen.add(item.id);
                this.queue.push(item);
            });
            this.next();
        },
        waits(item) {
            return ! item.urgent && document.body.hasAttribute('data-no-interrupt');
        },
        next() {
            if (this.current) {
                return;
            }
            const index = this.queue.findIndex((item) => ! this.waits(item));
            if (index === -1) {
                return;
            }
            const item = this.queue.splice(index, 1)[0];
            Livewire.dispatch('notification-shown', { id: item.id });
            if (new URL(item.url, location.origin).pathname === location.pathname) {
                this.next();
                return;
            }
            this.current = item;
            this.dragY = 0;
            this.duration = item.urgent ? 15000 : 6000;
            navigator.vibrate?.(item.urgent ? [80, 60, 80] : 40);
            this.run(this.duration);
        },
        run(ms) {
            this.left = ms;
            this.startedAt = Date.now();
            this.paused = false;
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.close(), ms);
        },
        pause() {
            if (this.paused || ! this.current) {
                return;
            }
            clearTimeout(this.timer);
            this.left = Math.max(0, this.left - (Date.now() - this.startedAt));
            this.paused = true;
        },
        resume() {
            if (this.paused && this.current) {
                this.run(Math.max(1500, this.left));
            }
        },
        touchStart(event) {
            this.touchY = event.touches[0].clientY;
            this.pause();
        },
        touchMove(event) {
            if (this.touchY !== null) {
                this.dragY = Math.min(0, event.touches[0].clientY - this.touchY);
            }
        },
        touchEnd() {
            this.touchY = null;
            if (this.dragY < -40) {
                this.close();
                return;
            }
            this.dragY = 0;
            this.resume();
        },
        close() {
            clearTimeout(this.timer);
            this.current = null;
            this.dragY = 0;
            setTimeout(() => this.next(), 450);
        },
    }"
    x-on:notification-banner.window="add($event.detail.items)"
    x-on:livewire:navigated.document="next()"
    class="pointer-events-none fixed inset-x-0 top-0 z-[80] flex justify-center px-3 pt-[calc(env(safe-area-inset-top)+0.75rem)]"
>
    <template x-if="current">
        <div
            x-transition:enter="transition duration-500 ease-[cubic-bezier(0.22,1.4,0.36,1)] motion-reduce:duration-0"
            x-transition:enter-start="-translate-y-[120%] scale-95 opacity-0"
            x-transition:enter-end="translate-y-0 scale-100 opacity-100"
            x-transition:leave="transition duration-300 ease-in motion-reduce:duration-0"
            x-transition:leave-start="translate-y-0 opacity-100"
            x-transition:leave-end="-translate-y-[120%] opacity-0"
            x-bind:style="dragY ? `transform: translateY(${dragY}px); opacity: ${1 + dragY / 160}` : ''"
            x-on:touchstart.passive="touchStart($event)"
            x-on:touchmove.passive="touchMove($event)"
            x-on:touchend="touchEnd()"
            x-on:mouseenter="pause()"
            x-on:mouseleave="resume()"
            x-on:keydown.escape.window="close()"
            role="status"
            class="pointer-events-auto relative w-full max-w-md overflow-hidden rounded-[1.4rem] bg-surface shadow-[0_14px_40px_-10px_rgba(0,0,0,0.35)] ring-1 ring-black/5 dark:bg-surface-dark dark:ring-white/10"
        >
            <a
                x-bind:href="current.url"
                wire:navigate
                x-on:click="close()"
                x-bind:dir="/[\u0600-\u06FF]/.test(current.title) ? 'rtl' : 'ltr'"
                class="flex items-center gap-3 px-3.5 py-3"
            >
                <span x-show="current.avatar" class="inline-flex shrink-0" x-html="current.avatar"></span>
                <span x-show="! current.avatar" class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark" x-html="current.icon"></span>
                <span class="line-clamp-2 min-w-0 flex-1 text-sm leading-snug font-bold text-ink dark:text-ink-dark" x-text="current.title"></span>
                <span x-show="current.urgent" class="shrink-0 rounded-full bg-success px-4 py-2 text-xs font-extrabold text-white dark:bg-success-dark">Join</span>
            </a>

            {{-- How long it stays; stops while touched. --}}
            <div class="absolute inset-x-3.5 bottom-0 h-0.5">
                <div
                    x-ref="bar"
                    x-effect="if (current && ! paused) { $el.style.transition = 'none'; $el.style.width = (left / duration * 100) + '%'; requestAnimationFrame(() => requestAnimationFrame(() => { $el.style.transition = `width ${left}ms linear`; $el.style.width = '0%'; })); } else if (paused) { $el.style.transition = 'none'; $el.style.width = (left / duration * 100) + '%'; }"
                    class="h-full rounded-full bg-accent/70 dark:bg-accent-dark/70"
                ></div>
            </div>
        </div>
    </template>
</div>
@endpersist
