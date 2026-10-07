{{--
    The signed-in app's primary navigation: three fixed tabs at the bottom
    of the screen, where a thumb reaches (the app is used mostly on
    phones and tablets). Replaces the old crowded header, which carried
    Friends, Review, streak, theme, sound, notifications and the account
    menu all at once.

    Home / Progress / Review are the three places a learner returns to
    every day. Everything secondary — Friends, Listening, profile, the
    theme and sound switches, Sign out — lives behind the avatar in the
    header, see <x-account-menu>.

    It steps aside while the learner is typing (the on-screen keyboard
    would otherwise leave it floating above the keys, eating room) and
    while the mic is recording (a stray tap mid-take would lose it) — see
    the x-show below and the eos-recording event in <x-voice-recorder>.
--}}
@php
    $user = auth()->user();

    $dueReviewCount = $user->dailyReviewCount();

    $tabs = [
        ['label' => 'Home', 'route' => 'home', 'active' => request()->routeIs('home'), 'icon' => 'home'],
        ['label' => 'Progress', 'route' => 'progress.index', 'active' => request()->routeIs('progress.*'), 'icon' => 'chart-bar'],
        ['label' => 'Review', 'route' => 'review.index', 'active' => request()->routeIs('review.*'), 'icon' => 'bolt', 'badge' => $dueReviewCount],
    ];

    // -1 on pages that belong to none of the three tabs (Friends, Profile...).
    $activeIndex = collect($tabs)->search(fn (array $tab): bool => $tab['active']);
    $activeIndex = $activeIndex === false ? -1 : $activeIndex;
@endphp

<nav
    x-data="{
        typing: false,
        recording: false,
        // The nav is rebuilt on every wire:navigate, so the tab the learner
        // came from is remembered on window: the pill starts there and
        // slides to the new tab, and the new tab's icon hops.
        init() {
            const to = {{ $activeIndex }};
            const from = window.eosNavIndex ?? to;
            window.eosNavIndex = to;

            const calm = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            if (calm || to < 0 || from < 0 || from === to) { return }

            const pill = this.$refs.pill;
            pill.style.transition = 'none';
            pill.style.translate = `${from * 100}% 0`;
            pill.getBoundingClientRect();
            pill.style.transition = '';
            pill.style.translate = `${to * 100}% 0`;

            this.$refs.activeIcon?.classList.add('animate-tab-pop');
        },
        // Re-read from the real focus on every focus change and click, so a
        // field that vanishes while focused (a step re-render) can never
        // leave the nav stuck hidden.
        checkTyping() {
            this.typing = document.activeElement?.matches('textarea, select, [contenteditable=true], input:not([type=checkbox]):not([type=radio]):not([type=button]):not([type=submit]):not([type=range]):not([type=file])') ?? false;
        },
    }"
    x-show="! typing && ! recording"
    x-transition.opacity.duration.150ms
    x-on:focusin.window="checkTyping()"
    x-on:focusout.window="checkTyping()"
    x-on:click.window="checkTyping()"
    x-on:eos-recording.window="recording = $event.detail"
    aria-label="Primary"
    class="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-surface/95 backdrop-blur dark:border-line-dark dark:bg-surface-dark/95"
>
    <div class="relative mx-auto grid max-w-2xl grid-cols-3">
        {{-- The soft highlight behind the current tab. One element for all
             three, so it can glide from tab to tab (see init() above). --}}
        <span
            x-ref="pill"
            aria-hidden="true"
            class="pointer-events-none absolute inset-y-0 left-0 w-1/3 p-1.5 transition-[translate,opacity] duration-300 ease-out motion-reduce:transition-none {{ $activeIndex < 0 ? 'opacity-0' : '' }}"
            style="translate: {{ max($activeIndex, 0) * 100 }}% 0"
        >
            <span class="block h-full rounded-2xl bg-accent-soft dark:bg-accent-soft-dark"></span>
        </span>
        @foreach ($tabs as $tab)
            <a
                href="{{ route($tab['route']) }}"
                wire:navigate
                @if ($tab['active']) aria-current="page" @endif
                class="relative flex flex-col items-center gap-0.5 py-2 text-[11px] font-semibold transition-colors {{ $tab['active'] ? 'text-accent-ink dark:text-accent-ink-dark' : 'text-ink-faint hover:text-ink dark:text-ink-faint-dark dark:hover:text-ink-dark' }}"
            >
                <span class="relative" @if ($tab['active']) x-ref="activeIcon" @endif>
                    @svg('heroicon-'.($tab['active'] ? 's' : 'o').'-'.$tab['icon'], 'h-6 w-6')
                    @if (! empty($tab['badge']))
                        <span class="absolute -top-1 -right-2.5 inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-accent px-1 text-[10px] font-bold text-white dark:bg-accent-dark">{{ $tab['badge'] }}</span>
                    @endif
                </span>
                {{ $tab['label'] }}
            </a>
        @endforeach
    </div>
</nav>
