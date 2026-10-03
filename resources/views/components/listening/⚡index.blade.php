<?php

use App\Models\Mission;
use App\Services\ListeningPicks;
use App\Services\ProgramPlanner;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    /**
     * Locked: these decide which day's picks are shown AND which day an
     * "I listened" tick is filed under, so the browser must never be able
     * to rewrite them after mount() has checked them.
     */
    #[Locked]
    public string $missionCode = '';

    #[Locked]
    public int $day = 1;

    /**
     * No arguments = the learner's own current day. A specific day is
     * allowed only if the learner has reached it: days ahead of their
     * program position are sent back to today, same "nothing is skippable"
     * idea as the rest of the app (but never a calendar lock).
     */
    public function mount(?string $requestedMission = null, ?int $requestedDay = null): void
    {
        $picks = app(ListeningPicks::class);
        $current = $picks->dayFor($this->program['today']);

        if ($requestedMission === null) {
            $target = $current ?? ['missionCode' => 'M24', 'dayNumber' => 4];
        } else {
            $target = ['missionCode' => $requestedMission, 'dayNumber' => $requestedDay ?? 1];

            if ($picks->indexOf($target['missionCode'], $target['dayNumber']) > $this->currentIndex) {
                $this->redirectRoute('listening.show', navigate: true);

                return;
            }
        }

        $this->missionCode = $target['missionCode'];
        $this->day = $target['dayNumber'];
    }

    /**
     * The learner's "I listened" tick — counts today toward the streak
     * (see User::activeDates()), filed under the day on screen. The day
     * is re-checked here, not trusted from mount().
     */
    public function markListened(): void
    {
        if (app(ListeningPicks::class)->indexOf($this->missionCode, $this->day) > $this->currentIndex) {
            return;
        }

        auth()->user()->recordListeningToday($this->missionCode, $this->day);

        unset($this->listenedToday);
    }

    #[Computed]
    public function program(): array
    {
        return app(ProgramPlanner::class)->plan(auth()->user());
    }

    /**
     * @return array{missionCode: string, dayNumber: int}|null null once all 24 missions are done
     */
    #[Computed]
    public function currentDay(): ?array
    {
        return app(ListeningPicks::class)->dayFor($this->program['today']);
    }

    /**
     * Program position of the learner's own current day (0-95). Once the
     * whole program is done every day counts as reached.
     */
    #[Computed]
    public function currentIndex(): int
    {
        $current = $this->currentDay;

        return $current === null
            ? ProgramPlanner::TOTAL_DAYS - 1
            : app(ListeningPicks::class)->indexOf($current['missionCode'], $current['dayNumber']);
    }

    #[Computed]
    public function isToday(): bool
    {
        $current = $this->currentDay;

        return $current !== null && $current['missionCode'] === $this->missionCode && $current['dayNumber'] === $this->day;
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function picks(): array
    {
        return app(ListeningPicks::class)->forDay($this->missionCode, $this->day) ?? [];
    }

    /**
     * @return array{title: string, grammar: string}
     */
    #[Computed]
    public function mission(): array
    {
        $catalog = Mission::roadmapCatalog()[$this->missionCode];

        return ['title' => $catalog['title'], 'grammar' => $catalog['grammar']];
    }

    #[Computed]
    public function listenedToday(): bool
    {
        return auth()->user()->hasListenedToday();
    }

    /**
     * The four days of the mission on screen, for the quick day switcher
     * under the title — the fast way back through a finished mission's
     * picks without hunting in the 24-mission path.
     *
     * @return list<array{number: int, state: string, viewing: bool}>
     */
    #[Computed]
    public function missionDays(): array
    {
        return collect($this->path)->firstWhere('code', $this->missionCode)['days'] ?? [];
    }

    /**
     * Every mission with its 4 days, each marked past / today / future
     * against the learner's own position, plus which one is on screen.
     *
     * @return list<array{code: string, title: string, days: list<array{number: int, state: string, viewing: bool}>}>
     */
    #[Computed]
    public function path(): array
    {
        $picks = app(ListeningPicks::class);
        $viewing = $picks->indexOf($this->missionCode, $this->day);
        $hasToday = $this->currentDay !== null;

        return collect(Mission::roadmapCatalog())
            ->map(fn (array $mission, string $code) => [
                'code' => $code,
                'title' => $mission['title'],
                'days' => collect(range(1, 4))->map(function (int $number) use ($picks, $code, $viewing, $hasToday) {
                    $index = $picks->indexOf($code, $number);

                    return [
                        'number' => $number,
                        'state' => match (true) {
                            $hasToday && $index === $this->currentIndex => 'today',
                            $index <= $this->currentIndex => 'past',
                            default => 'future',
                        },
                        'viewing' => $index === $viewing,
                    ];
                })->all(),
            ])
            ->values()
            ->all();
    }
};
?>

