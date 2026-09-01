{{-- Shown after the 2nd failed check attempt on a field — a heads-up
     before <x-reveal-offer> appears on attempt 3, so the offer doesn't
     come out of nowhere. See App\Livewire\Concerns\TracksCheckAttempts. --}}
@props(['show'])

@if ($show)
    <p class="mt-2 text-xs text-neutral-400 italic">One more try — after that I can write the correct one for you if you'd like.</p>
@endif
