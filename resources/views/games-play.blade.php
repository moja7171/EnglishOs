<x-layouts.app :focus="true" :stay="true">
    <livewire:games.play :pair-game="$pairGame" />
    {{-- ⓘ only: the waiting screen teaches each player their own part. --}}
    <livewire:usage-guide :guide="App\Services\UsageGuides::keyForGame($pairGame->game)" :auto="false" />
</x-layouts.app>
