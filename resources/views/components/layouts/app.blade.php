<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'English OS') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-neutral-50 text-neutral-900 antialiased dark:bg-neutral-900 dark:text-neutral-100">
    @auth
        <div class="mx-auto flex max-w-2xl items-center justify-between px-6 pt-4 text-xs text-neutral-500">
            <span>{{ auth()->user()->name }}</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="underline">Sign out</button>
            </form>
        </div>
    @endauth

    {{ $slot }}
    @livewireScripts
</body>
</html>
