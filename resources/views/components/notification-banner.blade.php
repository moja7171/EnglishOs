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
    Frosted card that drops in, a thin bar running down the time it stays,
    a tap opens it, a swipe up (or ×) dismisses it, and a touch pauses it.
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
            role="status"
            class="pointer-events-auto relative w-full max-w-md overflow-hidden rounded-[1.4rem] border border-white/60 bg-surface/80 shadow-[0_18px_50px_-12px_rgba(0,0,0,0.35)] ring-1 ring-black/5 backdrop-blur-xl backdrop-saturate-150 dark:border-white/10 dark:bg-surface-dark/80 dark:ring-white/5"
        >
            {{-- A soft wash of the accent colour behind the content. --}}
            <div aria-hidden="true" class="pointer-events-none absolute inset-0 bg-linear-to-br from-accent-soft/70 via-transparent to-transparent dark:from-accent-soft-dark/30"></div>

            <div class="relative flex items-center gap-3 p-3 pr-2">
                <a
                    x-bind:href="current.url"
                    wire:navigate
                    x-on:click="close()"
                    class="flex min-w-0 flex-1 items-center gap-3"
                >
                    <span class="relative inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-linear-to-br from-accent to-accent-ink text-white shadow-lg shadow-accent/30 dark:from-accent-dark dark:to-accent-ink-dark [&_svg]:h-5 [&_svg]:w-5" x-html="current.icon"></span>
                    <span class="min-w-0 flex-1">
                        <span class="flex items-center gap-1.5 text-[11px] font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">
                            <span class="relative flex h-1.5 w-1.5">
                                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-accent opacity-75 motion-reduce:hidden dark:bg-accent-dark"></span>
                                <span class="relative inline-flex h-1.5 w-1.5 rounded-full bg-accent dark:bg-accent-dark"></span>
                            </span>
                            <span x-text="current.urgent ? 'Live' : 'Now'"></span>
                        </span>
                        <span dir="auto" class="mt-0.5 line-clamp-2 block text-sm leading-snug font-semibold text-ink dark:text-ink-dark" x-text="current.title"></span>
                    </span>
                    <span class="shrink-0 rounded-full bg-accent px-3 py-1.5 text-xs font-bold text-white shadow-sm dark:bg-accent-dark" x-text="current.urgent ? 'Join' : 'Open'"></span>
                </a>
                <button type="button" x-on:click="close()" aria-label="Close" class="inline-flex h-8 w-8 shrink-0 cursor-pointer items-center justify-center rounded-full text-ink-faint transition-colors hover:bg-surface-sunken/80 dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark/80">
                    @svg('heroicon-o-x-mark', 'h-4 w-4')
                </button>
            </div>

            {{-- How long it stays; stops while touched. --}}
            <div class="relative h-0.5 bg-transparent">
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
