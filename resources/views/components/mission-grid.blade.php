@props(['missions', 'openDay' => null])

{{-- All 24 missions as square photo cards, two to a row on a phone (see
     App\Services\MissionGrid for the data). The photo opens the mission's
     furthest reached lesson day, or a finished mission's recap. Each day is
     a circle ringed in three parts — Lesson, Listening, Daily talk — that
     fill as they are done (all three: a green tick); a tap opens the day's
     sheet with those three, one tap each. The current mission is ringed and
     scrolled into view, a finished one ticked, one not reached yet greyed
     out with a lock.

     @param list<array<string, mixed>> $missions see MissionGrid::for()
     @param string|null $openDay "M03-2": that day's sheet opens on load (the
         way back from its Listening or Daily talk page) --}}
@php
    $parts = [
        ['key' => 'lesson', 'label' => 'Lesson', 'icon' => 'book-open'],
        ['key' => 'listened', 'url' => 'listeningUrl', 'label' => 'Listening', 'icon' => 'speaker-wave'],
        ['key' => 'talked', 'url' => 'talkUrl', 'label' => 'Daily talk', 'icon' => 'microphone'],
    ];
    // One third of the ring (r = 12) less a small gap on each side.
    $circumference = 2 * M_PI * 12;
    $segment = $circumference / 3 - 3;
@endphp

