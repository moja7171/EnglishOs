@props(['listening'])

{{-- The daily listening picks, shown inside the Today box. Deliberately
     NOT styled like the mission steps (no check circles, no numbers, no
     done/current states): it's an anytime, any-order habit that never
     gates Continue. The "I listened" tick lives only on /listening; here
     the box just reflects it. --}}
<div class="mt-3 rounded-xl bg-surface-sunken p-3 dark:bg-surface-sunken-dark" aria-label="Daily listening">
    <div class="flex items-center justify-between gap-2">
        <p class="flex items-center gap-1.5 text-xs font-bold text-ink dark:text-ink-dark">
            @svg('heroicon-o-speaker-wave', 'h-4 w-4 text-accent-ink dark:text-accent-ink-dark')
            Listen <span class="font-medium text-ink-soft dark:text-ink-soft-dark">· any time, any order</span>
        </p>
        @if ($listening['listenedToday'])
            <span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-success-soft px-2 py-0.5 text-[11px] font-semibold text-success dark:bg-success-soft-dark dark:text-success-dark">
                @svg('heroicon-s-check', 'h-3 w-3') Listened today
            </span>
        @endif
    </div>

    <ul class="mt-1.5 space-y-0.5">
        @foreach ($listening['picks'] as $level => $pick)
            <li wire:key="listen-pick-{{ $listening['missionCode'] }}-{{ $listening['dayNumber'] }}-{{ $level }}">
                <a
                    href="{{ $pick['url'] }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="flex items-center gap-2.5 rounded-lg px-1.5 py-1.5 transition-colors hover:bg-surface dark:hover:bg-surface-dark"
                >
                    <span class="inline-flex h-3.5 shrink-0 items-end gap-0.5" role="img" aria-label="Difficulty: {{ \App\Services\ListeningPicks::LEVELS[$level] }}">
                        @foreach ([2, 3, 4] as $bar => $height)
                            <span @class([
                                'w-1 rounded-sm',
                                'h-1.5' => $height === 2,
                                'h-2.5' => $height === 3,
                                'h-3.5' => $height === 4,
                                'bg-accent dark:bg-accent-dark' => $bar <= $level,
                                'bg-line dark:bg-line-dark' => $bar > $level,
                            ])></span>
                        @endforeach
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold text-ink dark:text-ink-dark">{{ $pick['title'] }}</span>
                        <span class="block truncate text-xs text-ink-faint dark:text-ink-faint-dark">{{ \Illuminate\Support\Str::afterLast(\App\Services\ListeningPicks::SOURCES[$pick['src']] ?? $pick['src'], '· ') }}@if ($pick['min']) · {{ $pick['min'] }} min @endif</span>
                    </span>
                    @svg('heroicon-o-arrow-top-right-on-square', 'h-3.5 w-3.5 shrink-0 text-ink-faint dark:text-ink-faint-dark')
                </a>
            </li>
        @endforeach
    </ul>

    <div class="mt-1.5 flex items-center justify-between gap-3 px-1.5">
        <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Pick one, or all three. Not a step, so no need to finish it first.</p>
        <a
            href="{{ route('listening.show') }}"
            wire:navigate
            class="shrink-0 text-xs font-semibold text-accent-ink underline dark:text-accent-ink-dark"
        >{{ $listening['listenedToday'] ? 'Details' : 'Details & tick' }} ›</a>
    </div>
</div>
