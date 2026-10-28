{{-- Shown once, straight after sign-up: the three guide cards full screen,
     ending in the placement test. --}}
<x-layouts.app fill>
    <div data-step-header class="mx-auto flex w-full max-w-md flex-1 flex-col justify-center p-5">
        <x-guide-cards :done-url="route('placement')" />
    </div>
</x-layouts.app>
