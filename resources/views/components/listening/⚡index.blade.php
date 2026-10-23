<?php

use App\Models\AudioListen;
use App\Models\Mission;
use App\Services\ListeningPicks;
use App\Services\ProgramPlanner;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
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
     * is re-checked here, not trusted from mount(). Also what a pick's
     * self-reported "I listened to this one" (listen-counter) counts as.
     */
    #[On('listen-self-reported')]
    public function markListened(): void
    {
        if (app(ListeningPicks::class)->indexOf($this->missionCode, $this->day) > $this->currentIndex) {
            return;
        }

        auth()->user()->recordListeningToday($this->missionCode, $this->day);

        unset($this->listenedToday, $this->listenedDaysByMission, $this->listenedDays, $this->missionDays);
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

    /**
     * The synced text of every in-app pick of the day, by its level —
     * read once per render instead of from inside the markup.
     *
     * @return array<int, list<array{text: string, start: float, end: float, speaker?: string}>>
     */
    #[Computed]
    public function localSegments(): array
    {
        $picks = app(ListeningPicks::class);

        return collect($this->picks)
            ->filter(fn (array $pick) => $pick['local'] !== null)
            ->map(fn (array $pick) => $picks->segmentsFor($this->missionCode, $pick['local']['slug']))
            ->all();
    }

    #[Computed]
    public function listenedToday(): bool
    {
        return auth()->user()->hasListenedToday();
    }

    /**
     * The days with at least one pick heard, by mission code.
     *
     * @return array<string, list<int>>
     */
    #[Computed]
    public function listenedDaysByMission(): array
    {
        return auth()->user()->listenedDaysByMission();
    }

    /**
     * The days of the mission on screen with at least one pick heard.
     *
     * @return list<int>
     */
    #[Computed]
    public function listenedDays(): array
    {
        return $this->listenedDaysByMission[$this->missionCode] ?? [];
    }

    /**
     * The four days of the mission on screen, for the quick day switcher
     * under the title, each marked past / today / future against the
     * learner's own position, plus which one is on screen and whether it
     * was listened to. Another mission is one tap away in the picker.
     *
     * @return list<array{number: int, state: string, viewing: bool, done: bool}>
     */
    #[Computed]
    public function missionDays(): array
    {
        $picks = app(ListeningPicks::class);
        $viewing = $picks->indexOf($this->missionCode, $this->day);
        $hasToday = $this->currentDay !== null;

        return collect(range(1, 4))
            ->map(function (int $number) use ($picks, $viewing, $hasToday) {
                $index = $picks->indexOf($this->missionCode, $number);

                return [
                    'number' => $number,
                    'state' => match (true) {
                        $hasToday && $index === $this->currentIndex => 'today',
                        $index <= $this->currentIndex => 'past',
                        default => 'future',
                    },
                    'viewing' => $index === $viewing,
                    'done' => in_array($number, $this->listenedDays, true),
                ];
            })
            ->all();
    }
};
?>

