<x-layouts.app>
    <div class="mx-auto max-w-2xl space-y-4 p-6">
        <header>
            <h1 class="font-display text-2xl font-extrabold text-ink dark:text-ink-dark">Missions</h1>
            <p class="mt-1 text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">Day {{ $grid['reachedDay'] }} of 96</p>
            <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-surface-sunken dark:bg-surface-sunken-dark">
                <div class="h-full rounded-full bg-accent dark:bg-accent-dark" style="width: {{ round($grid['reachedDay'] / 96 * 100, 1) }}%"></div>
            </div>
        </header>

        <x-mission-grid :missions="$grid['missions']" />
    </div>
</x-layouts.app>