<div x-data="{ open: false, sheet: null, show(day) { this.sheet = day; this.open = true } }">
    <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3">
        @foreach ($missions as $mission)
            <li
                wire:key="mission-card-{{ $mission['code'] }}"
                @if ($mission['current'] && ! $openDay) x-init="$el.scrollIntoView({ block: 'center' })" @endif
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
                    <div class="pointer-events-auto mt-2 flex gap-1">
                        @foreach ($mission['days'] as $day)
                            @php
                                $label = "{$mission['code']} · Day {$day['number']}";
                                $doneParts = collect($parts)->filter(fn ($part) => $day[$part['key']])->count();
                                $allDone = $doneParts === 3;
                            @endphp
                            @if ($day['state'] === 'future')
                                <span
                                    class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full border-2 border-dashed border-white/40 text-xs font-semibold text-white/50"
                                    aria-label="{{ $label }}, not reached yet"
                                >{{ $day['number'] }}</span>
                            @else
                                @php
                                    $sheet = [
                                        'title' => "{$mission['code']} · {$mission['title']} · Day {$day['number']}",
                                        'lesson' => $day['lesson'], 'listened' => $day['listened'], 'talked' => $day['talked'],
                                        'lessonUrl' => $day['lessonUrl'], 'listeningUrl' => $day['listeningUrl'], 'talkUrl' => $day['talkUrl'],
                                    ];
                                @endphp
                                <button
                                    type="button"
                                    x-on:click="show({{ Illuminate\Support\Js::from($sheet) }})"
                                    @if ($openDay === "{$mission['code']}-{$day['number']}") x-init="show({{ Illuminate\Support\Js::from($sheet) }}); $el.scrollIntoView({ block: 'center' })" @endif
                                    aria-label="{{ $label }}{{ $day['state'] === 'today' ? ', today' : '' }}{{ $allDone ? ', done' : ($doneParts ? ", {$doneParts} of 3 done" : '') }}"
                                    class="relative inline-flex h-8 w-8 shrink-0 cursor-pointer items-center justify-center transition-transform hover:scale-110 motion-reduce:transition-none"
                                >
                                    <svg viewBox="0 0 32 32" class="absolute inset-0 h-full w-full -rotate-90" aria-hidden="true">
                                        @foreach ($parts as $index => $part)
                                            <circle
                                                cx="16" cy="16" r="12" fill="none" stroke-width="3" stroke-linecap="round"
                                                stroke-dasharray="{{ round($segment, 2) }} {{ round($circumference - $segment, 2) }}"
                                                stroke-dashoffset="{{ round(-($index * $circumference / 3 + 1.5), 2) }}"
                                                @class([
                                                    'stroke-success dark:stroke-success-dark' => $day[$part['key']],
                                                    'stroke-white/35' => ! $day[$part['key']],
                                                ])
                                            />
                                        @endforeach
                                    </svg>
                                    <span @class([
                                        'relative inline-flex h-5 w-5 items-center justify-center rounded-full text-[11px] font-bold text-white',
                                        'bg-success dark:bg-success-dark' => $allDone,
                                        'bg-accent dark:bg-accent-dark' => ! $allDone && $day['state'] === 'today',
                                        'bg-black/30' => ! $allDone && $day['state'] !== 'today',
                                    ])>
                                        @if ($allDone)
                                            @svg('heroicon-s-check', 'h-3.5 w-3.5')
                                        @else
                                            {{ $day['number'] }}
                                        @endif
                                    </span>
                                </button>
                            @endif
                        @endforeach
                    </div>
                </div>
            </li>
        @endforeach
    </ul>

    {{-- The day's sheet: its Lesson, Listening and Daily talk, each ticked
         once done and one tap away. --}}
    <template x-teleport="body">
        <div x-show="open" x-cloak x-on:keydown.escape.window="open = false" x-back-close="{ open: open, close: () => open = false }" class="fixed inset-0 z-50">
            <div x-show="open" x-transition.opacity.duration.200ms x-on:click="open = false" class="absolute inset-0 bg-ink/40" aria-hidden="true"></div>
            <div
                x-show="open"
                x-swipe-close="open = false"
                x-transition:enter="transition duration-200 ease-out motion-reduce:duration-0"
                x-transition:enter-start="translate-y-full"
                x-transition:enter-end="translate-y-0"
                x-transition:leave="transition duration-150 ease-in motion-reduce:duration-0"
                x-transition:leave-start="translate-y-0"
                x-transition:leave-end="translate-y-full"
                role="dialog"
                aria-modal="true"
                x-bind:aria-label="sheet?.title"
                class="absolute inset-x-0 bottom-0 mx-auto max-w-md rounded-t-2xl border border-b-0 border-line bg-surface px-3 pt-2 pb-[max(1rem,env(safe-area-inset-bottom))] shadow-2xl dark:border-line-dark dark:bg-surface-dark"
            >
                <div class="mx-auto mb-2 h-1 w-10 rounded-full bg-line dark:bg-line-dark" aria-hidden="true"></div>
                <p class="px-2 pb-2 font-display text-base font-bold text-ink dark:text-ink-dark" x-text="sheet?.title"></p>
                <nav class="space-y-1">
                    @foreach ($parts as $part)
                        @php $urlKey = $part['url'] ?? 'lessonUrl'; @endphp
                        <a
                            x-show="sheet?.{{ $urlKey }}"
                            x-bind:href="sheet?.{{ $urlKey }}"
                            wire:navigate
                            x-on:click="open = false"
                            class="flex items-center gap-3 rounded-xl px-2 py-3 text-sm font-semibold text-ink transition-colors hover:bg-surface-sunken dark:text-ink-dark dark:hover:bg-surface-sunken-dark"
                        >
                            <span
                                class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full"
                                x-bind:class="sheet?.{{ $part['key'] }} ? 'bg-success text-white dark:bg-success-dark' : 'border-2 border-line text-transparent dark:border-line-dark'"
                            >@svg('heroicon-s-check', 'h-3.5 w-3.5')</span>
                            @svg('heroicon-o-'.$part['icon'], 'h-5 w-5 shrink-0 text-ink-faint dark:text-ink-faint-dark')
                            <span class="flex-1">{{ $part['label'] }}</span>
                            @svg('heroicon-o-chevron-right', 'h-4 w-4 shrink-0 text-ink-faint dark:text-ink-faint-dark')
                        </a>
                    @endforeach
                </nav>
            </div>
        </div>
    </template>
</div>
