{{--
    A read-only, no-navigation rendering of a mission's day-by-day path —
    same locked/current/done visual language as the runner's own journey
    path (⚡runner.blade.php), but deliberately without any entry-step
    links: this is what a Friends Board card shows for a friend's CURRENT
    mission (see friends/⚡board.blade.php), and a friend must never be
    able to click into someone else's actual mission run. Built as its
    own small component rather than reusing the runner's inline markup,
    so this addition carries zero risk to the runner's own, more heavily
    tested navigation logic.

    @param list<array{label: string, done: bool, current: bool, completedAt: ?\Illuminate\Support\Carbon}> $dayProgress
--}}
@props(['dayProgress' => []])

@if (count($dayProgress))
    <div {{ $attributes->class(['relative pl-9']) }}>
        <div class="absolute top-3 bottom-3 left-[14px] w-0.5 bg-line dark:bg-line-dark"></div>

        @foreach ($dayProgress as $index => $day)
            <div class="relative mb-3 last:mb-0">
                <div
                    class="absolute top-1 -left-9 flex h-7 w-7 items-center justify-center rounded-full border-2 text-xs font-semibold
                        {{ $day['done']
                            ? 'border-success bg-success text-white dark:border-success-dark dark:bg-success-dark'
                            : ($day['current']
                                ? 'border-accent bg-accent text-white dark:border-accent-dark dark:bg-accent-dark'
                                : 'border-line bg-ground text-ink-faint dark:border-line-dark dark:bg-ground-dark dark:text-ink-faint-dark') }}"
                >
                    @if ($day['done'])
                        @svg('heroicon-o-check', 'h-3.5 w-3.5')
                    @else
                        {{ $index + 1 }}
                    @endif
                </div>
                <p class="text-xs font-semibold text-ink dark:text-ink-dark">Day {{ $index + 1 }} · {{ $day['label'] }}</p>
                @if ($day['done'] && $day['completedAt'])
                    <p class="text-[11px] text-success dark:text-success-dark">Completed {{ $day['completedAt']->format('M j') }}</p>
                @elseif ($day['current'])
                    <p class="text-[11px] text-accent-ink dark:text-accent-ink-dark">In progress</p>
                @endif
            </div>
        @endforeach
    </div>
@endif
