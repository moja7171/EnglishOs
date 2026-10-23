@props(['missions', 'scrollToCurrent' => false])

{{-- All 24 missions as square photo cards, two to a row on a phone (see
     App\Services\MissionGrid for the data and where each day opens). The
     photo opens the mission's furthest reached day; the four circles open
     one day each. The current mission is ringed, a finished one ticked,
     one not reached yet greyed out with a lock.

     @param list<array{code: string, title: string, coverUrl: ?string, reached: bool, current: bool, done: bool, photoUrl: ?string, days: list<array{number: int, state: string, done: bool, url: ?string}>}> $missions
     @param bool $scrollToCurrent brings the current mission's card into view on load --}}
<ul class="grid grid-cols-2 gap-3 sm:grid-cols-3">
    @foreach ($missions as $mission)
        <li
            wire:key="mission-card-{{ $mission['code'] }}"
            @if ($mission['current'] && $scrollToCurrent) x-data x-init="$el.scrollIntoView({ block: 'center' })" @endif
            @class([
                'relative aspect-square overflow-hidden rounded-2xl bg-linear-to-br from-hero to-hero-2',
                'ring-2 ring-accent ring-offset-2 ring-offset-ground dark:ring-accent-dark dark:ring-offset-ground-dark' => $mission['current'],
                'opacity-60' => ! $mission['reached'],
            ])
        >
            @if ($mission['coverUrl'])
                <img src="{{ $mission['coverUrl'] }}" alt="" loading="lazy" @class(['absolute inset-0 h-full w-full object-cover', 'grayscale' => ! $mission['reached']])>
            @endif
            <div class="absolute inset-0 bg-linear-to-t from-black/80 via-black/25 to-transparent"></div>

            @if ($mission['photoUrl'])
                <a href="{{ $mission['photoUrl'] }}" wire:navigate class="absolute inset-0" aria-label="{{ $mission['code'] }} · {{ $mission['title'] }}"></a>
            @endif

            <div class="pointer-events-none absolute inset-x-2 top-2 flex items-start justify-between gap-1">
                @if ($mission['current'])
                    <span class="rounded-full bg-accent px-2 py-0.5 text-[11px] font-bold text-white dark:bg-accent-dark">You're here</span>
                @else
                    <span></span>
                @endif
                @if ($mission['done'])
                    <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-success text-white dark:bg-success-dark" aria-label="Done">@svg('heroicon-s-check', 'h-3.5 w-3.5')</span>
                @elseif (! $mission['reached'])
                    <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-black/40 text-white" aria-label="Locked">@svg('heroicon-s-lock-closed', 'h-3 w-3')</span>
                @endif
            </div>

            <div class="pointer-events-none absolute inset-x-0 bottom-0 p-3">
                <p class="text-[11px] font-bold tracking-widest text-white/70">{{ $mission['code'] }}</p>
                <p class="line-clamp-2 text-sm leading-tight font-semibold text-white">{{ $mission['title'] }}</p>
                <div class="pointer-events-auto mt-2 flex gap-1.5">
                    @foreach ($mission['days'] as $day)
                        @php
                            $circle = 'inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full border-2 text-xs font-semibold';
                            $label = "{$mission['code']} · Day {$day['number']}";
                        @endphp
                        @if ($day['url'] === null)
                            <span
                                class="{{ $circle }} border-dashed border-white/40 text-white/50"
                                aria-label="{{ $label }}, not reached yet"
                            >{{ $day['number'] }}</span>
                        @else
                            <a
                                href="{{ $day['url'] }}"
                                wire:navigate
                                aria-label="{{ $label }}{{ $day['state'] === 'today' ? ', today' : '' }}{{ $day['done'] ? ', done' : '' }}"
                                @class([
                                    $circle,
                                    'transition-transform hover:scale-110 motion-reduce:transition-none',
                                    'border-success bg-success text-white dark:border-success-dark dark:bg-success-dark' => $day['done'],
                                    'border-accent bg-accent text-white dark:border-accent-dark dark:bg-accent-dark' => ! $day['done'] && $day['state'] === 'today',
                                    'border-white/70 bg-black/20 text-white' => ! $day['done'] && $day['state'] === 'past',
                                ])
                            >
                                @if ($day['done'])
                                    @svg('heroicon-s-check', 'h-4 w-4')
                                @else
                                    {{ $day['number'] }}
                                @endif
                            </a>
                        @endif
                    @endforeach
                </div>
            </div>
        </li>
    @endforeach
</ul>
