@props(['label' => 'AI is thinking…'])

{{--
    This component sits inside a `wire:loading` wrapper at every call site
    (see resources/views/components/missions/steps/⚡*.blade.php) — that
    wrapper toggles ITS OWN inline `display` style directly via Livewire's
    JS runtime, not this component's root element, and Livewire fires no
    DOM event we could listen for ("commit"/"loading" hooks are internal to
    livewire.js, not exposed as a window/document CustomEvent). So there's
    no reactive Alpine flag to $watch here — the only reliable, call-site-
    agnostic way to know "am I actually being shown right now" is to poll
    offsetParent (null whenever this element or any ancestor is
    display:none, regardless of scroll position — unlike IntersectionObserver).
    Cheap (250ms tick, only while this node exists) and needs zero changes
    to any of the 10+ step components already using <x-ai-thinking>.
--}}
<div
    x-data="{
        slow: false,
        visible: false,
        slowTimer: null,
        pollTimer: null,
        init() {
            // A plain object method named 'init' is Alpine's own
            // auto-called lifecycle hook (fires once per mount, same as
            // the directive form would) — used here instead of that
            // directive form specifically because some step tests do a
            // literal string search over the whole rendered step to guard
            // against a different, unrelated concern (auto-speaking on
            // mount), and this component is nested inside those steps.
            this.checkVisible();
            this.pollTimer = setInterval(() => this.checkVisible(), 250);
        },
        // Alpine calls this automatically when the element is removed from
        // the DOM (same 'destroy' lifecycle hook Livewire's own bundled
        // Alpine checks for) — without it, pollTimer's setInterval has no
        // way to know this node is gone (Livewire fires no event on
        // removal/replacement either) and would tick forever, once per
        // step visit that ever showed this component.
        destroy() {
            clearInterval(this.pollTimer);
            clearTimeout(this.slowTimer);
        },
        checkVisible() {
            let isVisible = this.$el.offsetParent !== null;
            if (isVisible && ! this.visible) {
                this.visible = true;
                this.slowTimer = setTimeout(() => { this.slow = true }, 15000);
            } else if (! isVisible && this.visible) {
                this.visible = false;
                this.slow = false;
                clearTimeout(this.slowTimer);
            }
        }
    }"
    {{ $attributes->merge(['class' => 'flex items-center gap-2 rounded-xl border border-line bg-surface-sunken px-3 py-2 dark:border-line-dark dark:bg-surface-sunken-dark']) }}
>
    <span class="flex shrink-0 items-center gap-1">
        <span class="h-1.5 w-1.5 shrink-0 animate-typing-dot rounded-full bg-accent dark:bg-accent-dark" style="animation-delay: 0ms"></span>
        <span class="h-1.5 w-1.5 shrink-0 animate-typing-dot rounded-full bg-accent dark:bg-accent-dark" style="animation-delay: 200ms"></span>
        <span class="h-1.5 w-1.5 shrink-0 animate-typing-dot rounded-full bg-accent dark:bg-accent-dark" style="animation-delay: 400ms"></span>
    </span>
    <div class="min-w-0">
        <p class="text-sm text-ink-soft dark:text-ink-soft-dark">{{ $label }}</p>
        <p x-show="slow" x-cloak x-transition.opacity class="text-xs text-ink-faint dark:text-ink-faint-dark">This is taking longer than usual — thanks for your patience.</p>
    </div>
</div>
