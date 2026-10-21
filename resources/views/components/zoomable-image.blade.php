{{-- A picture that opens big in <x-image-viewer> when tapped. The class
     goes on the <img>; the button around it adds nothing visual. --}}
@props(['url', 'alt' => ''])

<button
    type="button"
    x-data
    x-on:click="$dispatch('zoom-image', { url: @js($url), alt: @js($alt) })"
    aria-label="Enlarge picture"
    class="block cursor-zoom-in"
>
    <img src="{{ $url }}" alt="{{ $alt }}" {{ $attributes }}>
</button>
