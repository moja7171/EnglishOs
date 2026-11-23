@props(['track' => null])

<x-layouts.app>
    @if ($track === 'talk')
        <livewire:review.reading />
        <livewire:usage-guide guide="review_reading" />
    @else
        <livewire:review.index :track="$track" />
        <livewire:usage-guide guide="review" />
    @endif
</x-layouts.app>
