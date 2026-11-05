<x-layouts.app>
    <livewire:games.setup :game="$game" />
    <livewire:usage-guide :guide="App\Services\UsageGuides::keyForGame($game)" :auto="false" />
</x-layouts.app>
