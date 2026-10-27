{{-- Day 1 → today: the comfort score from Get Ready against today's, and
     Day 1's recording next to Final Talk's. --}}
<div class="flex flex-col gap-4">
    <p class="text-2xl leading-tight font-extrabold text-ink dark:text-ink-dark">Day 1 → today</p>

    @if ($briefScore && $afterScore)
        <div class="flex items-center justify-center gap-4 text-4xl">
            <span>{{ $comfortChoices[$briefScore][0] ?? '' }}</span>
            <span class="rounded-full bg-success-soft px-3 py-1 text-sm font-extrabold text-success dark:bg-success-soft-dark dark:text-success-dark">{{ $briefScore }} → {{ $afterScore }}</span>
            <span>{{ $comfortChoices[$afterScore][0] ?? '' }}</span>
        </div>
    @endif

    @if ($warmUpRecordingUrl)
        <div class="flex flex-col gap-1.5">
            <p class="text-xs font-extrabold text-ink-faint uppercase dark:text-ink-faint-dark">Day 1</p>
            <x-audio-player :url="$warmUpRecordingUrl" />
        </div>
    @endif
    @if ($finalTalkRecordingUrl)
        <div class="flex flex-col gap-1.5">
            <p class="text-xs font-extrabold text-ink-faint uppercase dark:text-ink-faint-dark">Today</p>
            <x-audio-player :url="$finalTalkRecordingUrl" />
        </div>
    @endif

    <div class="flex flex-col gap-1.5">
        @foreach ($scores as $skill => $pair)
            @if ($pair['after'])
                <div class="flex items-center justify-between rounded-xl bg-surface px-3 py-2 text-sm font-bold text-ink dark:bg-surface-dark dark:text-ink-dark">
                    <span>{{ $skill }}</span>
                    <span class="tabular-nums">
                        @if ($pair['before'])<span class="text-ink-faint dark:text-ink-faint-dark">{{ $pair['before'] }} →</span>@endif
                        {{ $pair['after'] }}/5
                    </span>
                </div>
            @endif
        @endforeach
    </div>
</div>
