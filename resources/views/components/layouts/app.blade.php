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
    <div class="mx-auto flex max-w-2xl items-center justify-between px-6 pt-4 text-xs text-ink-faint dark:text-ink-faint-dark">
        <a href="{{ route('home') }}" wire:navigate class="inline-flex transition-opacity hover:opacity-80">
            <x-logo icon-class="h-8 w-8" text-class="text-base" />
        </a>

        @auth
            <div class="flex items-center gap-4">
                <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false">
                    <button
                        type="button"
                        x-on:click="open = !open"
                        class="flex cursor-pointer items-center gap-2 transition-colors hover:text-ink dark:hover:text-ink-dark"
                    >
                        <x-user-avatar :user="auth()->user()" class="h-6 w-6 text-[10px]" />
                        {{ auth()->user()->name }}
                        @svg('heroicon-o-chevron-down', 'h-3 w-3')
                    </button>

                    <div
                        x-show="open"
                        x-cloak
                        x-transition.opacity.duration.150ms
                        class="absolute right-0 z-20 mt-2 w-44 overflow-hidden rounded-xl border border-line bg-surface py-1 text-xs shadow-lg dark:border-line-dark dark:bg-surface-dark"
                    >
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

                @if ($streak = auth()->user()->currentStreak())
                    <span
                        class="inline-flex items-center gap-1 font-semibold text-accent-ink dark:text-accent-ink-dark"
                        title="{{ $streak }}-day streak"
                    >
                        @svg('heroicon-s-fire', 'h-3.5 w-3.5')
                        {{ $streak }}
                    </span>
                @endif

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
            </div>
        @endauth
    </div>

    {{ $slot }}
    @livewireScripts
</body>
</html>
