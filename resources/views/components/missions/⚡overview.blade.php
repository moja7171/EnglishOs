<?php

use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use App\Services\ListeningPicks;
use App\Services\PexelsClient;
use App\Services\PiPractice;
use App\Services\PiPrompts;
use App\Services\ProgramPlanner;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    /**
     * The 100-day program's answer to "what do I do today" — the top of
     * this page. See App\Services\ProgramPlanner for the whole model
     * (24 missions × 4 days, progress-based, never calendar-enforced).
     */
    #[Computed]
    public function program(): array
    {
        return app(ProgramPlanner::class)->plan(auth()->user());
    }

    /**
     * The daily listening picks for the learner's current program day (see
     * App\Services\ListeningPicks and the /listening page). "Current day"
     * is the same day Today shows: the open mission's day, or day 1 of the
     * next mission between missions. Null once all 24 missions are done.
     *
     * Shown inside the Today box as a single row linking to /listening,
     * where the picks live; the row is ticked once one of them has really
     * been heard (no manual tick anywhere).
     *
     * @return array{missionCode: string, dayNumber: int, listenedToday: bool}|null
     */
    #[Computed]
    public function listening(): ?array
    {
        $picks = app(ListeningPicks::class);
        $day = $picks->dayFor($this->program['today']);
        $trio = $day === null ? null : $picks->forDay($day['missionCode'], $day['dayNumber']);

        if ($trio === null) {
            return null;
        }

        return $day + [
            'listenedToday' => auth()->user()->hasListenedToday(),
        ];
    }

    /**
     * The daily voice practice with Pi (see App\Services\PiPractice and the
     * /pi page), shown at the bottom of the Today box as one quiet row.
     * The row points at the day the learner just finished and hasn't
     * practiced yet, otherwise at their current day; null when there is
     * neither (every mission done, nothing finished today).
     *
     * @return array{missionCode: string, dayNumber: int, title: string, practicedToday: bool}|null
     */
    #[Computed]
    public function piPractice(): ?array
    {
        $learner = auth()->user();
        $target = app(PiPractice::class)->targetFor($learner, $this->program['today']);

        if ($target === null) {
            return null;
        }

        return $target + [
            'title' => PiPrompts::DAYS[$target['dayNumber']]['title'],
            // The day's Daily talk also counts when it was done on an
            // earlier calendar day — the card ticks it, so no minutes left.
            'practicedToday' => $learner->hasPracticedWithPiToday()
                || in_array($target['dayNumber'], $learner->piPracticedDaysOf($target['missionCode']), true),
        ];
    }

    /**
     * Today's Daily Review for the Today box's review row — words,
     * speaking, and grammar combined into one finishable batch (see
     * User::dailyReviewItems()). Null when there is nothing to do AND
     * nothing was reviewed today: the row only shows up on days there is
     * something to review (or just was), so its appearance is the signal.
     *
     * About four items a minute, so a full batch of 8 reads "2 min".
     *
     * @return array{remaining: int, reviewedToday: int, breakdown: array{word: int, grammar: int, speaking: int}, minutes: int}|null
     */
    #[Computed]
    public function review(): ?array
    {
        $summary = auth()->user()->dailyReviewSummary();

        if ($summary['remaining'] === 0 && $summary['reviewedToday'] === 0) {
            return null;
        }

        return $summary + ['minutes' => $summary['remaining'] === 0 ? 0 : max(1, (int) ceil($summary['remaining'] / 4))];
    }

    /**
     * The Today box's one main button on a mission day: always the next
     * thing to do, so the learner never has to work the order out — Daily
     * Review first (a 2-minute warm-up), then the day's steps, then the
     * voice practice with Pi. Null once all of that is done: the day is
     * over, and the next one is only the small "Tomorrow" link (see
     * tomorrow()). Listening is not in this order: it's any time of day.
     *
     * @return array{label: string, url: string}|null
     */
    #[Computed]
    public function nextAction(): ?array
    {
        $today = $this->program['today'];

        if ($today['kind'] !== 'mission_day') {
            return null;
        }

        if (($this->review['remaining'] ?? 0) > 0) {
            return ['label' => 'Start with Daily Review', 'url' => route('review.index')];
        }

        if (! $today['dayCompletedToday']) {
            $currentStep = collect($today['steps'])->firstWhere('current', true);

            return [
                'label' => $currentStep ? 'Continue' : 'Open mission',
                'url' => route('missions.show', [$today['mission'], $currentStep['key'] ?? null]),
            ];
        }

        if ($this->piLeft) {
            return ['label' => 'Daily talk', 'url' => route('pi.practice')];
        }

        return null;
    }

    /**
     * A1: the next thing to do today as one big card — its name, a short
     * line, and one Start. The rest of the day is the small list under it.
     * Same order as nextAction(): Review, the day's step, then Daily talk.
     *
     * @return array{key: string, title: string, sub: string, icon: string, url: string}|null
     */
    #[Computed]
    public function nextCard(): ?array
    {
        $today = $this->program['today'];

        if ($today['kind'] !== 'mission_day') {
            return null;
        }

        if (($remaining = $this->review['remaining'] ?? 0) > 0) {
            return ['key' => 'review', 'title' => 'Daily Review', 'sub' => $remaining.' '.Str::plural('item', $remaining).' · '.$this->review['minutes'].' min', 'icon' => 'heroicon-s-bolt', 'url' => route('review.index')];
        }

        if (! $today['dayCompletedToday']) {
            $step = collect($today['steps'])->firstWhere('current', true);

            return $step
                ? ['key' => 'step', 'title' => $step['label'], 'sub' => ($today['dayLabel'] ?: $today['mission']->code).($step['minutes'] ? ' · '.$step['minutes'].' min' : ''), 'icon' => 'heroicon-s-play', 'url' => route('missions.show', [$today['mission'], $step['key']])]
                : ['key' => 'step', 'title' => $today['mission']->title, 'sub' => $today['mission']->code, 'icon' => 'heroicon-s-play', 'url' => route('missions.show', [$today['mission']])];
        }

        if ($this->piLeft) {
            return ['key' => 'pi', 'title' => 'Daily talk', 'sub' => $this->piPractice['title'].' · '.PiPractice::MINUTES.' min', 'icon' => 'heroicon-s-chat-bubble-left-right', 'url' => route('pi.practice')];
        }

        return null;
    }

    /**
     * A1's "friends now" row: up to three friends' faces, where the first
     * one who practised today is (mission · step), and one who hasn't
     * started yet. Null without friends.
     *
     * @return array{friends: \Illuminate\Support\Collection<int, User>, line: string, sub: ?string}|null
     */
    #[Computed]
    public function friendsNow(): ?array
    {
        $friends = auth()->user()->mutualFriends();

        if ($friends->isEmpty()) {
            return null;
        }

        [$active, $idle] = $friends->partition(fn (User $friend) => $friend->wasActiveToday());
        $where = function (User $friend): string {
            $run = $friend->currentInProgressMissionRun();
            $step = $run?->currentStepKey();

            return $friend->name.($run ? ' · '.$run->mission->code.($step ? ' '.$run->mission->stepLabel($step) : '') : ' practised today');
        };

        return [
            'friends' => $active->concat($idle)->take(3)->values(),
            'line' => $active->isNotEmpty() ? $where($active->first()) : $idle->first()->name." hasn't started today",
            'sub' => $active->isNotEmpty() && $idle->isNotEmpty() ? $idle->first()->name." hasn't started today" : ($active->count() > 1 ? '+'.($active->count() - 1).' more practised today' : null),
        ];
    }

    /**
     * The day's steps are done but the voice practice isn't — the day is
     * not over yet, so home says "one more: Pi" instead of "day done".
     */
    #[Computed]
    public function piLeft(): bool
    {
        return $this->piPractice !== null && ! $this->piPractice['practicedToday'];
    }

    /**
     * The next day, once today's steps are done: one small line under the
     * "done" message, so the learner sees how big tomorrow is and can still
     * go on tonight — never a second button next to the main one.
     *
     * @return array{label: string, url: string}|null
     */
    #[Computed]
    public function tomorrow(): ?array
    {
        $today = $this->program['today'];

        if ($today['kind'] !== 'mission_day' || ! $today['dayCompletedToday']) {
            return null;
        }

        $upNext = $today['upNext'];

        return [
            'label' => 'Tomorrow · Day '.$upNext['dayNumber'].' · ~'.Mission::formatDuration($upNext['minutes'] + PiPractice::MINUTES),
            'url' => route('missions.show', [$today['mission'], $upNext['stepKey']]),
        ];
    }

    /**
     * How long today takes: the day's steps + Daily Review + Pi (Extra
     * listening is any time and optional, so it's left out). Once anything
     * of today is done it turns into what's left, so the number shrinks as
     * the learner goes. Null outside a mission day.
     *
     * @return array{minutes: int, started: bool}|null
     */
    #[Computed]
    public function dayTime(): ?array
    {
        $today = $this->program['today'];

        if ($today['kind'] !== 'mission_day') {
            return null;
        }

        $steps = collect($today['steps']);
        $review = $this->review;
        $practice = $this->piPractice;

        $minutesLeft = $steps->reject(fn (array $step) => $step['done'])->sum('minutes')
            + ($review['minutes'] ?? 0)
            + ($this->piLeft ? PiPractice::MINUTES : 0);

        $started = $steps->contains(fn (array $step) => $step['done'])
            || ($review['reviewedToday'] ?? 0) > 0
            || ($practice['practicedToday'] ?? false);

        return ['minutes' => $minutesLeft, 'started' => $started];
    }

    /**
     * The top of home: the mission the learner is in (or about to start)
     * as the headline — on its photo banner when it is built — and one
     * line above it, "Mission N of 24" and the streak. The days are the
     * rings on the banner; the 100-day count, ahead/behind and memory
     * freshness live on the Progress page.
     *
     * @return array{title: string, missionNumber: int, dayNumber: ?int, mission: ?Mission}
     */
    #[Computed]
    public function headline(): array
    {
        $today = $this->program['today'];

        if ($today['kind'] === 'finished') {
            return ['title' => 'All 24 missions done', 'missionNumber' => Mission::TOTAL_ROADMAP_MISSIONS, 'dayNumber' => null, 'mission' => null];
        }

        $mission = $today['kind'] === 'mission_day' ? $today['mission'] : $today['nextMission'];
        $code = $mission?->code ?? $today['nextMissionCode'];

        return [
            'title' => $mission ? $mission->code.' · '.$mission->title : $code,
            'missionNumber' => (int) preg_replace('/\D/', '', $code),
            'dayNumber' => $today['kind'] === 'mission_day' ? $today['dayNumber'] : null,
            'mission' => $mission,
        ];
    }

    /**
     * The banner's background clip, only if it is already cached (the
     * mission's own page fetches it once) — home never waits on Pexels.
     */
    #[Computed]
    public function bannerVideoUrl(): ?string
    {
        $mission = $this->headline['mission'];

        return $mission ? app(PexelsClient::class)->cachedVideoUrl($mission->code.'-ambient') : null;
    }

    /**
     * The days of the open mission as rings on the banner, each past /
     * today / future against the day Today shows, with its three parts'
     * ticks — Lesson, Listening, Daily talk, the same as on the Missions
     * page — and a tap opening the mission's page on that day. Empty
     * outside a mission day.
     *
     * @return list<array{number: int, state: string, lesson: bool, listened: bool, talked: bool, url: string}>
     */
    #[Computed]
    public function missionDays(): array
    {
        $today = $this->program['today'];

        if ($today['kind'] !== 'mission_day') {
            return [];
        }

        $mission = $today['mission'];
        $listened = auth()->user()->listenedDaysOf($mission->code);
        $talked = auth()->user()->piPracticedDaysOf($mission->code);

        return collect($today['run']->dayProgress())
            ->map(fn (array $day, int $index) => [
                'number' => $index + 1,
                'state' => $state = match (true) {
                    $index + 1 < $today['dayNumber'] => 'past',
                    $index + 1 === $today['dayNumber'] => 'today',
                    default => 'future',
                },
                'lesson' => $day['done'],
                // A day behind the learner with its Lesson done is finished:
                // Listening and Daily talk are extras, never a reason to
                // leave an old day half-ringed.
                'listened' => ($state === 'past' && $day['done']) || in_array($index + 1, $listened, true),
                'talked' => ($state === 'past' && $day['done']) || in_array($index + 1, $talked, true),
                'url' => route('missions.show', [$mission, 'overview', 'day' => $index + 1]),
            ])
            ->all();
    }

    /**
     * The streak, shown only in the line under the headline. The flame is
     * greyed out until something is done today — the quiet version of the
     * old "practice today to keep your streak" card.
     *
     * @return array{days: int, activeToday: bool}
     */
    #[Computed]
    public function streak(): array
    {
        $learner = auth()->user();

        return ['days' => $learner->currentStreak(), 'activeToday' => $learner->wasActiveToday()];
    }

    /**
     * Offered once, on the home page, to anyone who skipped the placement
     * test at registration — the app would otherwise run on the
     * self-assessed level from the form for the whole 100 days.
     */
    #[Computed]
    public function needsPlacement(): bool
    {
        return auth()->user()->latestPlacementTest() === null;
    }

    /**
     * The one line below Today: at most ONE suggestion, the first that
     * applies — find your level, then the voice practice guide, then
     * practising with a friend (only for someone with a friend to practise
     * with). Never a stack of offers under the day's work.
     *
     * @return array{label: string, url: string, icon: string}|null
     */
    #[Computed]
    public function suggestion(): ?array
    {
        if ($this->needsPlacement) {
            return ['label' => 'Find your level · 8 min', 'url' => route('placement'), 'icon' => 'heroicon-o-academic-cap'];
        }

        if ($this->needsPiGuide) {
            return ['label' => 'How Daily talk works · 2 min', 'url' => route('pi.setup'), 'icon' => 'heroicon-o-microphone'];
        }

        if (auth()->user()->mutualFriends()->isNotEmpty()) {
            return ['label' => 'Practice with a friend', 'url' => route('pair-practice.index'), 'icon' => 'heroicon-o-user-group'];
        }

        return null;
    }

    #[Computed]
    public function justBenefitedFromGrace(): bool
    {
        return auth()->user()->justBenefitedFromGrace();
    }



    /**
     * The voice practice guide is offered as one line on home until the
     * learner has opened it and tapped through (see ⚡pi-setup's done()).
     */
    #[Computed]
    public function needsPiGuide(): bool
    {
        return auth()->user()->pi_onboarded_at === null;
    }

};
?>

