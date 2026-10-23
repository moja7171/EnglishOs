<x-layouts.app>
    @php
        $back = match ($context) {
            'listening' => ['url' => route('listening.show'), 'label' => 'Listening'],
            'talk' => ['url' => route('pi.practice'), 'label' => 'Daily talk'],
            default => ['url' => route('home'), 'label' => 'Home'],
        };
    @endphp

    <div class="mx-auto max-w-2xl space-y-4 p-6">
        <header>
            <a href="{{ $back['url'] }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-semibold text-ink-faint hover:text-ink dark:text-ink-faint-dark dark:hover:text-ink-dark">
                @svg('heroicon-o-chevron-left', 'h-3.5 w-3.5') {{ $back['label'] }}
            </a>
            <h1 class="mt-3 font-display text-2xl font-extrabold text-ink dark:text-ink-dark">Missions</h1>
            <p class="mt-1 text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">Day {{ $grid['reachedDay'] }} of 96</p>
            <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-surface-sunken dark:bg-surface-sunken-dark">
                <div class="h-full rounded-full bg-accent dark:bg-accent-dark" style="width: {{ round($grid['reachedDay'] / 96 * 100, 1) }}%"></div>
            </div>
        </header>

        <x-mission-grid :missions="$grid['missions']" scroll-to-current />
    </div>
</x-layouts.app>