<div class="mx-auto max-w-2xl space-y-6 p-6">
    <header class="border-b border-line pb-4 dark:border-line-dark">
        <a href="{{ route('home') }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-semibold text-ink-faint hover:text-ink dark:text-ink-faint-dark dark:hover:text-ink-dark">
            @svg('heroicon-o-chevron-left', 'h-3.5 w-3.5') Missions
        </a>
        <div class="mt-3 flex items-center gap-3">
            <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-accent text-white dark:bg-accent-dark">
                @svg('heroicon-o-speaker-wave', 'h-5 w-5')
            </span>
            <div>
                <h1 class="font-display text-2xl font-extrabold text-ink dark:text-ink-dark">{{ $this->isToday ? 'Listen today' : 'Listening' }}</h1>
                <p class="mt-0.5 text-sm text-ink-soft dark:text-ink-soft-dark">{{ $missionCode }} · {{ $this->mission['title'] }} · Day {{ $day }} of 4</p>
            </div>
        </div>
        <nav class="mt-3 flex flex-wrap items-center gap-1.5" aria-label="Days of {{ $missionCode }}">
            @foreach ($this->missionDays as $dayInfo)
                @php
                    $pill = 'inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold';
                @endphp
                @if ($dayInfo['state'] === 'future')
                    <span
                        class="{{ $pill }} cursor-not-allowed border-dashed border-line text-ink-faint dark:border-line-dark dark:text-ink-faint-dark"
                        title="Not reached yet"
                        aria-label="Day {{ $dayInfo['number'] }}, not reached yet"
                    >Day {{ $dayInfo['number'] }}</span>
                @else
                    <a
                        href="{{ route('listening.show', [$missionCode, $dayInfo['number']]) }}"
                        wire:navigate
                        @if ($dayInfo['viewing']) aria-current="page" @endif
                        @class([
                            $pill,
                            'border-ink bg-ink text-ground dark:border-ink-dark dark:bg-ink-dark dark:text-ground-dark' => $dayInfo['viewing'],
                            'border-accent text-accent-ink dark:border-accent-dark dark:text-accent-ink-dark' => ! $dayInfo['viewing'] && $dayInfo['state'] === 'today',
                            'border-line text-ink-soft hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark' => ! $dayInfo['viewing'] && $dayInfo['state'] !== 'today',
                        ])
                    >Day {{ $dayInfo['number'] }}{{ $dayInfo['state'] === 'today' ? ' · today' : '' }}</a>
                @endif
            @endforeach
        </nav>
        @if (! $this->isToday && $this->currentDay)
            <a href="{{ route('listening.show') }}" wire:navigate class="mt-3 inline-flex items-center gap-1 text-xs font-semibold text-accent-ink underline dark:text-accent-ink-dark">
                Back to today ({{ $this->currentDay['missionCode'] }} · Day {{ $this->currentDay['dayNumber'] }})
            </a>
        @endif
    </header>

    <section class="space-y-1">
        <p class="text-base font-semibold text-ink dark:text-ink-dark">Listen to at least one, or all three if you like.</p>
        <p class="text-sm text-ink-soft dark:text-ink-soft-dark">Pick whichever you prefer. The three go from lighter to more challenging.</p>
        <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Grammar in this mission: <span class="font-semibold text-ink-soft dark:text-ink-soft-dark">{{ $this->mission['grammar'] }}</span></p>
    </section>

    <section class="space-y-3" aria-label="Listening picks">
        @foreach ($this->picks as $level => $pick)
            <article class="card space-y-2.5 p-4" wire:key="pick-{{ $missionCode }}-{{ $day }}-{{ $level }}">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="text-xs font-semibold text-ink-soft dark:text-ink-soft-dark">{{ \App\Services\ListeningPicks::SOURCES[$pick['src']] ?? $pick['src'] }}</span>
                    <span class="inline-flex items-center gap-2 text-xs text-ink-soft dark:text-ink-soft-dark">
                        <span class="inline-flex h-4 items-end gap-0.5" role="img" aria-label="Difficulty: {{ \App\Services\ListeningPicks::LEVELS[$level] }}">
                            @foreach ([2, 3, 4] as $bar => $height)
                                <span @class([
                                    'w-1 rounded-sm',
                                    'h-2' => $height === 2,
                                    'h-3' => $height === 3,
                                    'h-4' => $height === 4,
                                    'bg-accent dark:bg-accent-dark' => $bar <= $level,
                                    'bg-line dark:bg-line-dark' => $bar > $level,
                                ])></span>
                            @endforeach
                        </span>
                        {{ \App\Services\ListeningPicks::LEVELS[$level] }}
                    </span>
                </div>

                <h2 class="text-lg leading-snug font-bold text-ink dark:text-ink-dark">{{ $pick['title'] }}</h2>
                @if ($pick['by'])
                    <p class="-mt-1.5 text-sm text-ink-soft dark:text-ink-soft-dark">{{ $pick['by'] }}</p>
                @endif

                <div class="flex flex-wrap gap-1.5">
                    @if ($pick['min'])
                        <span class="rounded-full bg-surface-sunken px-2.5 py-0.5 text-xs text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark">{{ $pick['min'] }} min</span>
                    @endif
                    @if ($pick['grammar'])
                        <span class="rounded-full bg-accent-soft px-2.5 py-0.5 text-xs font-semibold text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">Grammar of the day</span>
                    @endif
                    @if ($pick['tx'] === 'page')
                        <span class="rounded-full bg-success-soft px-2.5 py-0.5 text-xs text-success dark:bg-success-soft-dark dark:text-success-dark">Full transcript</span>
                    @endif
                    @if ($pick['src'] === 'teded')
                        <span class="rounded-full bg-surface-sunken px-2.5 py-0.5 text-xs text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark">Video</span>
                    @endif
                    @if ($pick['apple'])
                        <span class="rounded-full bg-surface-sunken px-2.5 py-0.5 text-xs text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark">Apple Podcasts · audio only</span>
                    @endif
                </div>

                <div class="flex flex-wrap items-center gap-x-4 gap-y-2 pt-0.5">
                    <a
                        href="{{ $pick['url'] }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-full bg-accent px-5 py-2 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
                    >Listen @svg('heroicon-o-arrow-top-right-on-square', 'h-4 w-4')</a>
                    @if ($pick['tx'] === 'link' && $pick['txUrl'])
                        <a href="{{ $pick['txUrl'] }}" target="_blank" rel="noopener noreferrer" class="text-sm font-semibold text-accent-ink underline dark:text-accent-ink-dark">Transcript</a>
                    @endif
                </div>
            </article>
        @endforeach
    </section>

    <section class="flex flex-wrap items-center gap-3 rounded-xl px-1">
        @if ($this->listenedToday)
            <span class="inline-flex items-center gap-1 rounded-full bg-success-soft px-4 py-2 text-sm font-semibold text-success dark:bg-success-soft-dark dark:text-success-dark">
                @svg('heroicon-s-check', 'h-4 w-4') Listened today
            </span>
        @else
            <button
                type="button"
                wire:click="markListened"
                wire:loading.attr="disabled"
                class="inline-flex cursor-pointer items-center gap-1 rounded-full border border-line px-5 py-2 text-sm font-semibold text-ink transition-colors hover:bg-surface-sunken disabled:opacity-60 dark:border-line-dark dark:text-ink-dark dark:hover:bg-surface-sunken-dark"
            >I listened @svg('heroicon-s-check', 'h-4 w-4')</button>
            <span class="text-xs text-ink-faint dark:text-ink-faint-dark">Counts toward your streak</span>
        @endif
    </section>

    <p class="border-t border-line pt-4 text-sm text-ink-soft dark:border-line-dark dark:text-ink-soft-dark">
        Don't stop for every unfamiliar word. If you need help, turn on English subtitles only.
    </p>

    <details class="card" @if (! $this->isToday) open @endif>
        <summary class="cursor-pointer list-none space-y-2 p-4">
            <div class="flex items-center justify-between gap-2 text-sm font-bold text-ink dark:text-ink-dark">
                <span>96-day path</span>
                <span class="text-xs font-semibold text-ink-soft dark:text-ink-soft-dark">Day {{ $this->currentIndex + 1 }} of {{ \App\Services\ProgramPlanner::TOTAL_DAYS }}</span>
            </div>
            <x-progress-bar>
                <div
                    class="h-full rounded-full bg-accent transition-all duration-300 dark:bg-accent-dark"
                    style="width: {{ ($this->currentIndex + 1) / \App\Services\ProgramPlanner::TOTAL_DAYS * 100 }}%"
                ></div>
            </x-progress-bar>
        </summary>
        <div class="grid grid-cols-2 gap-2.5 px-4 pb-4 sm:grid-cols-3">
            @foreach ($this->path as $mission)
                <div class="card-sunken space-y-1.5 p-2.5" wire:key="path-{{ $mission['code'] }}">
                    <p class="flex items-baseline gap-1.5 text-xs">
                        <span class="font-bold text-ink dark:text-ink-dark">{{ $mission['code'] }}</span>
                        <span class="truncate text-ink-soft dark:text-ink-soft-dark">{{ $mission['title'] }}</span>
                    </p>
                    <div class="flex gap-1.5">
                        @foreach ($mission['days'] as $dayInfo)
                            @php
                                $dayClasses = [
                                    'inline-flex h-7 w-7 items-center justify-center rounded-full border text-xs font-semibold',
                                    'ring-2 ring-ink ring-offset-2 ring-offset-surface-sunken dark:ring-ink-dark dark:ring-offset-surface-sunken-dark' => $dayInfo['viewing'],
                                ];
                            @endphp
                            @if ($dayInfo['state'] === 'future')
                                <span
                                    @class([...$dayClasses, 'cursor-not-allowed border-dashed border-line text-ink-faint dark:border-line-dark dark:text-ink-faint-dark'])
                                    title="Not reached yet"
                                    aria-label="{{ $mission['code'] }} day {{ $dayInfo['number'] }}, not reached yet"
                                >{{ $dayInfo['number'] }}</span>
                            @else
                                <a
                                    href="{{ route('listening.show', [$mission['code'], $dayInfo['number']]) }}"
                                    wire:navigate
                                    aria-label="{{ $mission['code'] }} day {{ $dayInfo['number'] }}{{ $dayInfo['state'] === 'today' ? ', today' : '' }}"
                                    @class([
                                        ...$dayClasses,
                                        'border-accent bg-accent text-white dark:border-accent-dark dark:bg-accent-dark' => $dayInfo['state'] === 'today',
                                        'border-transparent bg-success-soft text-success dark:bg-success-soft-dark dark:text-success-dark' => $dayInfo['state'] === 'past',
                                    ])
                                >{{ $dayInfo['number'] }}</a>
                            @endif
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </details>
</div>
