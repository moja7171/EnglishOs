@props(['mission'])

{{-- The Missions tab's top card: the mission the learner is on, big, with
     "You're here", today's day and its day rings (see <x-mission-grid>
     for the shape of $mission). --}}
@php $today = collect($mission['days'])->firstWhere('state', 'today'); @endphp
<div class="relative h-44 overflow-hidden rounded-3xl bg-linear-to-br from-hero to-hero-2">
    @if ($mission['coverUrl'])
        <img src="{{ $mission['coverUrl'] }}" alt="" class="absolute inset-0 h-full w-full object-cover">
    @endif
    <div class="absolute inset-0 bg-linear-to-t from-black/80 via-black/30 to-transparent"></div>
    @if ($mission['url'])
        <a href="{{ $mission['url'] }}" wire:navigate class="absolute inset-0" aria-label="{{ $mission['code'] }} · {{ $mission['title'] }}"></a>
    @endif
    <span class="pointer-events-none absolute top-3 left-3 rounded-full bg-white px-2.5 py-0.5 text-xs font-extrabold text-accent-ink">You're here</span>
    <div class="pointer-events-none absolute inset-x-0 bottom-0 p-4">
        <p class="text-xs font-extrabold text-white/80">{{ $mission['code'] }}{{ $today ? ' · Day '.$today['number'] : '' }}</p>
        <p class="text-xl font-extrabold text-white">{{ $mission['title'] }}</p>
        <div class="pointer-events-auto mt-2 flex gap-1.5">
            @foreach ($mission['days'] as $day)
                @if ($day['url'])
                    @php $doneParts = collect([$day['lesson'], $day['listened'], $day['talked']])->filter()->count(); @endphp
                    <a href="{{ $day['url'] }}" wire:navigate aria-label="{{ $mission['code'] }} · Day {{ $day['number'] }}{{ $day['state'] === 'today' ? ', today' : '' }}{{ $doneParts === 3 ? ', done' : ($doneParts ? ", {$doneParts} of 3 done" : '') }}" class="inline-flex shrink-0 transition-transform hover:scale-110">
                        <x-day-ring :number="$day['number']" :lesson="$day['lesson']" :listened="$day['listened']" :talked="$day['talked']" :today="$day['state'] === 'today'" on-photo />
                    </a>
                @endif
            @endforeach
        </div>
    </div>
</div>
