{{--
    A GitHub-contribution-style activity grid — extracted from the
    Progress page (progress/⚡index.blade.php) so the exact same visual
    can also show a FRIEND's activity on the Friends Board (see
    friends/⚡board.blade.php), fed by the same generic
    User::activityCalendar(). Content-free by design: shows only whether
    a day had activity, never what was done.

    @param list<array{date: string, label: string, active: bool, future: bool}> $calendar
    @param string $caption
--}}
@props(['calendar' => [], 'caption' => 'Last 12 weeks'])

<div {{ $attributes }}>
    <p class="text-xs text-ink-faint dark:text-ink-faint-dark">{{ $caption }}</p>
    <div class="mt-2 overflow-x-auto">
        <div class="grid w-fit grid-flow-col grid-rows-7 gap-1">
            @foreach ($calendar as $day)
                <span
                    title="{{ $day['label'] }}{{ $day['active'] ? ' — practiced' : '' }}"
                    class="h-3 w-3 rounded-sm {{ $day['future']
                        ? 'bg-transparent'
                        : ($day['active']
                            ? 'bg-accent dark:bg-accent-dark'
                            : 'bg-surface-sunken dark:bg-surface-sunken-dark') }}"
                ></span>
            @endforeach
        </div>
    </div>
    <div class="mt-2 flex items-center gap-1.5 text-[11px] text-ink-faint dark:text-ink-faint-dark">
        <span class="h-2.5 w-2.5 shrink-0 rounded-sm bg-surface-sunken dark:bg-surface-sunken-dark"></span>
        No activity
        <span class="ml-2 h-2.5 w-2.5 shrink-0 rounded-sm bg-accent dark:bg-accent-dark"></span>
        Practiced
    </div>
</div>