<div class="mx-auto max-w-2xl space-y-6 p-6">
    <header class="border-b border-line pb-4 dark:border-line-dark">
        <a href="{{ route('home') }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-semibold text-ink-faint hover:text-ink dark:text-ink-faint-dark dark:hover:text-ink-dark">
            @svg('heroicon-o-chevron-left', 'h-3.5 w-3.5') Home
        </a>
        <div class="mt-3 flex items-center gap-3">
            <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-accent text-white dark:bg-accent-dark">
                @svg('heroicon-o-speaker-wave', 'h-5 w-5')
            </span>
            <div class="min-w-0 flex-1">
                <h1 class="font-display text-2xl font-extrabold text-ink dark:text-ink-dark">Listening</h1>
                <p class="mt-0.5 text-sm text-ink-soft dark:text-ink-soft-dark">{{ $missionCode }} · {{ $this->mission['title'] }} · Day {{ $day }} of 4</p>
            </div>
            <x-missions-button for="listening" />
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
                    >
                        Day {{ $dayInfo['number'] }}{{ $dayInfo['state'] === 'today' ? ' · today' : '' }}
                        @if ($dayInfo['done'])
                            @svg('heroicon-s-check-circle', 'ms-1 h-3.5 w-3.5 text-success dark:text-success-dark')
                            <span class="sr-only">, done</span>
                        @endif
                    </a>
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
        <p class="text-base font-semibold text-ink dark:text-ink-dark">Pick one — that's enough.</p>
        <p class="text-sm text-ink-soft dark:text-ink-soft-dark">Lighter to more challenging.</p>
        <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Grammar in this mission: <span class="font-semibold text-ink-soft dark:text-ink-soft-dark">{{ $this->mission['grammar'] }}</span></p>
    </section>

    {{-- The one-tap tick is today's (it is what the streak counts). An
         earlier day shows only its own state: the picks' own counters
         below are what file a listen under it. --}}
    @if ($this->isToday)
        <x-done-toggle :done="$this->listenedToday" action="markListened" label="I listened" done-label="Listened today" />
    @elseif (in_array($day, $this->listenedDays, true))
        <x-done-toggle :done="true" action="markListened" label="I listened" done-label="Listened" />
    @endif

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
                    @if ($pick['local'])
                        <span class="rounded-full bg-success-soft px-2.5 py-0.5 text-xs text-success dark:bg-success-soft-dark dark:text-success-dark">Plays here · text follows the audio</span>
                    @elseif ($pick['tx'] === 'page')
                        <span class="rounded-full bg-success-soft px-2.5 py-0.5 text-xs text-success dark:bg-success-soft-dark dark:text-success-dark">Full transcript</span>
                    @endif
                    @if ($pick['src'] === 'teded')
                        <span class="rounded-full bg-surface-sunken px-2.5 py-0.5 text-xs text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark">Video</span>
                    @endif
                    @if ($pick['apple'])
                        <span class="rounded-full bg-surface-sunken px-2.5 py-0.5 text-xs text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark">Apple Podcasts · audio only</span>
                    @endif
                </div>

                @php
                    $pickKey = AudioListen::pickKey($day, $level);
                @endphp

                {{-- Every pick keeps its own count: heard here for an in-app
                     pick, told by the learner for one that plays elsewhere. --}}
                <livewire:listen-counter
                    :mission-code="$missionCode"
                    :source="AudioListen::SOURCE_LISTENING_PICK"
                    :day-number="$day"
                    :pick-key="$pickKey"
                    :self-reportable="! $pick['local']"
                    :key="'pick-counter-'.$missionCode.'-'.$pickKey"
                />

                <div x-data="{ open: false, loaded: false }" class="space-y-3">
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 pt-0.5">
                        @if ($pick['local'])
                            <button
                                type="button"
                                x-on:click="open = ! open; loaded = true"
                                x-bind:aria-expanded="open"
                                class="inline-flex cursor-pointer items-center gap-1.5 rounded-full bg-accent px-5 py-2 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
                            >
                                @svg('heroicon-s-play', 'h-4 w-4')
                                <span x-text="open ? 'Hide player' : 'Listen here'">Listen here</span>
                            </button>
                            <a href="{{ $pick['url'] }}" target="_blank" rel="noopener noreferrer" class="text-sm font-semibold text-accent-ink underline dark:text-accent-ink-dark">Original page</a>
                        @else
                            <a
                                href="{{ $pick['url'] }}"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex cursor-pointer items-center gap-1.5 rounded-full bg-accent px-5 py-2 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
                            >Listen @svg('heroicon-o-arrow-top-right-on-square', 'h-4 w-4')</a>
                        @endif
                        @if ($pick['tx'] === 'link' && $pick['txUrl'])
                            <a href="{{ $pick['txUrl'] }}" target="_blank" rel="noopener noreferrer" class="text-sm font-semibold text-accent-ink underline dark:text-accent-ink-dark">Transcript</a>
                        @endif
                    </div>

                    @if ($pick['local'])
                        {{-- Built on the first click, so the page doesn't start downloading
                             three audio files nobody asked for; hidden, not destroyed, after. --}}
                        <div wire:ignore>
                            <template x-if="loaded">
                                <div x-show="open" x-cloak>
                                    <x-audio-player
                                        :url="$pick['local']['audioUrl']"
                                        :segments="$this->localSegments[$level] ?? []"
                                        :listen="['mission_code' => $missionCode, 'source' => AudioListen::SOURCE_LISTENING_PICK, 'day_number' => $day, 'pick_key' => $pickKey, 'chip' => false]"
                                        onListened="$wire.markListened()"
                                        :resume-key="'eos-resume:'.$missionCode.':'.$pickKey"
                                    />
                                </div>
                            </template>
                        </div>
                    @endif
                </div>
            </article>
        @endforeach
    </section>

    <p class="border-t border-line pt-4 text-sm text-ink-soft dark:border-line-dark dark:text-ink-soft-dark">
        Don't stop for every unfamiliar word. If you need help, turn on English subtitles only.
    </p>
</div>
