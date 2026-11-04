<x-layouts.app :focus="true">
    <livewire:pair-practice.session :session="$session" />
    {{-- For the invited friend, who comes straight here; not over a round that's over. --}}
    <livewire:usage-guide guide="pair_practice" :auto="! $session->isFinished() && ! $session->isCancelled()" />
</x-layouts.app>
