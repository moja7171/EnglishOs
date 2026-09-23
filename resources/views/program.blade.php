<x-layouts.app>
    @php
        $program = app(\App\Services\ProgramPlanner::class)->plan(auth()->user());
    @endphp

    <div class="mx-auto max-w-2xl space-y-6 p-6">
        <header class="border-b border-line pb-4 dark:border-line-dark">
            <a href="{{ route('home') }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-semibold text-ink-faint hover:text-ink dark:text-ink-faint-dark dark:hover:text-ink-dark">@svg('heroicon-o-chevron-left', 'h-3.5 w-3.5') Missions</a>
            <h1 class="mt-2 font-display text-2xl font-extrabold text-ink dark:text-ink-dark">The 100-day program</h1>
            <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">24 missions, 4 days each. About 40 minutes a day. That's the whole plan.</p>
        </header>

        <section class="rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
            <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Where you are</p>
            <p class="mt-1 text-sm text-ink dark:text-ink-dark">
                Day <strong>{{ $program['programDay'] }}</strong> of {{ $program['totalDays'] }} · Mission <strong>{{ $program['missionNumber'] }}</strong> of {{ $program['totalMissions'] }}
                @if ($program['started'])
                    · {{ $program['calendarDay'] }} {{ Str::plural('day', $program['calendarDay']) }} since you started
                @endif
            </p>
        </section>

        <section class="space-y-3">
            <h2 class="font-display text-lg font-bold text-ink dark:text-ink-dark">One mission = four days</h2>
            <ol class="space-y-2">
                @foreach ([
                    ['Day 1', 'Get Ready, New Words, Listening', '~40 min'],
                    ['Day 2', 'New Words, Listen Again, Grammar Time, Picture Story, Video Shadowing, Picture Description', '~55 min'],
                    ['Day 3', 'New Words, Listen Again, Talk It Out, Reading, Writing', '~65 min'],
                    ['Day 4', 'Listen Again, My Fixes, Final Talk, Mission Result', '~35 min'],
                ] as [$day, $what, $time])
                    <li class="flex gap-3 rounded-2xl border border-line bg-surface p-3.5 dark:border-line-dark dark:bg-surface-dark">
                        <span class="w-12 shrink-0 text-xs font-bold text-accent-ink dark:text-accent-ink-dark">{{ $day }}</span>
                        <span class="flex-1">
                            <span class="block text-sm font-semibold text-ink dark:text-ink-dark">{{ $time }}</span>
                            <span class="block text-xs text-ink-soft dark:text-ink-soft-dark">{{ $what }}</span>
                        </span>
                    </li>
                @endforeach
            </ol>
            <p class="text-xs text-ink-faint dark:text-ink-faint-dark">The Missions page always shows exactly which of these is <em>today</em> — you never have to work it out yourself.</p>
        </section>

        <section class="space-y-2">
            <h2 class="font-display text-lg font-bold text-ink dark:text-ink-dark">"On track" is about progress, not the calendar</h2>
            <p class="text-sm text-ink-soft dark:text-ink-soft-dark">Nothing here is locked to a date. Your <strong>program day</strong> is where you actually are in the 100 (mission days done + today). Your <strong>calendar day</strong> is how many days have passed since you started. If the second gets ahead of the first, the Missions page says "N days behind" — a nudge to catch up, never a penalty. One missed day never breaks your streak either.</p>
        </section>

        <section class="space-y-2">
            <h2 class="font-display text-lg font-bold text-ink dark:text-ink-dark">Three habits that make it work</h2>
            <ul class="list-disc space-y-1 pl-5 text-sm text-ink-soft dark:text-ink-soft-dark">
                <li><strong class="text-ink dark:text-ink-dark">Same time every day.</strong> Forty minutes at a fixed hour beats two hours on a random Sunday.</li>
                <li><strong class="text-ink dark:text-ink-dark">Speak out loud, always.</strong> Every recording step — your mouth has to do it, not your eyes.</li>
                <li><strong class="text-ink dark:text-ink-dark">Do Daily Review before new material.</strong> Ten minutes of review on a short day is worth more than a new step.</li>
            </ul>
        </section>

        <a href="{{ route('home') }}" wire:navigate class="inline-flex cursor-pointer items-center gap-1 rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark">See today's plan @svg('heroicon-o-chevron-right', 'h-3.5 w-3.5')</a>
    </div>
</x-layouts.app>
