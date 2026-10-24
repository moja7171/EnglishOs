<?php

use App\Models\AudioListen;
use App\Models\Mission;
use App\Services\ListeningPicks;
use App\Services\ProgramPlanner;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    /**
     * Locked: these decide which day's picks are shown AND which day a
     * listen is filed under, so the browser must never be able to rewrite
     * them after mount() has checked them.
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
     * A pick really heard: the player calls this once at least 90% of it
     * has genuinely played (seeking ahead doesn't count — see
     * eosListenTracker). There is no manual tick: one real listen to any
     * of the three picks is the day's listening. It is filed as that
     * pick's listen and as today's listening (what the streak counts, see
     * User::activeDates()), under the day on screen, which is re-checked
     * here, not trusted from mount(). A second call within a minute is the
     * same listen, not another.
     */
    public function heardPick(int $level): void
    {
        if (! array_key_exists($level, $this->picks)
            || app(ListeningPicks::class)->indexOf($this->missionCode, $this->day) > $this->currentIndex) {
            return;
        }

        $learner = auth()->user();
        $pickKey = AudioListen::pickKey($this->day, $level);
        $justHeard = $learner->audioListens()
            ->where('mission_code', $this->missionCode)
            ->where('source', AudioListen::SOURCE_LISTENING_PICK)
            ->where('pick_key', $pickKey)
            ->where('created_at', '>', now()->subMinute())
            ->exists();

        if (! $justHeard) {
            $learner->audioListens()->create([
                'mission_code' => $this->missionCode,
                'source' => AudioListen::SOURCE_LISTENING_PICK,
                'day_number' => $this->day,
                'pick_key' => $pickKey,
                'self_reported' => false,
            ]);
        }

        $learner->recordListeningToday($this->missionCode, $this->day);

        unset($this->listenedToday, $this->listenedDaysByMission, $this->listenedDays, $this->missionDays, $this->heardLevels);
    }

    /**
     * The levels (0-2) of the day's picks heard at least once, for the
     * "Heard" mark on their cards.
     *
     * @return list<int>
     */
    #[Computed]
    public function heardLevels(): array
    {
        $heardKeys = auth()->user()->audioListens()
            ->where('mission_code', $this->missionCode)
            ->where('source', AudioListen::SOURCE_LISTENING_PICK)
            ->whereIn('pick_key', collect(array_keys($this->picks))->map(fn (int $level) => AudioListen::pickKey($this->day, $level)))
            ->pluck('pick_key')
            ->all();

        return collect(array_keys($this->picks))
            ->filter(fn (int $level) => in_array(AudioListen::pickKey($this->day, $level), $heardKeys, true))
            ->values()
            ->all();
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
     * was listened to. Another mission is the Missions page.
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

@php
    $listenedThisDay = $this->isToday ? $this->listenedToday : in_array($day, $this->listenedDays, true);
@endphp
<div class="mx-auto max-w-2xl space-y-5 p-6">
    {{-- The mission's photo banner: the way back to this day's sheet on
         the Missions page, the four days, and whether this day's listening
         is done. --}}
    <x-mission-banner
        :mission-code="$missionCode"
        title="Listening"
        icon="heroicon-o-speaker-wave"
        :back-href="route('missions.index', ['day' => $missionCode.'-'.$day])"
        :back-label="$missionCode.' · '.$this->mission['title'].' · Day '.$day"
        :done-label="$listenedThisDay ? 'Listened' : null"
    >
        <x-mission-day-pills :mission-code="$missionCode" route-name="listening.show" :days="$this->missionDays" />
    </x-mission-banner>

    @if (! $this->isToday && $this->currentDay)
        <a href="{{ route('listening.show') }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-semibold text-accent-ink underline dark:text-accent-ink-dark">
            Back to today ({{ $this->currentDay['missionCode'] }} · Day {{ $this->currentDay['dayNumber'] }})
        </a>
    @endif

    <p class="text-base font-semibold text-ink dark:text-ink-dark">Pick one — one is enough.</p>

    {{-- One card per pick: level, title, source and length, a star on the
         one with the day's grammar, and a round play button that opens the
         player and its synced text inside the card — one card at a time.
         The day ticks itself once a pick has really been heard (90% played
         through, see heardPick()); there is no manual tick. --}}
    <section x-data="{ playing: null }" class="space-y-3" aria-label="Listening picks">
        @foreach ($this->picks as $level => $pick)
            @php $heard = in_array($level, $this->heardLevels, true); @endphp
            <article
                wire:key="pick-{{ $missionCode }}-{{ $day }}-{{ $level }}"
                x-data="{ loaded: false }"
                x-effect="if (playing === {{ $level }}) { loaded = true } else { $el.querySelectorAll('audio').forEach((audio) => audio.pause()) }"
                @class(['card overflow-hidden', 'ring-1 ring-success/40 dark:ring-success-dark/40' => $heard])
            >
                <div class="flex items-center gap-3 p-4">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs font-semibold text-ink-soft dark:text-ink-soft-dark">
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
                            @if ($pick['grammar'])
                                <span class="text-warning-ink" title="Grammar of the day: {{ $this->mission['grammar'] }}" aria-label="Grammar of the day">★</span>
                            @endif
                            @if ($heard)
                                <span class="inline-flex items-center gap-0.5 text-success dark:text-success-dark">@svg('heroicon-s-check-circle', 'h-3.5 w-3.5') Heard</span>
                            @endif
                        </div>
                        <h2 class="mt-1 line-clamp-2 text-base leading-snug font-bold text-ink dark:text-ink-dark">{{ $pick['title'] }}</h2>
                        <p class="mt-0.5 text-xs text-ink-faint dark:text-ink-faint-dark">{{ \App\Services\ListeningPicks::SOURCES[$pick['src']] ?? $pick['src'] }}{{ $pick['min'] ? ' · '.$pick['min'].' min' : '' }}</p>
                        @if (! $pick['local'] && $pick['tx'] === 'link' && $pick['txUrl'])
                            <a href="{{ $pick['txUrl'] }}" target="_blank" rel="noopener noreferrer" class="mt-1 inline-block text-xs font-semibold text-accent-ink underline dark:text-accent-ink-dark">Transcript</a>
                        @endif
                    </div>

                    @if ($pick['local'])
                        <button
                            type="button"
                            x-on:click="playing = playing === {{ $level }} ? null : {{ $level }}"
                            x-bind:aria-expanded="playing === {{ $level }}"
                            aria-label="Play"
                            x-bind:aria-label="playing === {{ $level }} ? 'Close player' : 'Play'"
                            class="inline-flex h-12 w-12 shrink-0 cursor-pointer items-center justify-center rounded-full bg-accent text-white shadow-sm transition-transform hover:scale-105 motion-reduce:transition-none dark:bg-accent-dark"
                        >
                            <span x-show="playing !== {{ $level }}">@svg('heroicon-s-play', 'ml-0.5 h-5 w-5')</span>
                            <span x-show="playing === {{ $level }}" x-cloak>@svg('heroicon-s-x-mark', 'h-5 w-5')</span>
                        </button>
                    @else
                        <a
                            href="{{ $pick['url'] }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            aria-label="Listen on the original page"
                            class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-accent text-white shadow-sm dark:bg-accent-dark"
                        >@svg('heroicon-o-arrow-top-right-on-square', 'h-5 w-5')</a>
                    @endif
                </div>

                @if ($pick['local'])
                    {{-- Built on the first open, so the page doesn't start
                         downloading three audio files nobody asked for;
                         hidden (and paused), not destroyed, after. --}}
                    <div wire:ignore x-show="playing === {{ $level }}" x-cloak class="space-y-3 border-t border-line px-4 pt-3 pb-4 dark:border-line-dark">
                        <template x-if="loaded">
                            <x-audio-player
                                :url="$pick['local']['audioUrl']"
                                :segments="$this->localSegments[$level] ?? []"
                                onListened="$wire.heardPick({{ $level }})"
                                :resume-key="'eos-resume:'.$missionCode.':'.AudioListen::pickKey($day, $level)"
                            />
                        </template>
                        <p class="flex flex-wrap gap-x-4 gap-y-1 text-xs font-semibold">
                            <a href="{{ $pick['url'] }}" target="_blank" rel="noopener noreferrer" class="text-accent-ink underline dark:text-accent-ink-dark">Original page</a>
                            @if ($pick['tx'] === 'link' && $pick['txUrl'])
                                <a href="{{ $pick['txUrl'] }}" target="_blank" rel="noopener noreferrer" class="text-accent-ink underline dark:text-accent-ink-dark">Transcript</a>
                            @endif
                        </p>
                    </div>
                @endif
            </article>
        @endforeach
    </section>
</div>
