<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'English OS') }}</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    {{-- PWA: installable on a phone home screen (manifest + a minimal
         service worker, see public/sw.js — no offline-first caching,
         this is a server-rendered Livewire app with nothing meaningful
         to serve offline). theme-color tints the OS status bar/task
         switcher to match the app's own navy, not the browser default. --}}
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#211d3f">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="English OS">
    {{-- Vazirmatn: the only Persian-script font in the app, scoped to the
         two steps (AI Feedback #1, Error Log) that render real Persian
         feedback text via the .font-fa utility below — everything else
         keeps using --font-sans (Figtree/system), untouched. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-ground text-ink antialiased dark:bg-ground-dark dark:text-ink-dark">
    {{-- A 3-column grid, not flex+justify-between — that keeps the middle
         nav cluster genuinely centered on the row regardless of how wide
         the logo or the profile group end up being, instead of "centered"
         only by coincidence. Friends and Review live together in that
         middle column so adding Review didn't need a 4th column.
         The side columns use `1fr` (not a fixed third each) so the middle
         column only ever takes the room its own content needs — below
         `sm:` that's icon-only, which is what keeps the logo and the
         right-hand cluster from being squeezed into an equal third and
         overlapping on narrow screens. --}}
    <div class="mx-auto grid max-w-2xl grid-cols-[1fr_auto_1fr] items-center gap-2 px-3 pt-4 text-xs text-ink-faint sm:px-6 dark:text-ink-faint-dark">
        <a href="{{ route('home') }}" wire:navigate class="inline-flex w-fit min-w-0 transition-opacity hover:opacity-80">
            <x-logo icon-class="h-7 w-7 sm:h-8 sm:w-8" text-class="text-sm sm:text-base" />
        </a>

        @auth
            <div class="flex items-center justify-self-center gap-2 sm:gap-4">
                <a href="{{ route('friends.index') }}" wire:navigate title="Friends" class="relative inline-flex items-center gap-1 transition-colors hover:text-ink dark:hover:text-ink-dark">
                    @svg('heroicon-o-user-group', 'h-4 w-4')
                    <span class="hidden sm:inline">Friends</span>
                    @php
                        $unread = \App\Models\DirectMessage::where('recipient_id', auth()->id())->whereNull('read_at')->count();
                        $friendsBadge = $unread + auth()->user()->pendingFollowRequestsCount();
                    @endphp
                    @if ($friendsBadge)
                        <span class="inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-accent px-1 text-[10px] font-bold text-white dark:bg-accent-dark">{{ $friendsBadge }}</span>
                    @endif
                </a>

                <a href="{{ route('review.index') }}" wire:navigate title="Review" class="relative inline-flex items-center gap-1 transition-colors hover:text-ink dark:hover:text-ink-dark">
                    @svg('heroicon-o-bolt', 'h-4 w-4')
                    <span class="hidden sm:inline">Review</span>
                    @php
                        $dueReviewCount = auth()->user()->vocabularyWords()->where('next_review_at', '<=', now())->count()
                            + auth()->user()->speakingPrompts()->where('next_review_at', '<=', now())->count()
                            + auth()->user()->errorPatternReviews()->where('next_review_at', '<=', now())->count();
                    @endphp
                    @if ($dueReviewCount)
                        <span class="inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-accent px-1 text-[10px] font-bold text-white dark:bg-accent-dark">{{ $dueReviewCount }}</span>
                    @endif
                </a>
            </div>

            <div class="flex items-center justify-self-end gap-1.5 sm:gap-3">
                @if ($streak = auth()->user()->currentStreak())
                    <span
                        class="inline-flex items-center gap-1 font-semibold text-accent-ink dark:text-accent-ink-dark"
                        title="{{ $streak }}-day streak"
                    >
                        <x-streak-flame :streak="$streak" />
                        {{ $streak }}
                    </span>
                @endif

                <button
                    type="button"
                    x-data="{ enabled: true }"
                    x-init="enabled = (() => { try { return localStorage.getItem('eosSoundEnabled') !== 'false' } catch (e) { return true } })()"
                    x-on:click="enabled = !enabled; try { localStorage.setItem('eosSoundEnabled', enabled) } catch (e) {}"
                    x-bind:title="enabled ? 'Mute sound effects' : 'Unmute sound effects'"
                    class="inline-flex h-8 w-8 cursor-pointer items-center justify-center rounded-full text-ink-faint transition-colors hover:bg-surface-sunken hover:text-ink dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark dark:hover:text-ink-dark"
                >
                    <span x-show="enabled">@svg('heroicon-o-speaker-wave', 'h-4 w-4')</span>
                    <span x-show="!enabled" x-cloak>@svg('heroicon-o-speaker-x-mark', 'h-4 w-4')</span>
                </button>

                <livewire:notifications.bell />

                <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false">
                    <button
                        type="button"
                        x-on:click="open = !open"
                        class="flex cursor-pointer items-center gap-2 transition-colors hover:text-ink dark:hover:text-ink-dark"
                    >
                        <x-user-avatar :user="auth()->user()" class="h-6 w-6 text-[10px]" />
                        @svg('heroicon-o-chevron-down', 'h-3 w-3')
                    </button>

                    <div
                        x-show="open"
                        x-cloak
                        x-transition.opacity.duration.150ms
                        class="absolute right-0 z-20 mt-2 w-44 overflow-hidden rounded-xl border border-line bg-surface py-1 text-xs shadow-lg dark:border-line-dark dark:bg-surface-dark"
                    >
                        <a
                            href="{{ route('progress.index') }}"
                            wire:navigate
                            x-on:click="open = false"
                            class="flex items-center gap-2 px-3 py-2 font-semibold text-ink-soft transition-colors hover:bg-surface-sunken hover:text-ink dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark dark:hover:text-ink-dark"
                        >@svg('heroicon-o-chart-bar', 'h-4 w-4') My Progress</a>
                        <a
                            href="{{ route('profile') }}"
                            wire:navigate
                            x-on:click="open = false"
                            class="flex items-center gap-2 px-3 py-2 font-semibold text-ink-soft transition-colors hover:bg-surface-sunken hover:text-ink dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark dark:hover:text-ink-dark"
                        >@svg('heroicon-o-user-circle', 'h-4 w-4') Profile &amp; settings</a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button
                                type="submit"
                                class="flex w-full cursor-pointer items-center gap-2 px-3 py-2 text-left font-semibold text-ink-soft transition-colors hover:bg-red-50 hover:text-red-600 dark:text-ink-soft-dark dark:hover:bg-red-950"
                            >@svg('heroicon-o-arrow-right-start-on-rectangle', 'h-4 w-4') Sign out</button>
                        </form>
                    </div>
                </div>
            </div>
        @endauth
    </div>

    {{ $slot }}
    @livewireScripts
    <script>
        // Service workers require a secure context (HTTPS, or exactly
        // "localhost") — this check keeps a plain-HTTP dev visit from
        // throwing in the console instead of silently no-op'ing.
        if ('serviceWorker' in navigator && window.isSecureContext) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/sw.js').catch(() => {
                    // Installability is a progressive enhancement — a
                    // failed registration should never block the app.
                });
            });
        }
    </script>
</body>
</html>
