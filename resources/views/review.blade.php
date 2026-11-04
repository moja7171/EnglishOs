@props(['track' => null])

<x-layouts.app>
    <livewire:review.index :track="$track" />
    <livewire:usage-guide guide="review" />
</x-layouts.app>
