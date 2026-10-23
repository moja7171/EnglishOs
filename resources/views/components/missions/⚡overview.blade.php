<?php

use App\Models\Mission;
use App\Models\MissionRun;
use App\Services\ListeningPicks;
use App\Services\PiPractice;
use App\Services\PiPrompts;
use App\Services\ProgramPlanner;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    /**
     * The day of the open mission the Today box shows after a tap on the
     * day bar; null is today — the default, and where "Back to today"
     * goes. A number that isn't another day of the mission is today too.
     */
    #[Url(as: 'day')]
    public ?int $viewDay = null;

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
     * where the picks themselves and the "I listened" tick live; the box
     * only reflects whether today's tick has been made.
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
            'practicedToday' => $learner->hasPracticedWithPiToday(),
        ];
    }

    /**
     * The circle on the listening row: the same self-reported "I listened"
     * tick as on /listening, filed under the day the row points at. The
     * day comes from the server-side plan, never from the browser.
     */
    public function markListened(): void
    {
        $listening = $this->listening;

        if ($listening === null) {
            return;
        }

        auth()->user()->recordListeningToday($listening['missionCode'], $listening['dayNumber']);

        unset($this->listening);
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
     * as the headline (the Missions button beside it), and one line under
     * it — "Mission N of 24" and the streak. The days are the day bar
     * below; the 100-day count, ahead/behind and memory freshness live on
     * the Progress page.
     *
     * @return array{title: string, missionNumber: int, dayNumber: ?int}
     */
    #[Computed]
    public function headline(): array
    {
        $today = $this->program['today'];

        if ($today['kind'] === 'finished') {
            return ['title' => 'All 24 missions done', 'missionNumber' => Mission::TOTAL_ROADMAP_MISSIONS, 'dayNumber' => null];
        }

        $mission = $today['kind'] === 'mission_day' ? $today['mission'] : $today['nextMission'];
        $code = $mission?->code ?? $today['nextMissionCode'];

        return [
            'title' => $mission ? $mission->code.' · '.$mission->title : $code,
            'missionNumber' => (int) preg_replace('/\D/', '', $code),
            'dayNumber' => $today['kind'] === 'mission_day' ? $today['dayNumber'] : null,
        ];
    }

    /**
     * The day bar under the headline: every day of the open mission, each
     * past / today / future against the day Today shows, with the steps
     * the box lists when the learner taps it. Empty outside a mission day.
     *
     * @return list<array{number: int, label: string, state: string, done: bool, completedAt: ?\Carbon\CarbonInterface, minutes: int, steps: list<array{key: string, label: string, minutes: int, done: bool}>}>
     */
    #[Computed]
    public function missionDays(): array
    {
        $today = $this->program['today'];

        if ($today['kind'] !== 'mission_day') {
            return [];
        }

        $mission = $today['mission'];
        $recorded = $today['run']->evidence()->pluck('phase')->all();

        return collect($today['run']->dayProgress())
            ->map(fn (array $day, int $index) => [
                'number' => $index + 1,
                'label' => $day['label'],
                'state' => match (true) {
                    $index + 1 < $today['dayNumber'] => 'past',
                    $index + 1 === $today['dayNumber'] => 'today',
                    default => 'future',
                },
                'done' => $day['done'],
                'completedAt' => $day['completedAt'],
                'minutes' => $day['estimatedMinutes'],
                'steps' => collect($day['stepKeys'])->map(fn (string $key) => [
                    'key' => $key,
                    'label' => $mission->stepLabel($key),
                    'minutes' => $mission->stepDuration($key),
                    'done' => in_array($key, $recorded, true),
                ])->all(),
            ])
            ->all();
    }

    /**
     * The day tapped on the day bar, when it isn't today: the Today box
     * then lists that day's steps instead. Null shows today.
     *
     * @return array<string, mixed>|null one entry of missionDays()
     */
    #[Computed]
    public function viewedDay(): ?array
    {
        $day = collect($this->missionDays)->firstWhere('number', $this->viewDay);

        return $day === null || $day['state'] === 'today' ? null : $day;
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

    #[Computed]
    public function justLostStreak(): bool
    {
        return auth()->user()->justLostStreak();
    }

    /**
     * What home shows below Today, as one line: the next mission still to
     * unlock and how many more come after it (locked or not built yet) —
     * the full list lives on the Progress page's Path tab.
     *
     * @return array{next: ?Mission, moreCount: int}
     */
    #[Computed]
    public function upcoming(): array
    {
        $upcoming = collect($this->missionSlots)->filter(fn ($slot) => ! $slot['mission'] || $slot['blockedBy']);
        $next = $upcoming->first(fn ($slot) => $slot['mission'] !== null)['mission'] ?? null;

        return [
            'next' => $next,
            'moreCount' => $upcoming->count() - ($next ? 1 : 0),
        ];
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

    /**
     * Every slot of the 24-mission curriculum (EOS-009 §15 roadmap, v3.0),
     * with the seeded Mission if there is one and what still gates it.
     * Home only uses it for upcoming() — the full list is the roadmap.
     *
     * Evidence Before Progress (EOS-001 Article 3) also gates mission-to-
     * mission: a seeded mission whose predecessor's MissionRun isn't at
     * least 'complete' or 'needs_review' is gated (see
     * MissionRun::gatingMission(), which also exempts a learner who
     * already has a run of their own for it).
     *
     * @return list<array{code: string, mission: ?Mission, blockedBy: ?Mission}>
     */
    #[Computed]
    public function missionSlots(): array
    {
        $seeded = Mission::orderBy('code')->get()->keyBy('code');
        $learner = auth()->user();

        return collect(range(1, Mission::TOTAL_ROADMAP_MISSIONS))
            ->map(fn ($n) => sprintf('M%02d', $n))
            ->map(fn ($code) => ['code' => $code, 'mission' => $seeded->get($code)])
            ->map(fn ($slot) => $slot + [
                'blockedBy' => $slot['mission'] ? MissionRun::gatingMission($learner, $slot['mission']) : null,
            ])
            ->all();
    }
};
?>

<div class="stagger-children mx-auto max-w-2xl space-y-5 p-6">
    @php $program = $this->program; $today = $program['today']; $headline = $this->headline; $streak = $this->streak; @endphp

    {{-- Where the learner is, in two lines: the mission as the headline,
         with the Missions button beside it (any reached day of any mission
         is one tap away there), then "Mission N of 24" and the streak, which opens the
         Progress page (the 100-day count, ahead/behind and the rest). --}}
    @php $viewedDay = $this->viewedDay; @endphp
    <header>
        <div class="flex items-start justify-between gap-3">
            <h1 class="font-display text-2xl font-extrabold text-ink dark:text-ink-dark">{{ $headline['title'] }}</h1>
            <x-missions-button class="mt-1" />
        </div>
        <a
            href="{{ route('progress.index') }}"
            wire:navigate
            class="mt-1 inline-flex flex-wrap items-center gap-x-1.5 text-xs font-semibold text-ink-soft transition-colors hover:text-ink dark:text-ink-soft-dark dark:hover:text-ink-dark"
        >
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

    {{-- The days of the open mission, one tap each: the Today box below
         switches to that day's steps (see missionDays()). The pills look
         like the day switcher on /listening and /pi. --}}
    @if ($missionDays = $this->missionDays)
        <nav class="-mt-2 grid auto-cols-fr grid-flow-col gap-1.5" aria-label="Days of {{ $today['mission']->code }}">
            @foreach ($missionDays as $missionDay)
                @php $viewing = $missionDay['number'] === ($viewedDay['number'] ?? $today['dayNumber']); @endphp
                <button
                    type="button"
                    wire:key="day-bar-{{ $missionDay['number'] }}"
                    wire:click="$set('viewDay', {{ $missionDay['state'] === 'today' ? 'null' : $missionDay['number'] }})"
                    @if ($viewing) aria-current="true" @endif
                    aria-label="Day {{ $missionDay['number'] }}{{ $missionDay['state'] === 'today' ? ', today' : '' }}{{ $missionDay['done'] ? ', done' : '' }}{{ $missionDay['state'] === 'future' ? ', not reached yet' : '' }}"
                    @class([
                        'inline-flex cursor-pointer items-center justify-center gap-1 rounded-full border py-1.5 text-xs font-semibold transition-colors',
                        'border-ink bg-ink text-ground dark:border-ink-dark dark:bg-ink-dark dark:text-ground-dark' => $viewing,
                        'border-accent text-accent-ink dark:border-accent-dark dark:text-accent-ink-dark' => ! $viewing && $missionDay['state'] === 'today',
                        'border-line text-ink-soft hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark' => ! $viewing && $missionDay['state'] === 'past',
                        'border-dashed border-line text-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark' => ! $viewing && $missionDay['state'] === 'future',
                    ])
                >
                    @if ($missionDay['done'])
                        @svg('heroicon-s-check-circle', 'h-3.5 w-3.5 text-success dark:text-success-dark')
                    @endif
                    Day {{ $missionDay['number'] }}
                </button>
            @endforeach
        </nav>
    @endif

    {{-- Today — the one thing this page must answer (see ProgramPlanner). --}}
    <section class="rounded-2xl border-2 border-accent/40 bg-surface p-4 dark:border-accent-dark/40 dark:bg-surface-dark">
        @if ($viewedDay)
            {{-- Another day of the mission, from the day bar: a finished or
                 skipped-past day opens step by step for review; a day still
                 ahead is a look-only list, like the steps ahead of today. --}}
            <div class="flex items-center justify-between gap-3">
                <p class="text-xs font-semibold tracking-wide text-accent-ink uppercase dark:text-accent-ink-dark">{{ \App\Models\Mission::dayHeading($viewedDay['number'], $viewedDay['label']) }}</p>
                <span class="shrink-0 text-xs text-ink-faint dark:text-ink-faint-dark">{{ $viewedDay['done'] ? 'Done '.$viewedDay['completedAt']->format('M j') : '~'.\App\Models\Mission::formatDuration($viewedDay['minutes']) }}</span>
            </div>
            <ul class="mt-3">
                @foreach ($viewedDay['steps'] as $step)
                    <x-today-row
                        :href="$viewedDay['state'] === 'past' ? route('missions.show', [$today['mission'], $step['key']]) : null"
                        :title="$step['label']"
                        :minutes="$step['minutes']"
                        :done="$step['done']"
                    />
                @endforeach
            </ul>
            <button type="button" wire:click="$set('viewDay', null)" class="mt-2 inline-flex cursor-pointer items-center gap-1 text-xs font-semibold text-accent-ink underline-offset-2 hover:underline dark:text-accent-ink-dark">
                @svg('heroicon-o-chevron-left', 'h-3 w-3') Back to today
            </button>

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

            @if ($nextAction)
                <a
                    href="{{ $nextAction['url'] }}"
                    wire:navigate
                    class="mt-3 inline-flex cursor-pointer items-center gap-1 rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
                >{{ $nextAction['label'] }} @svg('heroicon-o-chevron-right', 'h-3.5 w-3.5')</a>
            @endif

            {{-- The whole day as one checklist, in the order it's meant to be
                 done: Daily Review as the warm-up, the steps, then Pi. The
                 play mark sits on the row the main button points at. Steps
                 further ahead are plain text — the button is the way in. --}}
            @php $reviewIsNext = ($review['remaining'] ?? 0) > 0; @endphp
            <ul class="mt-3">
                @if ($review)
                    <x-review-today :review="$review" :current="$reviewIsNext" />
                @endif
                @foreach ($today['steps'] as $step)
                    <x-today-row
                        :href="$step['done'] || $step['current'] ? route('missions.show', [$today['mission'], $step['key']]) : null"
                        :title="$step['label']"
                        :minutes="$step['minutes']"
                        :done="$step['done']"
                        :current="$step['current'] && ! $reviewIsNext"
                    />
                @endforeach
                @if ($piPractice)
                    <x-pi-today :practice="$piPractice" :current="$today['dayCompletedToday'] && ! $reviewIsNext" />
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

    @if ($this->justBenefitedFromGrace)
        <p class="flex items-center gap-2 px-3.5 text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">
            <span aria-hidden="true">🧊</span> A freeze saved your streak yesterday.
        </p>
    @elseif ($this->justLostStreak)
        <p class="flex items-center gap-2 px-3.5 text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">
            @svg('heroicon-o-trophy', 'h-4 w-4 shrink-0 text-ink-faint dark:text-ink-faint-dark') Fresh start — your best run was {{ auth()->user()->longestStreak() }} {{ Str::plural('day', auth()->user()->longestStreak()) }}.
        </p>
    @endif

    {{-- At most one suggestion below the day's work (see suggestion()). --}}
    @if ($suggestion = $this->suggestion)
        <a href="{{ $suggestion['url'] }}" wire:navigate class="flex items-center gap-2.5 rounded-xl px-3.5 py-2.5 text-sm font-semibold text-ink-soft transition-colors hover:bg-surface-sunken dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">
            @svg($suggestion['icon'], 'h-4 w-4 shrink-0 text-ink-faint dark:text-ink-faint-dark')
            <span class="flex-1">{{ $suggestion['label'] }}</span>
            @svg('heroicon-o-chevron-right', 'h-4 w-4 shrink-0 text-ink-faint dark:text-ink-faint-dark')
        </a>
    @endif

    {{-- One line for what comes after: the next mission opens as a
         preview, the rest is on the Progress page's Path tab. --}}
    @php $upcoming = $this->upcoming; $linkClass = 'transition-colors hover:text-ink-soft dark:hover:text-ink-soft-dark'; @endphp
    @if ($upcoming['next'] || $upcoming['moreCount'] > 0)
        <p class="flex flex-wrap items-center justify-center gap-x-1.5 px-4 py-2 text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">
            <span aria-hidden="true">🔒</span>
            @if ($next = $upcoming['next'])
                <a href="{{ route('missions.show', [$next, 'overview']) }}" wire:navigate class="{{ $linkClass }}">Next: {{ $next->code }} {{ $next->title }}</a>
                <span aria-hidden="true">·</span>
            @endif
            <a href="{{ route('progress.index') }}#path" wire:navigate class="inline-flex items-center gap-1 {{ $linkClass }}">
                {{ $upcoming['moreCount'] > 0 ? $upcoming['moreCount'].' more'.($upcoming['next'] ? '' : ' '.Str::plural('mission', $upcoming['moreCount'])).' · ' : '' }}Roadmap @svg('heroicon-o-chevron-right', 'h-3.5 w-3.5')
            </a>
        </p>
    @endif
</div>
