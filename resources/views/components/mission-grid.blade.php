@props(['missions'])

{{-- The missions as square photo cards, two to a row on a phone (see
     App\Services\MissionGrid for the data). The photo opens the mission's
     own page. Each day is a circle ringed in three parts — Lesson,
     Listening, Daily talk — that fill as they are done (all three: a green
     tick, see <x-day-ring>); a tap opens the mission's page on that day.
     A finished mission is ticked; one not unlocked yet shows a 🔒 and is
     dimmed (its page still opens as a preview); one not built yet is
     greyed out. The current mission sits above, see <x-mission-grid-hero>.

     @param list<array<string, mixed>> $missions see MissionGrid::for() --}}
<div>
    <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3">
        @foreach ($missions as $mission)
            <li
                wire:key="mission-card-{{ $mission['code'] }}"
                @class([
                    'relative aspect-square overflow-hidden rounded-2xl bg-linear-to-br from-hero to-hero-2',
                    'opacity-60' => ! $mission['built'],
                ])
            >
                @if ($mission['coverUrl'])
                    <img src="{{ $mission['coverUrl'] }}" alt="" loading="lazy" @class(['absolute inset-0 h-full w-full object-cover', 'grayscale' => ! $mission['built'], 'grayscale-[70%] brightness-75' => $mission['built'] && ! $mission['reached'] && ! $mission['done']])>
                @endif
                <div class="absolute inset-0 bg-linear-to-t from-black/80 via-black/25 to-transparent"></div>

                @if ($mission['url'])
                    <a href="{{ $mission['url'] }}" wire:navigate class="absolute inset-0" aria-label="{{ $mission['code'] }} · {{ $mission['title'] }}"></a>
                @endif

                <div class="pointer-events-none absolute inset-x-2 top-2 flex items-start justify-between gap-1">
                    <span></span>
                    @if ($mission['done'])
                        <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-success text-white dark:bg-success-dark" aria-label="Done">@svg('heroicon-s-check', 'h-3.5 w-3.5')</span>
                    @elseif (! $mission['built'] || ! $mission['reached'])
                        <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-black/40 text-white" aria-label="Locked">@svg('heroicon-s-lock-closed', 'h-3 w-3')</span>
                    @endif
                </div>

                <div class="pointer-events-none absolute inset-x-0 bottom-0 p-3">
                    <p class="text-[11px] font-bold tracking-widest text-white/70">{{ $mission['code'] }}</p>
                    <p class="line-clamp-2 text-sm leading-tight font-semibold text-white">{{ $mission['title'] }}</p>
                    <div class="pointer-events-auto mt-2 flex gap-1">
                        @foreach ($mission['days'] as $day)
                            @php
                                $label = "{$mission['code']} · Day {$day['number']}";
                                $doneParts = collect([$day['lesson'], $day['listened'], $day['talked']])->filter()->count();
                            @endphp
                            @if ($day['url'])
                                <a
                                    href="{{ $day['url'] }}"
                                    wire:navigate
                                    aria-label="{{ $label }}{{ $day['state'] === 'today' ? ', today' : '' }}{{ $doneParts === 3 ? ', done' : ($doneParts ? ", {$doneParts} of 3 done" : '') }}"
                                    class="inline-flex shrink-0 transition-transform hover:scale-110 motion-reduce:transition-none"
                                >
                                    <x-day-ring :number="$day['number']" :lesson="$day['lesson']" :listened="$day['listened']" :talked="$day['talked']" :today="$day['state'] === 'today'" on-photo />
                                </a>
                            @else
                                <span
                                    class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full border-2 border-dashed border-white/40 text-xs font-semibold text-white/50"
                                    aria-label="{{ $label }}, not built yet"
                                >{{ $day['number'] }}</span>
                            @endif
                        @endforeach
                    </div>
                </div>
            </li>
        @endforeach
    </ul>
</div>
