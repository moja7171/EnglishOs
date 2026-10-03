{{-- @param bool $focus Hides the bottom nav on full-attention screens (a
     mission, the placement test, a partner session, a chat thread) whose
     own actions sit at the bottom of the page. The header stays, and its
     logo is always a way back home. --}}
@props(['focus' => false])

@php
    $showNav = auth()->check() && ! $focus;
@endphp
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
    {{-- Theme (light/dark). Applies the dark class before first paint (saved
         toggle choice, else the OS preference) so a dark-mode learner never
         sees a white flash. wire:navigate copies the NEW page's <html>
         attributes over the current ones on every navigation — which strips
         the class, since the server never renders it — so a MutationObserver
         puts it straight back (microtask, i.e. before paint). window.eosTheme
         is the single source of truth; the header toggle just calls set(). --}}
    <script>
        (function () {
            var root = document.documentElement;
            var media = window.matchMedia('(prefers-color-scheme: dark)');
            var saved = null;
            try { saved = localStorage.getItem('eosTheme'); } catch (e) {}

            var theme = window.eosTheme = {
                dark: saved ? saved === 'dark' : media.matches,
                set: function (dark) {
                    theme.dark = dark;
                    try { localStorage.setItem('eosTheme', dark ? 'dark' : 'light'); } catch (e) {}
                    apply();
                },
            };

            function apply() {
                if (root.classList.contains('dark') !== theme.dark) {
                    root.classList.toggle('dark', theme.dark);
                }
                var meta = document.querySelector('meta[name=theme-color]');
                if (meta) { meta.setAttribute('content', theme.dark ? '#0f0d21' : '#211d3f'); }
            }

            apply();
            new MutationObserver(apply).observe(root, { attributes: true, attributeFilter: ['class'] });
            media.addEventListener('change', function (event) {
                var chosen = null;
                try { chosen = localStorage.getItem('eosTheme'); } catch (e) {}
                if (! chosen) { theme.dark = event.matches; apply(); }
            });
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-ground text-ink antialiased dark:bg-ground-dark dark:text-ink-dark {{ $showNav ? 'pb-20' : '' }}">
    {{-- Deliberately bare: the logo mark on the left; notifications and the
         avatar (which opens <x-account-menu>) on the right. Navigation
         lives in <x-bottom-nav> and everything secondary in the avatar's
         sheet, so the top of the screen stays calm. Signed-out pages keep
         the full wordmark (it is the only branding on the login screen)
         and the theme switch. --}}
    <div class="mx-auto flex max-w-2xl items-center justify-between gap-2 px-3 pt-4 text-xs text-ink-faint sm:px-6 dark:text-ink-faint-dark">
        <a href="{{ route('home') }}" wire:navigate aria-label="English OS home" class="inline-flex w-fit min-w-0 transition-opacity hover:opacity-80">
            <x-logo icon-class="h-8 w-8" :with-text="! auth()->check()" text-class="text-sm sm:text-base" />
        </a>

        @auth
            <div class="flex items-center gap-1.5">
                <livewire:notifications.bell />
                <x-account-menu />
            </div>
        @else
            <x-theme-toggle />
        @endauth
    </div>

    {{ $slot }}

    @if ($showNav)
        <x-bottom-nav />
    @endif

    {{-- Custom install prompt: Chrome/Edge on Android removed their own
         automatic "Add to Home screen" banner years ago — a site now has to
         capture beforeinstallprompt itself and offer its own call to action,
         or nothing ever invites the user to install at all (the browser's
         only remaining affordance is a buried "Install app" menu item). --}}
    <div
        x-data="{
            deferredPrompt: null,
            dismissed: false,
            init() {
                try { this.dismissed = localStorage.getItem('eosInstallPromptDismissed') === 'true' } catch (e) {}

                window.addEventListener('beforeinstallprompt', (event) => {
                    event.preventDefault();
                    this.deferredPrompt = event;
                });

                window.addEventListener('appinstalled', () => this.dismiss());
            },
            async install() {
                if (! this.deferredPrompt) { return }
                this.deferredPrompt.prompt();
                await this.deferredPrompt.userChoice;
                this.deferredPrompt = null;
            },
            dismiss() {
                this.dismissed = true;
                try { localStorage.setItem('eosInstallPromptDismissed', 'true') } catch (e) {}
            },
        }"
        x-show="deferredPrompt && !dismissed"
        x-cloak
        x-transition
        class="fixed inset-x-3 {{ $showNav ? 'bottom-20' : 'bottom-3' }} z-30 mx-auto flex max-w-2xl items-center gap-3 rounded-xl border border-line bg-surface px-4 py-3 text-xs shadow-lg sm:inset-x-6 dark:border-line-dark dark:bg-surface-dark"
    >
        <x-logo icon-class="h-8 w-8" :with-text="false" />
        <div class="flex-1">
            <p class="font-semibold text-ink dark:text-ink-dark">Install English OS</p>
            <p class="text-ink-faint dark:text-ink-faint-dark">Add it to your home screen for quick, full-screen access.</p>
        </div>
        <button type="button" x-on:click="dismiss()" class="px-2 py-1.5 font-semibold text-ink-faint transition-colors hover:text-ink dark:text-ink-faint-dark dark:hover:text-ink-dark">Not now</button>
        <button type="button" x-on:click="install()" class="shrink-0 rounded-full bg-accent px-3 py-1.5 font-semibold text-white dark:bg-accent-dark">Install</button>
    </div>

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
