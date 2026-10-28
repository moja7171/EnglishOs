<?php

use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\PreviewMissionRun;
use App\Services\ListeningPicks;
use App\Services\PexelsClient;
use App\Services\ProgramPlanner;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    public Mission $mission;

    public MissionRun $run;

    public ?string $viewStep = null;

    /**
     * ?retry=1 re-opens an already-evidenced step as live/editable instead
     * of the default read-only review — the mechanism behind Mission
     * Result's "Redo this step" / "Get an updated result" links (EOS-001
     * Article 3's retry_evidence path). Submitting the step's normal save
     * flow creates a fresh Evidence row; nothing here needs to know about
     * that, since every step already reads Evidence via ->latest().
     */
    #[Url]
    public bool $retry = false;

    /**
     * ?day=2 opens that day on the mission's page — the way in from its
     * circle on the Missions page, and the way back from its steps, its
     * Listening and its Daily talk. Only which day is unfolded; nothing
     * else depends on it.
     */
    #[Url(as: 'day')]
    public ?int $requestedDay = null;

    /**
     * True while looking at a mission the learner hasn't unlocked yet:
     * every day and step of it is viewable, none is doable, and nothing is
     * recorded — $run is then a throwaway PreviewMissionRun, not a database
     * row.
     */
    #[Locked]
    public bool $missionLocked = false;

    /**
     * The gate is checked BEFORE findOrStart() deliberately — that call is
     * a firstOrCreate() and would otherwise plant an 'in_progress' run for
     * a still-gated mission on the mere act of visiting its URL, which
     * would then permanently exempt it from the gate (see
     * MissionRun::gatingMission()'s "already has ANY run" carve-out). A
     * gated mission is previewed on an in-memory run instead, so visiting
     * it never starts it.
     */
    public function mount(Mission $mission, ?string $step = null): void
    {
        $this->mission = $mission;
        $this->viewStep = $step;

        if (MissionRun::gatingMission(auth()->user(), $mission)) {
            $this->missionLocked = true;
            $this->run = PreviewMissionRun::for(auth()->user(), $mission);

            return;
        }

        $this->run = MissionRun::findOrStart(auth()->user(), $mission);
    }

    /**
     * The earlier mission that has to be finished before this one unlocks,
     * while previewing a locked mission.
     */
    public function getGatingMissionProperty(): ?Mission
    {
        return $this->missionLocked
            ? MissionRun::gatingMission(auth()->user(), $this->mission)
            : null;
    }

    /**
     * The mission's days for its page, each with its three parts — the
     * Lesson (this run's steps of the day), Listening and Daily talk — and
     * their ticks, the same ones as on the Missions page. All three parts
     * of every day open: done to review, current to continue, later as a
     * look-only preview — a Lesson until its day unlocks, Listening and
     * Daily talk until the learner's program reaches the day ('ahead'). "Here" is the learner's day today, or else the run's
     * current day.
     *
     * @return list<array{number: int, heading: string, estimatedMinutes: int, completedAt: ?\Carbon\Carbon, lesson: bool, listened: bool, talked: bool, here: bool, preview: bool, ahead: bool, started: bool, lessonUrl: ?string, listeningUrl: ?string, talkUrl: ?string, primary: ?string}>
     */
    public function getMissionDaysProperty(): array
    {
        $learner = auth()->user();
        $picks = app(ListeningPicks::class);
        $today = $picks->dayFor(app(ProgramPlanner::class)->plan($learner)['today']);
        $reachedIndex = $today === null ? ProgramPlanner::TOTAL_DAYS - 1 : $picks->indexOf($today['missionCode'], $today['dayNumber']);
        $todayHere = ($today['missionCode'] ?? null) === $this->mission->code ? $today['dayNumber'] : null;
        $listened = $learner->listenedDaysOf($this->mission->code);
        $talked = $learner->piPracticedDaysOf($this->mission->code);
        $code = $this->mission->code;

        return collect($this->run->dayProgress())
            ->map(function (array $day, int $index) use ($picks, $reachedIndex, $todayHere, $listened, $talked, $code) {
                $number = $index + 1;
                $preview = $this->missionLocked || $day['locked'];
                $current = $day['current'] && ! $this->missionLocked;
                $entryStep = $current ? $this->currentStepKey : ($day['stepKeys'][0] ?? null);
                $reached = ! $this->missionLocked && $number <= 4 && $picks->indexOf($code, $number) <= $reachedIndex;

                $parts = [
                    'lesson' => $day['done'],
                    'listening' => in_array($number, $listened, true),
                    'talk' => in_array($number, $talked, true),
                ];
                $urls = [
                    'lesson' => $entryStep ? route('missions.show', [$this->mission, $entryStep]) : null,
                    'listening' => route('listening.show', [$code, $number]),
                    'talk' => route('pi.practice', [$code, $number]),
                ];

                return [
                    'number' => $number,
                    'heading' => Mission::dayHeading($number, $day['label']),
                    'estimatedMinutes' => $day['estimatedMinutes'],
                    'stepCount' => count($day['stepKeys']),
                    'completedAt' => $day['completedAt'],
                    'lesson' => $parts['lesson'],
                    'listened' => $parts['listening'],
                    'talked' => $parts['talk'],
                    'here' => $todayHere !== null ? $todayHere === $number : $current,
                    'preview' => $preview,
                    'ahead' => ! $reached,
                    'started' => $day['startedAt'] !== null,
                    'lessonUrl' => $urls['lesson'],
                    'listeningUrl' => $urls['listening'],
                    'talkUrl' => $urls['talk'],
                    // The one filled button of the day: its first part not
                    // done yet, in order — never a preview.
                    'primary' => collect($parts)
                        ->keys()
                        ->first(fn (string $part) => ! $parts[$part] && $urls[$part] !== null && ! ($part === 'lesson' ? $preview : ! $reached)),
                ];
            })
            ->all();
    }

    /**
     * The one day unfolded on the mission's page: the one asked for
     * (?day=), else the learner's day here — none on a finished or
     * not-yet-reached mission, whose days all stay folded.
     */
    public function getOpenDayNumberProperty(): ?int
    {
        $days = $this->missionDays;

        if ($this->requestedDay !== null && $this->requestedDay >= 1 && $this->requestedDay <= count($days)) {
            return $this->requestedDay;
        }

        return collect($days)->firstWhere('here', true)['number'] ?? null;
    }

    /**
     * Cleared — the same bar the mission-to-mission gate and the Missions
     * page use — so the page shows the recap under the days.
     */
    public function getIsFinishedProperty(): bool
    {
        return ! $this->missionLocked
            && in_array($this->run->status, [MissionRun::STATUS_COMPLETE, MissionRun::STATUS_NEEDS_REVIEW], true);
    }

    public function getCurrentStepKeyProperty(): ?string
    {
        return $this->run->currentStepKey();
    }

    public function getStepKeysProperty(): array
    {
        return $this->mission->stepKeys();
    }

    /**
     * Steps the learner can DO — done steps plus the current one. Evidence
     * Before Progress (EOS-003 §7) still applies: you can redo any of
     * these, but never complete a step ahead of the current one — except
     * for an admin account (User::bypassesEvidenceGating()), which reaches
     * everything. Steps beyond this set are still viewable, just only as a
     * look-but-don't-touch preview (see isPreviewing). Single source of
     * truth for this bypass, also used by dayProgress()'s 'locked' flag.
     */
    public function getReachableStepKeysProperty(): array
    {
        if ($this->missionLocked) {
            return [];
        }

        if ($this->run->learner->bypassesEvidenceGating()) {
            return $this->stepKeys;
        }

        if ($this->currentStepKey === null) {
            return $this->stepKeys;
        }

        $currentIndex = array_search($this->currentStepKey, $this->stepKeys, true);

        return array_slice($this->stepKeys, 0, $currentIndex + 1);
    }

    /**
     * Show the day overview instead of a step form when: the learner
     * explicitly asked for it (?step=overview), the mission is fully done,
     * or they've just arrived at a day they haven't started yet. Mid-day,
     * a bare visit goes straight to the current step — only the boundary
     * between days is a deliberate checkpoint.
     */
    public function getShowOverviewProperty(): bool
    {
        if ($this->viewStep === 'overview') {
            return true;
        }

        if ($this->viewStep && in_array($this->viewStep, $this->stepKeys, true)) {
            return false;
        }

        return $this->currentStepKey === null || $this->run->isAtTheStartOfAFreshDay();
    }

    /**
     * The step actually being displayed: the requested ?step= if it is any
     * real step of this mission (reached or not — see isPreviewing),
     * otherwise the current step.
     */
    public function getActiveStepKeyProperty(): ?string
    {
        if ($this->viewStep && in_array($this->viewStep, $this->stepKeys, true)) {
            return $this->viewStep;
        }

        return $this->currentStepKey;
    }

    /**
     * True when the displayed step is one the learner hasn't reached yet:
     * they get the real screen, but read-only and with every action
     * refused server-side (see PreviewsStep) — so they can see what's
     * coming without being able to do it or skip ahead.
     */
    public function getIsPreviewingProperty(): bool
    {
        return $this->activeStepKey !== null
            && ! in_array($this->activeStepKey, $this->reachableStepKeys, true);
    }

    /**
     * Whether a finished day offers "Practice with a friend" — only for
     * someone with a mutual friend to practise with.
     */
    public function getHasPracticeFriendProperty(): bool
    {
        return ! $this->missionLocked && auth()->user()->mutualFriends()->isNotEmpty();
    }

    /**
     * Where the learner actually is — day number, day label and step label
     * of the current step — for the "you are here" cue shown while they're
     * looking at a step ahead of it.
     *
     * @return ?array{dayNumber: int, dayLabel: string, stepKey: string, stepLabel: string}
     */
    public function getCurrentPositionProperty(): ?array
    {
        if ($this->missionLocked || $this->currentStepKey === null) {
            return null;
        }

        foreach ($this->run->dayProgress() as $index => $day) {
            if (in_array($this->currentStepKey, $day['stepKeys'], true)) {
                return [
                    'dayNumber' => $index + 1,
                    'dayLabel' => $day['label'],
                    'stepKey' => $this->currentStepKey,
                    'stepLabel' => $this->mission->stepLabel($this->currentStepKey),
                ];
            }
        }

        return null;
    }

    /**
     * A step is being reviewed (read-only) when it already has recorded
     * Evidence — not merely when it isn't the literal current step. Under
     * normal locking the two are identical (reachableStepKeys only ever
     * contains done steps + the current one), but if that ever changes —
     * e.g. a future or not-yet-done step becomes reachable some other way —
     * it must still render as live/editable, not as an inert "already
     * reviewed" shell. ?retry=1 overrides this entirely — see $retry.
     */
    public function getIsReviewingProperty(): bool
    {
        if ($this->retry) {
            return false;
        }

        return $this->activeStepKey !== null && $this->run->latestEvidence($this->activeStepKey) !== null;
    }

    /**
     * The day (phase) containing the active step, and that day's own step
     * list — navigation stays inside one day at a time; crossing into the
     * next one always goes through the overview.
     */
    public function getActiveDayProperty(): ?array
    {
        if (! $this->activeStepKey) {
            return null;
        }

        foreach ($this->run->dayProgress() as $day) {
            if (in_array($this->activeStepKey, $day['stepKeys'], true)) {
                return $day;
            }
        }

        return null;
    }

    /**
     * 0-based position of the active day among all of the mission's days —
     * shown at the top of the page ("Day 2 · Build") so the learner always
     * knows where they are without having to scroll into the step list.
     */
    public function getActiveDayIndexProperty(): ?int
    {
        if (! $this->activeStepKey) {
            return null;
        }

        foreach ($this->run->dayProgress() as $index => $day) {
            if (in_array($this->activeStepKey, $day['stepKeys'], true)) {
                return $index;
            }
        }

        return null;
    }

    public function getNextStepKeyProperty(): ?string
    {
        $daySteps = $this->activeDay['stepKeys'] ?? [];
        $index = array_search($this->activeStepKey, $daySteps, true);
        $next = $daySteps[$index + 1] ?? null;

        // Live: never offer a step you can't do yet. Previewing: walking
        // forward through what's coming is the whole point.
        if ($this->isPreviewing) {
            return $next;
        }

        return $next && in_array($next, $this->reachableStepKeys, true) ? $next : null;
    }

    /**
     * Step keys with a real screen built. Anything else falls back to a
     * "not built yet" placeholder — see EOS-009 §7 for the full list.
     */
    protected function stepComponents(): array
    {
        return [
            'mission_brief' => 'missions.steps.mission-brief',
            'vocabulary_builder_1' => 'missions.steps.vocabulary-builder',
            'vocabulary_builder_2' => 'missions.steps.vocabulary-builder',
            'vocabulary_builder_3' => 'missions.steps.vocabulary-builder',
            'listening' => 'missions.steps.listening',
            'daily_listen_2' => 'missions.steps.daily-listen-2',
            'grammar_in_context' => 'missions.steps.grammar-in-context',
            'story_sequence' => 'missions.steps.story-sequence',
            'video_shadowing' => 'missions.steps.video-shadowing',
            'daily_listen_3' => 'missions.steps.daily-listen-3',
            'ai_conversation_1' => 'missions.steps.ai-conversation1',
            'picture_description' => 'missions.steps.picture-description',
            'reading_comprehension' => 'missions.steps.reading-comprehension',
            'writing' => 'missions.steps.writing',
            'error_log' => 'missions.steps.error-log',
            'ai_conversation_2' => 'missions.steps.ai-conversation2',
            'mission_result' => 'missions.steps.mission-result',
            'partner_speaking_session' => 'missions.steps.partner-speaking-session',
        ];
    }

    public function getStepComponentProperty(): ?string
    {
        return $this->stepComponents()[$this->activeStepKey] ?? null;
    }

    /**
     * A small visual cue for what kind of activity a step is — matched by
     * key prefix so it keeps working for future missions' ai_conversation_3
     * etc. without a new lookup entry each time. Returns a Heroicons name
     * (see resources/views/components — the app's icon catalog is
     * Heroicons throughout, via blade-ui-kit/blade-heroicons) for use with
     * the @svg() Blade directive, e.g. @svg($this->stepIcon($key), '...').
     */
    /**
     * A silent, looping background clip for the mission hero panel below —
     * purely decorative/mood-setting (see <x-ambient-video>'s own
     * docblock), NOT tied to any specific grammar point or listening
     * source. Sourced from Mission Brief's own content since it's a
     * mission-wide (not per-step) decoration. Fails soft (null) on no
     * query/no key/any error, same as every other PexelsClient call.
     */
    public function heroVideoUrl(): ?string
    {
        $query = $this->mission->stepContent('mission_brief')['ambient_video_query'] ?? null;

        if (! $query) {
            return null;
        }

        return app(PexelsClient::class)->videoUrlFor($this->mission->code.'-ambient', $query);
    }

    public function stepIcon(string $key): string
    {
        return match (true) {
            $key === 'mission_brief' => 'heroicon-o-rocket-launch',
            str_starts_with($key, 'vocabulary_builder') => 'heroicon-o-book-open',
            $key === 'listening' => 'heroicon-o-speaker-wave',
            str_starts_with($key, 'daily_listen') => 'heroicon-o-speaker-wave',
            $key === 'grammar_in_context' => 'heroicon-o-pencil',
            $key === 'story_sequence' => 'heroicon-o-photo',
            $key === 'video_shadowing' => 'heroicon-o-video-camera',
            str_starts_with($key, 'ai_conversation') => 'heroicon-o-chat-bubble-left-right',
            str_starts_with($key, 'ai_feedback') => 'heroicon-o-light-bulb',
            $key === 'picture_description' => 'heroicon-o-photo',
            $key === 'reading_comprehension' => 'heroicon-o-newspaper',
            $key === 'writing' => 'heroicon-o-pencil-square',
            $key === 'error_log' => 'heroicon-o-magnifying-glass',
            $key === 'mission_result' => 'heroicon-o-flag',
            $key === 'partner_speaking_session' => 'heroicon-o-user-group',
            default => 'heroicon-o-map-pin',
        };
    }
};
?>