<div class="stagger-children mx-auto max-w-2xl space-y-5 p-6">
    @php $program = $this->program; $today = $program['today']; $headline = $this->headline; $streak = $this->streak; @endphp

    {{-- Where the learner is: "Mission N of 24" and the streak (which opens
         the Progress page — the 100-day count, ahead/behind and the rest),
         then the mission as the headline. A built mission sits on its photo
         banner (its clip fading in once cached), the same as its own page:
         the title opens that page, and each day's ring — Lesson, Listening,
         Daily talk — opens it on that day. Between missions it is the next
         one, without days; a plain headline when there is none to show. --}}
    @php $streakLine = 'inline-flex flex-wrap items-center gap-x-1.5 text-xs font-semibold transition-colors'; @endphp
    @if ($bannerMission = $headline['mission'])
        <x-mission-banner
            :mission-code="$bannerMission->code"
            :title="$headline['title']"
            :title-href="route('missions.show', [$bannerMission, 'overview', 'day' => $headline['dayNumber']])"
        >
            <x-slot:top>
                <a href="{{ route('progress.index') }}" wire:navigate class="{{ $streakLine }} text-white/85 hover:text-white">
                    <span>Mission {{ $headline['missionNumber'] }} of {{ \App\Models\Mission::TOTAL_ROADMAP_MISSIONS }}</span>
                    @if ($streak['days'] > 0)
                        <span aria-hidden="true">·</span>
                        <span @class(['inline-flex items-center gap-0.5', 'opacity-50 grayscale' => ! $streak['activeToday']]) title="{{ $streak['days'] }}-day streak">
                            <x-streak-flame :streak="$streak['days']" animated /> {{ $streak['days'] }}
                        </span>
                    @endif
                    @svg('heroicon-o-chevron-right', 'h-3 w-3 text-white/60')
                </a>
            </x-slot:top>
            @if ($videoUrl = $this->bannerVideoUrl)
                <x-slot:media>
                    <x-ambient-video :url="$videoUrl" />
                </x-slot:media>
            @endif
            @if ($missionDays = $this->missionDays)
                {{-- The rings pick the day shown under the banner (the mission's
                     days live right here on Home). --}}
                @if (($dayTime = $this->dayTime) && $dayTime['minutes'] > 0)
                    <p class="mt-2 inline-flex items-center gap-1 rounded-full bg-white/15 px-2.5 py-0.5 text-xs font-bold text-white">
                        @svg('heroicon-o-clock', 'h-3.5 w-3.5')
                        ~{{ \App\Models\Mission::formatDuration($dayTime['minutes']) }} {{ $dayTime['started'] ? 'left' : 'today' }}
                    </p>
                @endif
                <nav class="mt-3 flex gap-1.5" aria-label="Days of {{ $bannerMission->code }}" x-data="{ day: {{ (int) ($today['dayNumber'] ?? 1) }} }">
                    @foreach ($missionDays as $missionDay)
                        @php $doneParts = collect([$missionDay['lesson'], $missionDay['listened'], $missionDay['talked']])->filter()->count(); @endphp
                        <button
                            type="button"
                            x-on:click="day = {{ $missionDay['number'] }}; $dispatch('select-day', { day: {{ $missionDay['number'] }} })"
                            x-bind:aria-pressed="day === {{ $missionDay['number'] }}"
                            wire:key="day-ring-{{ $missionDay['number'] }}"
                            @if ($missionDay['state'] === 'today') aria-current="true" @endif
                            aria-label="Day {{ $missionDay['number'] }}{{ $missionDay['state'] === 'today' ? ', today' : '' }}{{ $doneParts === 3 ? ', done' : ($doneParts ? ", {$doneParts} of 3 done" : '') }}{{ $missionDay['state'] === 'future' ? ', not reached yet' : '' }}"
                            @class([
                                'inline-flex shrink-0 cursor-pointer rounded-full transition-transform hover:scale-110 motion-reduce:transition-none',
                                'opacity-60' => $missionDay['state'] === 'future',
                            ])
                            x-bind:class="day === {{ $missionDay['number'] }} && 'ring-2 ring-white ring-offset-2 ring-offset-transparent'"
                        >
                            <x-day-ring :number="$missionDay['number']" :lesson="$missionDay['lesson']" :listened="$missionDay['listened']" :talked="$missionDay['talked']" :today="$missionDay['state'] === 'today'" on-photo />
                        </button>
                    @endforeach
                </nav>
            @else
                <p class="mt-0.5 text-sm text-white/80">Up next</p>
            @endif
        </x-mission-banner>
    @else
        <header>
            <h1 class="font-display text-2xl font-extrabold text-ink dark:text-ink-dark">{{ $headline['title'] }}</h1>
            <a href="{{ route('progress.index') }}" wire:navigate class="{{ $streakLine }} mt-1 text-ink-soft hover:text-ink dark:text-ink-soft-dark dark:hover:text-ink-dark">
                <span>Mission {{ $headline['missionNumber'] }} of {{ \App\Models\Mission::TOTAL_ROADMAP_MISSIONS }}</span>
                @if ($streak['days'] > 0)
                    <span aria-hidden="true">·</span>
                    <span @class(['inline-flex items-center gap-0.5', 'opacity-50 grayscale' => ! $streak['activeToday']]) title="{{ $streak['days'] }}-day streak">
                        <x-streak-flame :streak="$streak['days']" animated /> {{ $streak['days'] }}
                    </span>
                @endif
                @svg('heroicon-o-chevron-right', 'h-3 w-3 text-ink-faint dark:text-ink-faint-dark')
            </a>
        </header>
    @endif

    {{-- Today — the one thing this page must answer (see ProgramPlanner). --}}
    @php $daysBelow = $today['kind'] === 'mission_day' && $this->missionDays !== []; @endphp
    <section @class(['rounded-3xl bg-surface p-3.5 dark:bg-surface-dark' => ! $daysBelow, 'space-y-3' => $daysBelow])>
        @if ($daysBelow)
            {{-- The Today box is gone: the time is a chip on the banner and the
                 next thing is the highlighted card of the day below. What is
                 left here: the day-done note and the Review row. --}}
            @php $review = $this->review; @endphp
            @if ($today['dayCompletedToday'])
                <div class="rounded-2xl bg-success-soft px-3.5 py-2.5 dark:bg-success-soft-dark">
                    <p class="flex items-center gap-2 text-sm font-semibold text-success dark:text-success-dark">
                        @svg('heroicon-s-check-circle', 'h-4 w-4 shrink-0')
                        {{ $this->piLeft ? 'Steps done — one more: Pi' : 'Day '.$today['dayNumber'].' done — nice work.' }}
                    </p>
                    @if ($tomorrow = $this->tomorrow)
                        <a href="{{ $tomorrow['url'] }}" wire:navigate class="mt-1 ms-6 inline-flex items-center gap-1 text-xs font-semibold text-ink-soft underline-offset-2 hover:underline dark:text-ink-soft-dark">{{ $tomorrow['label'] }} @svg('heroicon-o-chevron-right', 'h-3 w-3')</a>
                    @endif
                </div>
            @endif
            @if (($review['remaining'] ?? 0) > 0)
                <a href="{{ route('review.index') }}" wire:navigate class="flex min-h-16 items-center gap-3 rounded-2xl bg-accent-soft p-3 dark:bg-accent-soft-dark">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-accent text-white dark:bg-accent-dark">@svg('heroicon-s-bolt', 'h-5 w-5')</span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-base font-extrabold text-ink dark:text-ink-dark">Review</span>
                        <span class="block truncate text-xs font-bold text-accent-ink dark:text-accent-ink-dark">{{ $review['remaining'] }} {{ Str::plural('item', $review['remaining']) }} · {{ $review['minutes'] }} min</span>
                    </span>
                    <span class="shrink-0 rounded-full bg-accent px-4 py-2 text-sm font-extrabold text-white dark:bg-accent-dark">Start</span>
                </a>
            @elseif (($review['reviewedToday'] ?? 0) > 0)
                <p class="flex items-center gap-1.5 px-1 text-sm font-bold text-success dark:text-success-dark">@svg('heroicon-s-check', 'h-4 w-4') Review done</p>
            @endif
        @elseif ($today['kind'] === 'mission_day')
            @php $nextAction = $this->nextAction; $dayTime = $this->dayTime; $review = $this->review; $piPractice = $this->piPractice; @endphp
            <div class="flex items-center justify-between gap-3">
                <p class="text-xs font-semibold tracking-wide text-accent-ink uppercase dark:text-accent-ink-dark">Today{{ $today['dayLabel'] ? ' · '.$today['dayLabel'] : '' }}</p>
                @if ($dayTime['minutes'] > 0)
                    <span class="shrink-0 text-xs text-ink-faint dark:text-ink-faint-dark">~{{ \App\Models\Mission::formatDuration($dayTime['minutes']) }}{{ $dayTime['started'] ? ' left' : '' }}</span>
                @endif
            </div>

            @if ($today['dayCompletedToday'])
                {{-- Finished earlier today: stay on this day (the date flips
                     the box to the next one tomorrow). "Done" only once Pi
                     is done too. --}}
                <div class="mt-3 rounded-xl bg-success-soft px-3 py-2.5 dark:bg-success-soft-dark">
                    <p class="flex items-center gap-2 text-sm font-semibold text-success dark:text-success-dark">
                        @svg('heroicon-s-check-circle', 'h-4 w-4 shrink-0')
                        {{ $this->piLeft ? 'Steps done — one more: Pi' : 'Day '.$today['dayNumber'].' done — nice work.' }}
                    </p>
                    @if ($tomorrow = $this->tomorrow)
                        <a href="{{ $tomorrow['url'] }}" wire:navigate class="mt-1 ms-6 inline-flex items-center gap-1 text-xs font-semibold text-ink-soft underline-offset-2 hover:underline dark:text-ink-soft-dark">{{ $tomorrow['label'] }} @svg('heroicon-o-chevron-right', 'h-3 w-3')</a>
                    @endif
                </div>
            @endif

            {{-- A1: the next thing as one big card with one Start; the rest
                 of the day a small list under it. --}}
            @php $nextCard = $this->nextCard; $nextKey = $nextCard['key'] ?? null; @endphp
            @if ($nextCard)
                <div class="mt-3 flex flex-col gap-3 rounded-2xl bg-accent-soft p-3.5 dark:bg-accent-soft-dark">
                    <div class="flex items-center gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-accent text-white dark:bg-accent-dark">@svg($nextCard['icon'], 'h-5 w-5')</span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-base font-extrabold text-ink dark:text-ink-dark">{{ $nextCard['title'] }}</span>
                            <span class="block truncate text-xs font-bold text-accent-ink dark:text-accent-ink-dark">{{ $nextCard['sub'] }}</span>
                        </span>
                    </div>
                    <a href="{{ $nextCard['url'] }}" wire:navigate class="block w-full rounded-2xl bg-accent py-3 text-center text-base font-extrabold text-white shadow-[0_4px_0_color-mix(in_srgb,var(--color-accent)_60%,black)] transition-opacity hover:opacity-90 dark:bg-accent-dark">Start</a>
                </div>
            @endif

            @php $daysBelow = $this->missionDays !== []; @endphp
            <ul class="mt-2">
                @if ($review && $nextKey !== 'review')
                    <x-review-today :review="$review" />
                @endif
                @foreach ($daysBelow ? [] : $today['steps'] as $step)
                    @continue($nextKey === 'step' && $step['current'])
                    <x-today-row
                        :href="$step['done'] || $step['current'] ? route('missions.show', [$today['mission'], $step['key']]) : null"
                        :title="$step['label']"
                        :minutes="$step['minutes']"
                        :done="$step['done']"
                    />
                @endforeach
                @if ($piPractice && $nextKey !== 'pi' && ! $daysBelow)
                    <x-pi-today :practice="$piPractice" />
                @endif
            </ul>

            @if ($listening = $this->listening)
                <div class="mt-2 border-t border-line pt-1 dark:border-line-dark">
                    <x-listening-today :listening="$listening" />
                </div>
            @endif

        @elseif ($today['kind'] === 'checkpoint')
            {{-- S3 of [[project_growth_without_discouragement_stories]] —
                 offered, never pushed, right after a checkpoint mission
                 closes and before nudging toward the next one. --}}
            <p class="text-xs font-semibold tracking-wide text-accent-ink uppercase dark:text-accent-ink-dark">Today · {{ $today['mission']->code }} done</p>
            <div class="mt-2 rounded-xl card-accent p-3">
                <p class="text-sm font-semibold text-accent-ink dark:text-accent-ink-dark">Want to hear how far you've come?</p>
                <p class="mt-0.5 text-xs text-accent-ink/80 dark:text-accent-ink-dark/80">Your placement question again, side by side · 1 min</p>
                <a
                    href="{{ route('missions.checkpoint', $today['mission']) }}"
                    wire:navigate
                    class="mt-2 inline-flex cursor-pointer items-center gap-1 rounded-full bg-ink px-4 py-1.5 text-xs font-semibold text-ground transition-colors hover:opacity-85 dark:bg-ink-dark dark:text-ground-dark"
                >Your voice, {{ $today['mission']->code }} in @svg('heroicon-o-chevron-right', 'h-3 w-3')</a>
            </div>

            @if ($today['nextMission'])
                <p class="mt-4 text-xs text-ink-faint dark:text-ink-faint-dark">
                    Or skip it — <a href="{{ route('missions.show', [$today['nextMission'], $today['nextMission']->stepKeys()[0] ?? null]) }}" wire:navigate class="underline hover:text-ink dark:hover:text-ink-dark">start {{ $today['nextMission']->code }}: {{ $today['nextMission']->title }}</a>.
                </p>
            @endif

            <ul class="mt-3 empty:hidden">
                @if ($review = $this->review)
                    <x-review-today :review="$review" />
                @endif
                @if ($piPractice = $this->piPractice)
                    <x-pi-today :practice="$piPractice" />
                @endif
            </ul>
            @if ($listening = $this->listening)
                <div class="mt-2 border-t border-line pt-1 dark:border-line-dark">
                    <x-listening-today :listening="$listening" />
                </div>
            @endif

        @elseif ($today['kind'] === 'start_next')
            <p class="text-xs font-semibold tracking-wide text-accent-ink uppercase dark:text-accent-ink-dark">Today · Start a new mission</p>
            @if ($today['nextMission'])
                <p class="mt-0.5 text-sm font-semibold text-ink dark:text-ink-dark">{{ $today['nextMission']->code }}: {{ $today['nextMission']->title }}</p>
                <p class="mt-0.5 text-xs text-ink-faint dark:text-ink-faint-dark">Day 1 · ~{{ \App\Models\Mission::formatDuration(collect($today['nextMission']->phases[0]['steps'] ?? [])->sum(fn ($s) => (int) ($s['duration_minutes'] ?? 0))) }}</p>
                <a
                    href="{{ route('missions.show', [$today['nextMission'], $today['nextMission']->stepKeys()[0] ?? null]) }}"
                    wire:navigate
                    class="mt-3 inline-flex cursor-pointer items-center gap-1 rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
                >Start {{ $today['nextMission']->code }} @svg('heroicon-o-chevron-right', 'h-3.5 w-3.5')</a>
            @else
                <p class="mt-0.5 text-sm text-ink-soft dark:text-ink-soft-dark">{{ $today['nextMissionCode'] }} is coming soon — keep your streak with <a href="{{ route('review.index') }}" wire:navigate class="underline">Review</a>.</p>
            @endif

            <ul class="mt-3 empty:hidden">
                @if ($review = $this->review)
                    <x-review-today :review="$review" />
                @endif
                @if ($piPractice = $this->piPractice)
                    <x-pi-today :practice="$piPractice" />
                @endif
            </ul>
            @if ($listening = $this->listening)
                <div class="mt-2 border-t border-line pt-1 dark:border-line-dark">
                    <x-listening-today :listening="$listening" />
                </div>
            @endif

        @else
            <p class="text-xs font-semibold tracking-wide text-accent-ink uppercase dark:text-accent-ink-dark">100 days · done</p>
            <p class="mt-0.5 text-sm font-semibold text-ink dark:text-ink-dark">All 24 missions complete. Keep the streak with Daily Review — and keep talking.</p>

            <ul class="mt-3 empty:hidden">
                @if ($review = $this->review)
                    <x-review-today :review="$review" />
                @endif
                @if ($piPractice = $this->piPractice)
                    <x-pi-today :practice="$piPractice" />
                @endif
            </ul>
            @if ($listening = $this->listening)
                <div class="mt-2 border-t border-line pt-1 dark:border-line-dark">
                    <x-listening-today :listening="$listening" />
                </div>
            @endif
        @endif
    </section>

    {{-- The mission's days under the banner: the day picked on its rings,
         with the Lesson's steps, Listening and Daily talk (the mission's own
         page, without its banner and tabs). --}}
    @if ($this->missionDays !== [])
        <livewire:missions.runner :mission="$today['mission']" step="overview" :embedded="true" :key="'home-days-'.$today['mission']->code" />
    @endif

    @if ($this->justBenefitedFromGrace)
        <p class="flex items-center gap-2 px-3.5 text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">
            <span aria-hidden="true">🧊</span> A freeze saved your streak yesterday.
        </p>
    @endif

    {{-- A1: where friends are right now — one tap to the Friends tab. --}}
    @if ($friendsNow = $this->friendsNow)
        <a href="{{ route('friends.index') }}" wire:navigate class="flex items-center gap-3 rounded-2xl bg-surface px-3.5 py-2.5 transition-colors hover:bg-surface-sunken dark:bg-surface-dark dark:hover:bg-surface-sunken-dark">
            <span class="flex shrink-0 -space-x-2">
                @foreach ($friendsNow['friends'] as $friend)
                    <x-user-avatar :user="$friend" class="h-8 w-8 rounded-full ring-2 ring-surface dark:ring-surface-dark" />
                @endforeach
            </span>
            <span class="min-w-0 flex-1">
                <span class="block truncate text-sm font-bold text-ink dark:text-ink-dark">{{ $friendsNow['line'] }}</span>
                @if ($friendsNow['sub'])
                    <span class="block truncate text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">{{ $friendsNow['sub'] }}</span>
                @endif
            </span>
            @svg('heroicon-o-chevron-right', 'h-4 w-4 shrink-0 text-ink-faint dark:text-ink-faint-dark')
        </a>
    @endif

    {{-- At most one suggestion below the day's work (see suggestion()). --}}
    @if ($suggestion = $this->suggestion)
        <a href="{{ $suggestion['url'] }}" wire:navigate class="flex items-center gap-2.5 rounded-xl px-3.5 py-2.5 text-sm font-semibold text-ink-soft transition-colors hover:bg-surface-sunken dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">
            @svg($suggestion['icon'], 'h-4 w-4 shrink-0 text-ink-faint dark:text-ink-faint-dark')
            <span class="flex-1">{{ $suggestion['label'] }}</span>
            @svg('heroicon-o-chevron-right', 'h-4 w-4 shrink-0 text-ink-faint dark:text-ink-faint-dark')
        </a>
    @endif

</div>
