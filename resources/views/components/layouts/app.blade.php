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
            <span>{{ auth()->user()->name }}</span>
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
