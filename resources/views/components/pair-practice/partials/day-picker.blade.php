{{--
    Latest (auto) or Choose days, for a practice round or a game. Needs an
    Alpine scope with mode, days, toggle() and toggleAll(), and $autoLine,
    $missions (PairPractice::dayChoices()), $keyPrefix.
--}}
<div class="space-y-2">
    <label class="flex cursor-pointer items-center gap-3 rounded-xl border p-3" x-bind:class="mode === 'auto' ? 'border-accent bg-accent-soft/40 dark:border-accent-dark dark:bg-accent-soft-dark/40' : 'border-line dark:border-line-dark'">
        <input type="radio" value="auto" x-model="mode" class="accent-accent">
        <span class="min-w-0">
            <span class="block text-sm font-semibold text-ink dark:text-ink-dark">Latest (auto)</span>
            <span class="block truncate text-xs text-ink-faint dark:text-ink-faint-dark">{{ $autoLine }}</span>
        </span>
    </label>
    <label class="flex cursor-pointer items-center gap-3 rounded-xl border p-3" x-bind:class="mode === 'choose' ? 'border-accent bg-accent-soft/40 dark:border-accent-dark dark:bg-accent-soft-dark/40' : 'border-line dark:border-line-dark'">
        <input type="radio" value="choose" x-model="mode" class="accent-accent">
        <span class="text-sm font-semibold text-ink dark:text-ink-dark">Choose days</span>
    </label>
</div>

<div x-show="mode === 'choose'" x-cloak class="space-y-3">
    @foreach ($missions as $mission)
        @php $sharedKeys = collect($mission['days'])->where('shared', true)->pluck('key')->values()->all(); @endphp
        <div wire:key="mission-{{ $keyPrefix }}-{{ $mission['code'] }}" class="flex flex-wrap items-center gap-2">
            <span class="w-10 shrink-0 text-xs font-bold text-ink-soft dark:text-ink-soft-dark">{{ $mission['code'] }}</span>
            @foreach ($mission['days'] as $missionDay)
                @if ($missionDay['shared'])
                    <button
                        type="button"
                        x-on:click="toggle(@js($missionDay['key']))"
                        x-bind:class="days.includes(@js($missionDay['key'])) ? 'border-accent bg-accent text-white dark:border-accent-dark dark:bg-accent-dark' : 'border-line text-ink-soft dark:border-line-dark dark:text-ink-soft-dark'"
                        class="h-9 min-w-9 cursor-pointer rounded-full border px-3 text-sm font-semibold transition-colors"
                    >{{ $missionDay['day'] }}</button>
                @else
                    <span class="inline-flex h-9 items-center gap-0.5 rounded-full border border-dashed border-line px-2.5 text-sm text-ink-faint dark:border-line-dark dark:text-ink-faint-dark" title="Not finished by both of you yet">{{ $missionDay['day'] }} @svg('heroicon-o-lock-closed', 'h-3 w-3')</span>
                @endif
            @endforeach
            @if (count($sharedKeys) > 1)
                <button type="button" x-on:click="toggleAll(@js($sharedKeys))" class="cursor-pointer px-1 text-xs font-semibold text-accent-ink dark:text-accent-ink-dark">All</button>
            @endif
        </div>
    @endforeach
</div>
