<?php

use App\Models\AudioListen;
use App\Models\Mission;
use App\Services\ListeningPicks;
use App\Services\MissionGrid;
use App\Services\PexelsClient;
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
     * No arguments = the learner's own current day. Any other day opens
     * too; one ahead of their program position is a preview (see
     * isAhead()): the picks play, but nothing is ticked or kept.
     */
    public function mount(?string $requestedMission = null, ?int $requestedDay = null): void
    {
        $current = app(ListeningPicks::class)->dayFor($this->program['today']);

        $target = $requestedMission === null
            ? $current ?? ['missionCode' => 'M24', 'dayNumber' => 4]
            : ['missionCode' => $requestedMission, 'dayNumber' => $requestedDay ?? 1];

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
        if (! array_key_exists($level, $this->picks) || $this->isAhead) {
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

        unset($this->listenedToday, $this->listenedDaysByMission, $this->listenedDays, $this->missionDays, $this->listenCounts);
    }

    /**
     * How many times each of the day's picks was really heard (each a 90%
     * listen, see heardPick()), by level — the 🎧 count on its card.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function listenCounts(): array
    {
        $counts = auth()->user()->audioListens()
            ->where('mission_code', $this->missionCode)
            ->where('source', AudioListen::SOURCE_LISTENING_PICK)
            ->whereIn('pick_key', collect(array_keys($this->picks))->map(fn (int $level) => AudioListen::pickKey($this->day, $level)))
            ->selectRaw('pick_key, count(*) as listens')
            ->groupBy('pick_key')
            ->pluck('listens', 'pick_key');

        return collect(array_keys($this->picks))
            ->mapWithKeys(fn (int $level) => [$level => (int) ($counts[AudioListen::pickKey($this->day, $level)] ?? 0)])
            ->all();
    }

    /**
     * Each pick's own photo by level, or the mission's photo when the
     * pick's isn't cached — read from disk only, never fetched here.
     *
     * @return array<int, string|null>
     */
    #[Computed]
    public function pickImages(): array
    {
        $pexels = app(PexelsClient::class);
        $missionPhoto = app(MissionGrid::class)->coverUrl($this->missionCode);

        return collect(array_keys($this->picks))
            ->mapWithKeys(fn (int $level) => [$level => $pexels->cachedImageUrl(ListeningPicks::imageKey($this->missionCode, $this->day, $level)) ?? $missionPhoto])
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

    /**
     * A day the learner's program hasn't reached yet — look and listen,
     * never ticked (heardPick() re-checks it).
     */
    #[Computed]
    public function isAhead(): bool
    {
        return app(ListeningPicks::class)->indexOf($this->missionCode, $this->day) > $this->currentIndex;
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
    {{-- The mission's photo banner: the way back to this day on the
         mission's page, the four days, and whether this day's listening
         is done. --}}
    <x-mission-banner
        :mission-code="$missionCode"
        title="Listening"
        icon="heroicon-o-speaker-wave"
        :back-href="app(App\Services\MissionGrid::class)->pageUrl($missionCode, $day)"
        :back-label="$missionCode.' · '.$this->mission['title'].' · Day '.$day"
        :done-label="$listenedThisDay ? 'Listened' : null"
    >
        <x-slot:actions>
            <x-usage-guide-button on-photo />
        </x-slot:actions>
        <x-mission-day-pills :mission-code="$missionCode" route-name="listening.show" :days="$this->missionDays" />
    </x-mission-banner>

    @if (! $this->isToday && $this->currentDay)
        <a href="{{ route('listening.show') }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-semibold text-accent-ink underline dark:text-accent-ink-dark">
            Back to today ({{ $this->currentDay['missionCode'] }} · Day {{ $this->currentDay['dayNumber'] }})
        </a>
    @endif

    @if ($this->isAhead)
        <x-preview-only-note />
    @endif

    <h2 class="text-base font-extrabold text-ink dark:text-ink-dark">Pick one</h2>

    {{-- One card per pick: its photo, level, title, source and length, how
         many times it was really heard, and — when it was left half-way —
         an orange bar with where it stopped (the player's own resume
         memory). A tap opens the pick's full-screen player; leaving it
         (⌄) keeps the audio playing until another pick is opened. The day
         ticks itself once a pick has really been heard (90% played
         through, see heardPick()); there is no manual tick. --}}
    <section
        x-data="{ open: null, current: null }"
        x-effect="document.documentElement.classList.toggle('overflow-hidden', open !== null)"
        class="space-y-3"
        aria-label="Listening picks"
    >
        @foreach ($this->picks as $level => $pick)
            @php
                $listens = $this->listenCounts[$level] ?? 0;
                $source = \App\Services\ListeningPicks::SOURCES[$pick['src']] ?? $pick['src'];
                $sourceShort = \Illuminate\Support\Str::before($source, ' · ');
                $resumeKey = 'eos-resume:'.$missionCode.':'.AudioListen::pickKey($day, $level);
                $image = $this->pickImages[$level] ?? null;
            @endphp
            <article
                wire:key="pick-{{ $missionCode }}-{{ $day }}-{{ $level }}"
                x-data="{
                    loaded: false,
                    resumeAt: null,
                    length: {{ ($pick['min'] ?? 0) * 60 }},
                    refresh() {
                        try {
                            const at = Number(localStorage.getItem({{ Illuminate\Support\Js::from($resumeKey) }}));
                            const length = Number(localStorage.getItem({{ Illuminate\Support\Js::from($resumeKey.':length') }}));
                            if (length > 0) this.length = length;
                            this.resumeAt = at > 0 ? at : null;
                        } catch (e) {}
                    },
                    get share() { return this.length > 0 ? Math.min(this.resumeAt / this.length, 0.97) : 0.5 },
                    clock(seconds) { const s = Math.floor(seconds); return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0') },
                }"
                x-init="refresh()"
                x-effect="if (open === {{ $level }}) { loaded = true; current = {{ $level }} } else { refresh() } if (current !== {{ $level }}) $el.querySelectorAll('audio').forEach((audio) => audio.pause())"
            >
                @php
                    $target = AudioListen::PICK_TARGET;
                    $stripState = match (true) {
                        $listens === 0 => 'new',
                        $listens < $target => 'started',
                        $listens < 5 => 'goal',
                        default => 'replayed',
                    };
                    $cardOuter = 'block w-full overflow-hidden rounded-3xl bg-surface text-left shadow-md ring-1 ring-line dark:bg-surface-dark dark:ring-line-dark';
                @endphp
                @if ($pick['local'])
                    <button type="button" x-on:click="open = {{ $level }}" class="{{ $cardOuter }} cursor-pointer">
                @else
                    <a href="{{ $pick['url'] }}" target="_blank" rel="noopener noreferrer" class="{{ $cardOuter }}">
                @endif
                    <span class="flex w-full items-center gap-3 p-3">
                        <span class="relative h-16 w-16 shrink-0 overflow-hidden rounded-2xl bg-linear-to-br from-hero to-hero-2">
                            @if ($image)
                                <img src="{{ $image }}" alt="" class="h-full w-full object-cover">
                            @endif
                        </span>
                        <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                            <span class="flex items-center gap-1.5 text-[11px] font-extrabold text-ink-faint dark:text-ink-faint-dark">
                                <span class="inline-flex h-3 items-end gap-0.5" role="img" aria-label="Difficulty: {{ \App\Services\ListeningPicks::LEVELS[$level] }}">
                                    @foreach ([1, 2, 3] as $bar => $height)
                                        <span @class([
                                            'w-[3px] rounded-sm',
                                            'h-1' => $height === 1,
                                            'h-2' => $height === 2,
                                            'h-3' => $height === 3,
                                            'bg-accent dark:bg-accent-dark' => $bar <= $level,
                                            'bg-line dark:bg-line-dark' => $bar > $level,
                                        ])></span>
                                    @endforeach
                                </span>
                                {{ \App\Services\ListeningPicks::LEVELS[$level] }}
                                @if ($pick['grammar'])
                                    <span class="text-warning-ink" title="Grammar of the day: {{ $this->mission['grammar'] }}" aria-label="Grammar of the day">★</span>
                                @endif
                            </span>
                            <span class="line-clamp-2 text-sm leading-snug font-extrabold text-ink dark:text-ink-dark">{{ $pick['title'] }}</span>
                            <span class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">{{ $sourceShort }}{{ $pick['min'] ? ' · '.$pick['min'].' min' : '' }}</span>
                            @if ($pick['local'])
                                <span x-show="resumeAt !== null" x-cloak class="mt-1 flex items-center gap-2">
                                    <span class="h-1 flex-1 overflow-hidden rounded-full bg-line dark:bg-line-dark"><span class="block h-full rounded-full bg-accent dark:bg-accent-dark" :style="`width: ${share * 100}%`"></span></span>
                                    <span class="text-[11px] font-extrabold text-accent-ink tabular-nums dark:text-accent-ink-dark" x-text="clock(resumeAt ?? 0)"></span>
                                </span>
                            @endif
                        </span>
                        @unless ($pick['local'])
                            <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-accent text-white dark:bg-accent-dark" aria-label="Listen on the original page">@svg('heroicon-o-arrow-top-right-on-square', 'h-5 w-5')</span>
                        @endunless
                    </span>
                    @if ($pick['local'])
                        {{-- The count strip: always the same place on every
                             card, so the three read side by side. One hole
                             per listen the day asks for, filled as they
                             happen; the colour says where the pick stands
                             (new, started, goal reached, replayed). A pick
                             that plays outside the app can't be heard, so
                             it has no count. --}}
                        <span
                            data-testid="pick-count"
                            data-state="{{ $stripState }}"
                            @class([
                                'flex w-full items-center gap-3 border-t border-line py-2.5 pr-3 pl-4 dark:border-line-dark',
                                'bg-surface-sunken dark:bg-surface-sunken-dark' => in_array($stripState, ['new', 'started'], true),
                                'bg-success-soft dark:bg-success-soft-dark' => $stripState === 'goal',
                                'bg-warning-soft' => $stripState === 'replayed',
                            ])
                        >
                            <span @class([
                                'flex items-baseline gap-1.5',
                                'text-ink-faint dark:text-ink-faint-dark' => $stripState === 'new',
                                'text-accent-ink dark:text-accent-ink-dark' => $stripState === 'started',
                                'text-success dark:text-success-dark' => $stripState === 'goal',
                                'text-warning-ink' => $stripState === 'replayed',
                            ])>
                                <span class="font-display text-3xl leading-none font-extrabold tabular-nums">{{ $listens }}</span>
                                <span class="text-xs font-extrabold">heard</span>
                            </span>
                            <span class="flex items-center gap-1.5" aria-hidden="true">
                                @for ($hole = 1; $hole <= $target; $hole++)
                                    <span @class([
                                        'h-4 w-4 rounded-full',
                                        'bg-ground shadow-[inset_0_2px_3px_rgba(0,0,0,0.28)] ring-[1.5px] ring-line dark:bg-ground-dark dark:ring-line-dark' => $hole > $listens,
                                        'bg-accent ring-[1.5px] ring-accent dark:bg-accent-dark dark:ring-accent-dark' => $hole <= $listens && $stripState === 'started',
                                        'bg-success ring-[1.5px] ring-success dark:bg-success-dark dark:ring-success-dark' => $hole <= $listens && $stripState === 'goal',
                                        'bg-warning ring-[1.5px] ring-warning' => $hole <= $listens && $stripState === 'replayed',
                                    ])></span>
                                @endfor
                            </span>
                            <span @class([
                                'ml-auto text-xs font-bold whitespace-nowrap',
                                'text-ink-faint dark:text-ink-faint-dark' => in_array($stripState, ['new', 'started'], true),
                                'text-success dark:text-success-dark' => $stripState === 'goal',
                                'text-warning-ink' => $stripState === 'replayed',
                            ])>
                                @if ($stripState === 'started'){{ $target - $listens }} more @elseif ($stripState === 'goal')Goal ✓ @elseif ($stripState === 'replayed')Replayed @endif
                            </span>
                            <span @class([
                                'inline-flex shrink-0 items-center gap-1 rounded-full px-3.5 py-1.5 text-sm font-extrabold',
                                'bg-accent text-white dark:bg-accent-dark' => $listens === 0,
                                'bg-surface text-ink ring-[1.5px] ring-line dark:bg-surface-dark dark:text-ink-dark dark:ring-line-dark' => $listens > 0,
                            ]) aria-label="Play">
                                @svg('heroicon-s-play', 'h-3.5 w-3.5')
                                {{ $listens === 0 ? 'Play' : 'Again' }}
                            </span>
                        </span>
                    @endif
                @if ($pick['local'])
                    </button>
                @else
                    </a>
                @endif
                @if (! $pick['local'] && $pick['tx'] === 'link' && $pick['txUrl'])
                    <a href="{{ $pick['txUrl'] }}" target="_blank" rel="noopener noreferrer" class="mt-1 ml-3 inline-block text-xs font-semibold text-accent-ink underline dark:text-accent-ink-dark">Transcript</a>
                @endif

                @if ($pick['local'])
                    {{-- The pick's own full-screen player, built on the first
                         open (no audio is fetched before that), then only
                         hidden — the audio keeps playing behind ⌄. --}}
                    <div
                        wire:ignore
                        x-show="open === {{ $level }}"
                        x-cloak
                        x-back-close="{ open: open === {{ $level }}, close: () => open = null }"
                        x-on:keydown.escape.window="if (open === {{ $level }}) open = null"
                        x-on:loadedmetadata.capture="(() => { try { localStorage.setItem({{ Illuminate\Support\Js::from($resumeKey.':length') }}, $event.target.duration) } catch (e) {} })()"
                        role="dialog"
                        aria-modal="true"
                        aria-label="{{ $pick['title'] }}"
                        class="fixed inset-0 z-50 overflow-y-auto bg-ground dark:bg-ground-dark"
                    >
                        <div x-data="{ menu: false, listens: {{ $listens }}, goal: {{ $target }}, share: 0, counted: false }" class="mx-auto flex min-h-full max-w-lg flex-col gap-4 px-5 pt-[max(0.75rem,env(safe-area-inset-top))] pb-8">
                            <div class="relative flex items-center gap-2">
                                <button type="button" x-on:click="open = null" aria-label="Back to the list" class="inline-flex h-10 w-10 cursor-pointer items-center justify-center rounded-full text-ink-soft hover:bg-surface-sunken dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">@svg('heroicon-o-chevron-down', 'h-6 w-6')</button>
                                <span class="flex-1 text-center text-xs font-extrabold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">{{ $missionCode }} · Day {{ $day }}</span>
                                <button type="button" x-on:click="menu = ! menu" x-bind:aria-expanded="menu" aria-label="More" class="inline-flex h-10 w-10 cursor-pointer items-center justify-center rounded-full text-ink-soft hover:bg-surface-sunken dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">@svg('heroicon-o-ellipsis-horizontal', 'h-6 w-6')</button>
                                <div x-show="menu" x-cloak x-on:click.outside="menu = false" class="absolute top-11 right-0 z-10 flex min-w-48 flex-col rounded-2xl bg-surface p-1.5 text-sm font-bold text-ink shadow-xl dark:bg-surface-dark dark:text-ink-dark">
                                    <a href="{{ $pick['local']['audioUrl'] }}" download class="flex items-center gap-2 rounded-xl px-3 py-2.5 hover:bg-surface-sunken dark:hover:bg-surface-sunken-dark">@svg('heroicon-o-arrow-down-tray', 'h-4 w-4') Download</a>
                                    <a href="{{ $pick['url'] }}" target="_blank" rel="noopener noreferrer" class="flex items-center gap-2 rounded-xl px-3 py-2.5 hover:bg-surface-sunken dark:hover:bg-surface-sunken-dark">@svg('heroicon-o-arrow-top-right-on-square', 'h-4 w-4') Original page</a>
                                    @if ($pick['tx'] === 'link' && $pick['txUrl'])
                                        <a href="{{ $pick['txUrl'] }}" target="_blank" rel="noopener noreferrer" class="flex items-center gap-2 rounded-xl px-3 py-2.5 hover:bg-surface-sunken dark:hover:bg-surface-sunken-dark">@svg('heroicon-o-document-text', 'h-4 w-4') Transcript</a>
                                    @endif
                                    <button type="button" x-on:click="const audio = $root.querySelector('audio'); if (audio) audio.currentTime = 0; menu = false" class="flex cursor-pointer items-center gap-2 rounded-xl px-3 py-2.5 text-left hover:bg-surface-sunken dark:hover:bg-surface-sunken-dark">@svg('heroicon-o-arrow-uturn-left', 'h-4 w-4') Start from 0:00</button>
                                </div>
                            </div>

                            <div class="h-56 overflow-hidden rounded-3xl bg-linear-to-br from-hero to-hero-2">
                                @if ($image)
                                    <img src="{{ $image }}" alt="" class="h-full w-full object-cover">
                                @endif
                            </div>
                            <div>
                                <p class="text-lg leading-snug font-extrabold text-ink dark:text-ink-dark">{{ $pick['title'] }}</p>
                                <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">{{ $source }}</p>
                            </div>

                            {{-- How many times it was heard, big. The ring
                                 fills as the listen gets to 90% (when it
                                 counts), turns green and the number jumps
                                 the moment it does. --}}
                            <div class="flex items-center gap-4 rounded-3xl bg-surface p-3 shadow-sm ring-1 ring-line dark:bg-surface-dark dark:ring-line-dark" data-testid="player-count">
                                <div class="relative h-[68px] w-[68px] shrink-0">
                                    <svg viewBox="0 0 68 68" class="h-full w-full -rotate-90" aria-hidden="true">
                                        <circle cx="34" cy="34" r="29" fill="none" stroke-width="6" class="stroke-line dark:stroke-line-dark"></circle>
                                        <circle cx="34" cy="34" r="29" fill="none" stroke-width="6" stroke-linecap="round" stroke-dasharray="182.2" class="transition-[stroke-dashoffset] duration-150" x-bind:class="counted ? 'stroke-success dark:stroke-success-dark' : 'stroke-accent dark:stroke-accent-dark'" x-bind:style="{ strokeDashoffset: 182.2 * (1 - Math.min(share / 0.9, 1)) }" style="stroke-dashoffset: 182.2"></circle>
                                    </svg>
                                    <span class="absolute inset-0 grid place-items-center font-display text-2xl font-extrabold text-ink tabular-nums dark:text-ink-dark" x-bind:class="counted && 'animate-count-bump'" x-text="listens">{{ $listens }}</span>
                                </div>
                                <div class="flex min-w-0 flex-col">
                                    <p class="text-sm font-extrabold text-ink dark:text-ink-dark" x-text="listens === 1 ? '1 listen' : listens + ' listens'">{{ $listens === 1 ? '1 listen' : $listens.' listens' }}</p>
                                    @if ($this->isAhead)
                                        <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">Preview · not counted</p>
                                    @else
                                        <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark" x-bind:class="counted && 'text-success dark:text-success-dark'" x-text="counted ? (listens < goal ? 'Counted · ' + (goal - listens) + ' more to reach your goal' : listens === goal ? 'Counted · goal reached ✓' : 'Counted · heard again') : 'Counts when you reach 90%'">Counts when you reach 90%</p>
                                    @endif
                                </div>
                            </div>

                            <template x-if="loaded">
                                <x-audio-player
                                    podcast
                                    text-panel-class="mt-4 max-h-[45dvh] text-base"
                                    :url="$pick['local']['audioUrl']"
                                    :segments="$this->localSegments[$level] ?? []"
                                    :onListened="$this->isAhead ? null : '$wire.heardPick('.$level.'); listens++; counted = true; share = 1'"
                                    :onProgress="$this->isAhead ? null : 'if (heard > 0.02) counted = false; if (! counted) share = heard'"
                                    :resume-key="$this->isAhead ? null : $resumeKey"
                                />
                            </template>
                        </div>
                    </div>
                @endif
            </article>
        @endforeach
    </section>
</div>
