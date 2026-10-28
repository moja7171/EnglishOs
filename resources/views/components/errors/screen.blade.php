{{--
    The one screen every error page uses: logo, a big icon, a short English
    title, one Persian line and (usually) one big button. Standalone on
    purpose — no session, no database, no Livewire — so it still renders
    when the app itself is what broke.

    @param string $code The HTTP status, shown small above the title.
    @param string $icon A heroicon name.
    @param string $title
    @param string $line One short Persian sentence.
    @param ?string $action Button label, or null for no button.
    @param ?string $href Where the button goes; null reloads the page.
    @param bool $autoRefresh Reload by itself every 30 seconds (maintenance).
--}}
@props(['code', 'icon', 'title', 'line', 'action' => null, 'href' => null, 'autoRefresh' => false])
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · English OS</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    @if ($autoRefresh)
        <meta http-equiv="refresh" content="30">
    @endif
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;600;700&display=swap" rel="stylesheet">
    <script>
        (function () {
            var saved = null;
            try { saved = localStorage.getItem('eosTheme'); } catch (e) {}
            var dark = saved ? saved === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
            document.documentElement.classList.toggle('dark', dark);
        })();
    </script>
    @vite(['resources/css/app.css'])
</head>
<body class="flex min-h-dvh flex-col bg-ground text-ink antialiased dark:bg-ground-dark dark:text-ink-dark">
    <div class="px-5 pt-5">
        <a href="/" aria-label="English OS home"><x-logo icon-class="h-8 w-8" :with-text="false" /></a>
    </div>
    <main class="mx-auto flex w-full max-w-sm flex-1 flex-col items-center justify-center gap-3 px-6 pb-16 text-center">
        <span class="inline-flex h-20 w-20 items-center justify-center rounded-3xl bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">
            @svg($icon, 'h-10 w-10')
        </span>
        <p class="text-xs font-extrabold tracking-widest text-ink-faint dark:text-ink-faint-dark">{{ $code }}</p>
        <h1 class="font-display text-2xl font-bold text-ink dark:text-ink-dark">{{ $title }}</h1>
        <p dir="rtl" class="text-sm leading-7 text-ink-soft dark:text-ink-soft-dark" style="font-family: 'Vazirmatn', system-ui, sans-serif">{{ $line }}</p>
        @if ($action)
            @if ($href)
                <a href="{{ $href }}" class="mt-3 w-full rounded-full bg-accent px-5 py-3.5 text-base font-extrabold text-white shadow-[0_4px_0_color-mix(in_srgb,var(--color-accent)_60%,#000)] transition-opacity hover:opacity-90 dark:bg-accent-dark">{{ $action }}</a>
            @else
                <button type="button" onclick="location.reload()" class="mt-3 w-full cursor-pointer rounded-full bg-accent px-5 py-3.5 text-base font-extrabold text-white shadow-[0_4px_0_color-mix(in_srgb,var(--color-accent)_60%,#000)] transition-opacity hover:opacity-90 dark:bg-accent-dark">{{ $action }}</button>
            @endif
        @endif
    </main>
</body>
</html>
