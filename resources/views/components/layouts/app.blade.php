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
    {{ $slot }}
    @livewireScripts
</body>
</html>
