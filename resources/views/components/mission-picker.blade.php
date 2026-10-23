@props(['missions', 'missionCode', 'routeName', 'todayCode' => null])

{{-- The line under a practice page's title (/listening, /pi) — "M02 ·
     People & Relationships · Day 1 of 4" — doubling as the way to another
     mission: a tap opens the missions the learner has reached (see
     ListeningPicks::missionsReachedBy()), newest first, as a bottom sheet
     on a phone. A mission opens on its day 1; the one the page treats as
     today's opens on today. With a single mission there is nothing to
     pick, so it stays plain text.

     @param list<array{code: string, title: string}> $missions
     @param string $routeName listening.show or pi.practice
     @param string|null $todayCode the mission whose link opens today --}}
@if (count($missions) < 2)
    <p class="mt-0.5 text-sm text-ink-soft dark:text-ink-soft-dark">{{ $slot }}</p>
@else
    <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false">
        <button
            type="button"
            x-on:click="open = ! open"
            aria-haspopup="menu"
            x-bind:aria-expanded="open"
            class="mt-0.5 inline-flex cursor-pointer items-center gap-1 text-start text-sm text-ink-soft transition-colors hover:text-ink dark:text-ink-soft-dark dark:hover:text-ink-dark"
        >
            <span>{{ $slot }}</span>
            @svg('heroicon-o-chevron-down', 'h-4 w-4 shrink-0')
        </button>

        <x-menu-panel role="menu" aria-label="Missions" width="sm:w-72">
            <div class="sm:max-h-96 sm:overflow-y-auto sm:py-1">
                @foreach ($missions as $mission)
                    <a
                        href="{{ $mission['code'] === $todayCode ? route($routeName) : route($routeName, [$mission['code'], 1]) }}"
                        wire:navigate
                        role="menuitem"
                        x-on:click="open = false"
                        @if ($mission['code'] === $missionCode) aria-current="page" @endif
                        @class([
                            'flex items-center gap-3 px-4 py-3 text-sm transition-colors hover:bg-surface-sunken dark:hover:bg-surface-sunken-dark',
                            'font-semibold text-ink dark:text-ink-dark' => $mission['code'] === $missionCode,
                            'text-ink-soft dark:text-ink-soft-dark' => $mission['code'] !== $missionCode,
                        ])
                    >
                        <span class="w-8 shrink-0 text-xs font-bold text-ink-faint dark:text-ink-faint-dark">{{ $mission['code'] }}</span>
                        <span class="min-w-0 flex-1 truncate">{{ $mission['title'] }}</span>
                        @if ($mission['code'] === $todayCode)
                            <span class="text-xs text-accent-ink dark:text-accent-ink-dark">today</span>
                        @endif
                    </a>
                @endforeach
            </div>
        </x-menu-panel>
    </div>
@endif
