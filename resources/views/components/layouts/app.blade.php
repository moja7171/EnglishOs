<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'English OS') }}</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-ground text-ink antialiased dark:bg-ground-dark dark:text-ink-dark">
    <div class="mx-auto max-w-2xl px-6 pt-4">
        <a href="{{ route('home') }}" wire:navigate class="inline-flex transition-opacity hover:opacity-80">
            <x-logo icon-class="h-8 w-8" text-class="text-base" />
        </a>
    </div>

    @auth
        <div class="mx-auto flex max-w-2xl items-center justify-between px-6 pt-3 text-xs text-ink-faint dark:text-ink-faint-dark">
            <span class="flex items-center gap-3">
                <a href="{{ route('profile') }}" wire:navigate class="flex items-center gap-2 transition-colors hover:text-ink dark:hover:text-ink-dark">
                    <x-user-avatar :user="auth()->user()" class="h-6 w-6 text-[10px]" />
                    {{ auth()->user()->name }}
                </a>
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
            <span class="flex items-center gap-3">
                <a href="{{ route('friends.index') }}" wire:navigate class="relative inline-flex items-center gap-1 transition-colors hover:text-ink dark:hover:text-ink-dark">
                    @svg('heroicon-o-user-group', 'h-4 w-4')
                    Friends
                    @php
                        $unread = auth()->check()
                            ? \App\Models\DirectMessage::where('recipient_id', auth()->id())->whereNull('read_at')->count()
                            : 0;
                    @endphp
                    @if ($unread)
                        <span class="inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-accent px-1 text-[10px] font-bold text-white dark:bg-accent-dark">{{ $unread }}</span>
                    @endif
                </a>
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
