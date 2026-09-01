<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'English OS') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-ground text-ink antialiased dark:bg-ground-dark dark:text-ink-dark">
    @auth
        <div class="mx-auto flex max-w-2xl items-center justify-between px-6 pt-4 text-xs text-ink-faint dark:text-ink-faint-dark">
            <span class="flex items-center gap-3">
                {{ auth()->user()->name }}
                @if ($streak = auth()->user()->currentStreak())
                    <span
                        class="inline-flex items-center gap-1 font-semibold text-accent-ink dark:text-accent-ink-dark"
                        title="{{ $streak }}-day streak"
                    >
                        @svg('heroicon-s-fire', 'h-3.5 w-3.5')
                        {{ $streak }}
                    </span>
                @endif
            </span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="cursor-pointer underline transition-colors hover:text-ink dark:hover:text-ink-dark">Sign out</button>
            </form>
        </div>
    @endauth

    {{ $slot }}
    @livewireScripts
</body>
</html>
