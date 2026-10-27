<x-layouts.app>
    {{-- A2: the mission the learner is on as one big card on top (one tap
         to go on), then every other mission in a grid — ✓ when finished,
         🔒 when not unlocked yet (a preview still opens). --}}
    @php $current = collect($grid['missions'])->firstWhere('current', true); @endphp
    <div class="mx-auto max-w-2xl space-y-4 p-4 sm:p-6">
        <header class="flex items-baseline justify-between gap-3">
            <h1 class="font-display text-3xl font-semibold text-ink dark:text-ink-dark">Missions</h1>
            <p class="text-sm font-bold text-ink-faint dark:text-ink-faint-dark">Day {{ $grid['reachedDay'] }} of 96</p>
        </header>

        @if ($current)
            <x-mission-grid-hero :mission="$current" />
        @endif

        <x-mission-grid :missions="collect($grid['missions'])->reject(fn ($mission) => $mission['current'])->values()->all()" />
    </div>
</x-layouts.app>
