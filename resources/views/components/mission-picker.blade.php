@props(['missions', 'missionCode', 'day', 'routeName' => null, 'reachedDay', 'triggerClass' => 'mt-0.5 inline-flex cursor-pointer items-center gap-1 text-start text-sm text-ink-soft transition-colors hover:text-ink dark:text-ink-soft-dark dark:hover:text-ink-dark'])

{{-- The line under a practice page's title (/listening, /pi) — "M02 ·
     People & Relationships · Day 1 of 4" — doubling as the way to any day
     of any mission: a tap opens the map of all 24 missions in path order
     (see ListeningPicks::missionMap()), a bottom sheet on a phone, scrolled
     to the learner's current mission. Each mission is a row of four day
     circles, ticked by the page's own practice; a day not reached yet is
     dashed, and a mission not reached yet is faded with a lock. The day
     the page opens on by itself links to the page's bare URL. A page whose
     days don't open at a "{code}/{day}" route (home) gives each reached
     day its own url instead, null for one that doesn't open.

     @param list<array{code: string, title: string, reached: bool, current: bool, days: list<array{number: int, state: string, done: bool, url?: ?string}>}> $missions
     @param int $day the day on screen
     @param string|null $routeName listening.show or pi.practice; null when every day carries its url
     @param string $triggerClass the classes of the line that opens the map
     @param int $reachedDay the furthest day reached, 1-96 --}}
<div
    class="relative"
    x-data="{
        open: false,
        // Brings the current mission's row to the middle of whichever box
        // scrolls: the whole sheet on a phone, the list in the dropdown.
        scrollToCurrent() {
            const row = this.$refs.current;
            for (let box = row?.parentElement; box; box = box.parentElement) {
                if (box.scrollHeight > box.clientHeight) {
                    box.scrollTop += row.getBoundingClientRect().top - box.getBoundingClientRect().top - (box.clientHeight - row.offsetHeight) / 2;

                    return;
                }
            }
        },
    }"
    x-on:click.outside="open = false"
    x-on:keydown.escape.window="open = false"
>
    <button
        type="button"
        x-on:click="open = ! open; if (open) { $nextTick(() => scrollToCurrent()) }"
        aria-haspopup="menu"
        x-bind:aria-expanded="open"
        class="{{ $triggerClass }}"
    >
        <span>{{ $slot }}</span>
        @svg('heroicon-o-chevron-down', 'h-4 w-4 shrink-0')
    </button>

    <x-menu-panel role="menu" aria-label="Missions" width="sm:w-96">
        <div class="px-4 pt-2 pb-3">
            <p class="text-sm font-semibold text-ink dark:text-ink-dark">Day {{ $reachedDay }} of 96</p>
            <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-surface-sunken dark:bg-surface-sunken-dark">
                <div class="h-full rounded-full bg-accent dark:bg-accent-dark" style="width: {{ round($reachedDay / 96 * 100, 1) }}%"></div>
            </div>
        </div>
        <div class="border-t border-line pb-2 sm:max-h-96 sm:overflow-y-auto dark:border-line-dark">
            @foreach ($missions as $mission)
                <div
                    @if ($mission['current']) x-ref="current" @endif
                    @class([
                        'flex items-center gap-3 px-4 py-2',
                        'bg-surface-sunken dark:bg-surface-sunken-dark' => $mission['code'] === $missionCode,
                        'opacity-50' => ! $mission['reached'],
                    ])
                >
                    <span class="w-8 shrink-0 text-xs font-bold text-ink-faint dark:text-ink-faint-dark">{{ $mission['code'] }}</span>
                    <span @class([
                        'min-w-0 flex-1 truncate text-sm',
                        'font-semibold text-ink dark:text-ink-dark' => $mission['code'] === $missionCode,
                        'text-ink-soft dark:text-ink-soft-dark' => $mission['code'] !== $missionCode,
                    ])>{{ $mission['title'] }}</span>
                    @unless ($mission['reached'])
                        @svg('heroicon-s-lock-closed', 'h-3.5 w-3.5 shrink-0 text-ink-faint dark:text-ink-faint-dark')
                    @endunless
                    <span class="flex shrink-0 gap-1.5">
                        @foreach ($mission['days'] as $missionDay)
                            @php
                                $circle = 'inline-flex h-7 w-7 items-center justify-center rounded-full border-2 text-xs font-semibold';
                                $label = "{$mission['code']} · Day {$missionDay['number']}";
                                $viewing = $mission['code'] === $missionCode && $missionDay['number'] === $day;
                                $href = match (true) {
                                    $missionDay['state'] === 'future' => null,
                                    array_key_exists('url', $missionDay) => $missionDay['url'],
                                    $missionDay['state'] === 'today' => route($routeName),
                                    default => route($routeName, [$mission['code'], $missionDay['number']]),
                                };
                            @endphp
                            @if ($href === null)
                                <span
                                    class="{{ $circle }} border-dashed border-line text-ink-faint dark:border-line-dark dark:text-ink-faint-dark"
                                    aria-label="{{ $label }}, not reached yet"
                                >{{ $missionDay['number'] }}</span>
                            @else
                                <a
                                    href="{{ $href }}"
                                    wire:navigate
                                    role="menuitem"
                                    x-on:click="open = false"
                                    aria-label="{{ $label }}{{ $missionDay['state'] === 'today' ? ', today' : '' }}{{ $missionDay['done'] ? ', done' : '' }}"
                                    @if ($viewing) aria-current="page" @endif
                                    @class([
                                        $circle,
                                        'transition-transform hover:scale-110 motion-reduce:transition-none',
                                        'ring-2 ring-ink ring-offset-2 ring-offset-surface dark:ring-ink-dark dark:ring-offset-surface-dark' => $viewing,
                                        'border-success bg-success text-white dark:border-success-dark dark:bg-success-dark' => $missionDay['done'],
                                        'border-accent bg-accent text-white dark:border-accent-dark dark:bg-accent-dark' => ! $missionDay['done'] && $missionDay['state'] === 'today',
                                        'border-line text-ink-soft dark:border-line-dark dark:text-ink-soft-dark' => ! $missionDay['done'] && $missionDay['state'] === 'past',
                                    ])
                                >
                                    @if ($missionDay['done'])
                                        @svg('heroicon-s-check', 'h-4 w-4')
                                    @else
                                        {{ $missionDay['number'] }}
                                    @endif
                                </a>
                            @endif
                        @endforeach
                    </span>
                </div>
            @endforeach
        </div>
    </x-menu-panel>
</div>
