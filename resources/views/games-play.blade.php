<x-layouts.app :focus="true" :stay="true">
    <livewire:games.play :pair-game="$pairGame" />
    {{-- For the invited friend, who never saw the setup; never over a running turn. --}}
    <livewire:usage-guide :guide="App\Services\UsageGuides::keyForGame($pairGame->game)" :auto="$pairGame->isWaiting() || $pairGame->stage === App\Models\PairGame::STAGE_READY" />
</x-layouts.app>