@php
    $isStepView = ! $this->showOverview && $this->activeStepKey;
@endphp

<div class="mx-auto max-w-2xl {{ $isStepView ? 'space-y-4 px-4 pb-6 sm:px-6' : 'space-y-6 p-6' }}" data-mood="{{ $mission->moodKey() }}">
    @unless ($isStepView)
        {{-- The mission's photo banner only on its page (the same as
             Listening and Daily talk) — inside a step the one-line header
             below says where the learner is, so the step's own content
             starts at the top of the screen. The mission's ambient clip, if
             it has one, fades in over the photo. --}}
        <x-mission-banner
            :mission-code="$mission->code"
            :title="$mission->title"
            :back-href="route('missions.index')"
            back-label="Missions"
            :done-label="$this->isFinished ? 'Done' : null"
        >
            @if ($videoUrl = $this->heroVideoUrl())
                <x-slot:media>
                    <x-ambient-video :url="$videoUrl" />
                </x-slot:media>
            @endif
            <p class="mt-0.5 text-sm text-white/80">{{ $mission->code }}{{ $mission->outcome ? ' · '.$mission->outcome : '' }}</p>
        </x-mission-banner>
    @endunless

    @if ($this->showOverview)
        @if ($this->missionLocked)
            <x-missions.locked-notice :gating-mission="$this->gatingMission" />
        @endif

        {{-- A3: the four days as buttons side by side (done = green ✓,
             the learner's day selected), and under them the chosen day's
             three cards — Lesson (steps · time + its button), Listening,
             Daily talk — then "Practice this day with a friend". --}}
        @php
            $openDay = $this->openDayNumber ?? 1;
            $hasPracticeFriend = $this->hasPracticeFriend;
            $card = 'flex min-h-16 items-center gap-3 rounded-2xl p-3';
        @endphp
        <div x-data="{ openDay: @js($openDay) }" class="space-y-2.5">
            <div class="grid grid-cols-4 gap-2" role="tablist" aria-label="Days">
                @foreach ($this->missionDays as $day)
                    @php $allDone = $day['lesson'] && $day['listened'] && $day['talked']; @endphp
                    <button
                        type="button"
                        role="tab"
                        wire:key="mission-day-tab-{{ $day['number'] }}"
                        x-on:click="openDay = {{ $day['number'] }}"
                        x-bind:aria-selected="openDay === {{ $day['number'] }}"
                        aria-label="Day {{ $day['number'] }}{{ $day['here'] ? ', today' : '' }}{{ $allDone ? ', done' : '' }}"
                        class="flex cursor-pointer flex-col items-center rounded-2xl py-2 text-base font-extrabold transition-colors"
                        x-bind:class="openDay === {{ $day['number'] }} ? 'bg-accent text-white dark:bg-accent-dark' : @js($day['lesson'] ? 'bg-surface text-success dark:bg-surface-dark dark:text-success-dark' : 'bg-surface text-ink-soft dark:bg-surface-dark dark:text-ink-soft-dark')"
                    >
                        <span>{{ $day['lesson'] ? '✓' : $day['number'] }}</span>
                        <span class="text-[11px] font-bold opacity-80">{{ $day['here'] ? 'Today' : 'Day '.$day['number'] }}</span>
                    </button>
                @endforeach
            </div>

            @foreach ($this->missionDays as $day)
                @php
                    $number = $day['number'];
                    $lessonNote = $day['lesson'] ? 'Done · tap to review' : ($day['preview'] ? 'Preview' : $day['stepCount'].' '.Str::plural('step', $day['stepCount']).($day['estimatedMinutes'] ? ' · ~'.Mission::formatDuration($day['estimatedMinutes']) : ''));
                    $parts = [
                        ['key' => 'listening', 'done' => $day['listened'], 'url' => $day['listeningUrl'], 'label' => 'Listening', 'icon' => 'speaker-wave', 'note' => $day['ahead'] ? 'Preview' : 'Pick one'],
                        ['key' => 'talk', 'done' => $day['talked'], 'url' => $day['talkUrl'], 'label' => 'Daily talk', 'icon' => 'microphone', 'note' => $day['ahead'] ? 'Preview' : 'with Pi'],
                    ];
                @endphp
                <div
                    wire:key="mission-day-{{ $mission->code }}-{{ $number }}"
                    id="mission-day-{{ $number }}"
                    x-show="openDay === {{ $number }}"
                    @if ($openDay !== $number) x-cloak @endif
                    class="space-y-2"
                >
                    <{{ $day['lessonUrl'] ? 'a' : 'div' }}
                        @if ($day['lessonUrl']) href="{{ $day['lessonUrl'] }}" wire:navigate @endif
                        aria-label="Day {{ $number }} Lesson{{ $day['lesson'] ? ', done' : '' }}"
                        class="{{ $card }} bg-accent-soft dark:bg-accent-soft-dark"
                    >
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-accent text-white dark:bg-accent-dark">@svg('heroicon-o-book-open', 'h-5 w-5')</span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-base font-extrabold text-ink dark:text-ink-dark">Lesson</span>
                            <span class="block truncate text-xs font-bold text-accent-ink dark:text-accent-ink-dark">{{ $lessonNote }}</span>
                        </span>
                        @if ($day['lesson'])
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-success text-white dark:bg-success-dark">@svg('heroicon-s-check', 'h-4 w-4')</span>
                        @elseif ($day['lessonUrl'] && ! $day['preview'])
                            <span class="shrink-0 rounded-full bg-accent px-4 py-2 text-sm font-extrabold text-white dark:bg-accent-dark">{{ $day['started'] ? 'Continue' : 'Start' }}</span>
                        @endif
                    </{{ $day['lessonUrl'] ? 'a' : 'div' }}>

                    @foreach ($parts as $part)
                        <{{ $part['url'] ? 'a' : 'div' }}
                            @if ($part['url']) href="{{ $part['url'] }}" wire:navigate @endif
                            aria-label="Day {{ $number }} {{ $part['label'] }}{{ $part['done'] ? ', done' : '' }}"
                            class="{{ $card }} bg-surface dark:bg-surface-dark"
                        >
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-surface-sunken text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark">@svg('heroicon-o-'.$part['icon'], 'h-5 w-5')</span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-base font-extrabold text-ink dark:text-ink-dark">{{ $part['label'] }}</span>
                                <span class="block text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">{{ $part['note'] }}</span>
                            </span>
                            @if ($part['done'])
                                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-success text-white dark:bg-success-dark">@svg('heroicon-s-check', 'h-4 w-4')</span>
                            @elseif ($part['url'])
                                @svg('heroicon-o-chevron-right', 'h-5 w-5 shrink-0 text-ink-faint dark:text-ink-faint-dark')
                            @endif
                        </{{ $part['url'] ? 'a' : 'div' }}>
                    @endforeach

                    @if ($day['lesson'] && $hasPracticeFriend)
                        <a href="{{ route('pair-practice.index', ['day' => $mission->code.'-'.$number]) }}" wire:navigate class="block px-2 py-1 text-sm font-bold text-ink-soft hover:text-ink dark:text-ink-soft-dark dark:hover:text-ink-dark">👫 Practice this day with a friend ›</a>
                    @endif
                </div>
            @endforeach
        </div>

        @if ($this->isFinished)
            <livewire:progress.mission :mission="$mission" :key="'recap-'.$mission->code" />
        @endif
    @elseif ($this->activeStepKey)
        @php
            $daySteps = $this->activeDay['stepKeys'] ?? [];
        @endphp
        <x-missions.step-bar
            :back-url="route('missions.show', [$mission, 'overview', 'day' => $this->activeDayIndex + 1])"
            :mission-code="$mission->code"
            :mission-title="$mission->title"
            :day-number="$this->activeDayIndex + 1"
            :day-heading="\App\Models\Mission::dayHeading($this->activeDayIndex + 1, $this->activeDay['label'] ?? '')"
            :step-label="$mission->stepLabel($this->activeStepKey)"
            :ask-instructor="! $this->isPreviewing"
            :steps="collect($daySteps)->map(fn ($key) => [
                'label' => $mission->stepLabel($key),
                'icon' => $this->stepIcon($key),
                'url' => route('missions.show', [$mission, $key]),
                'state' => $key === $this->activeStepKey
                    ? 'active'
                    : ($key !== $this->currentStepKey && in_array($key, $this->reachableStepKeys, true) ? 'done' : 'ahead'),
                'finished' => $key !== $this->currentStepKey && in_array($key, $this->reachableStepKeys, true),
                'here' => $key === $this->currentStepKey && $key !== $this->activeStepKey && ! $this->missionLocked,
                'minutes' => $mission->stepDuration($key),
            ])->all()"
        />

        @if ($this->isPreviewing)
            @if ($this->missionLocked && $this->gatingMission)
                <x-preview-pill
                    :label="'Preview only · unlocks after '.$this->gatingMission->code"
                    :href="route('missions.show', [$this->gatingMission, 'overview'])"
                />
            @elseif ($this->currentPosition)
                <x-preview-pill
                    label="Preview only · Go to where I am"
                    :href="route('missions.show', [$mission, $this->currentPosition['stepKey']])"
                />
            @else
                <x-preview-pill label="Preview only" />
            @endif
        @endif

        <div data-step-card>
            @if ($this->isReviewing)
                <div class="mb-4 flex items-center justify-between gap-2">
                    <span class="inline-flex items-center gap-1 text-xs font-semibold text-success dark:text-success-dark">
                        @svg('heroicon-s-check-circle', 'h-4 w-4') Done
                    </span>
                    @if (Mission::isStepRedoable($this->activeStepKey))
                        <a
                            href="{{ route('missions.show', [$mission, $this->activeStepKey, 'retry' => 1]) }}"
                            wire:navigate
                            class="inline-flex cursor-pointer items-center gap-1 rounded-full border border-line px-3 py-1 text-xs font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                        >@svg('heroicon-o-arrow-path', 'h-3.5 w-3.5') Practice again</a>
                    @endif
                </div>
            @endif

            @if ($this->stepComponent)
                @php
                    // vocabulary_builder_1/2/3 all share one component (see
                    // stepComponents() above) and need to know which day
                    // they're rendering — every other step still derives
                    // everything it needs from $run alone.
                    $extraProps = str_starts_with($this->activeStepKey, 'vocabulary_builder_')
                        ? ['stepKey' => $this->activeStepKey]
                        : [];
                @endphp
                @livewire($this->stepComponent, ['run' => $run, 'readOnly' => $this->isReviewing || $this->isPreviewing, 'preview' => $this->isPreviewing, ...$extraProps], key($run->id.'-'.$this->activeStepKey.'-'.($this->isPreviewing ? 'pv' : ($this->isReviewing ? 'ro' : 'live'))))
            @else
                <p class="text-sm text-ink-faint dark:text-ink-faint-dark">Step screen not built yet.</p>
            @endif
        </div>

        {{-- A finished or previewed step has no Continue of its own, so the
             same full-width spot walks on through the day instead. --}}
        @if ($this->isReviewing || $this->isPreviewing)
            <a
                href="{{ $this->nextStepKey ? route('missions.show', [$mission, $this->nextStepKey]) : route('missions.show', [$mission, 'overview', 'day' => $this->activeDayIndex + 1]) }}"
                wire:navigate
                class="block w-full cursor-pointer rounded-2xl bg-ink py-3.5 text-center text-base font-bold text-ground transition-opacity hover:opacity-90 dark:bg-ink-dark dark:text-ground-dark"
            >{{ $this->nextStepKey ? 'Next step' : 'Back to all days' }}</a>
        @endif

        @unless ($this->isPreviewing)
            <div>
                @livewire('missions.ask-instructor', ['run' => $run, 'stepKey' => $this->activeStepKey], key('ask-instructor-'.$run->id.'-'.$this->activeStepKey))
            </div>
        @endunless

    @endif
</div>
