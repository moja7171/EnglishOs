<?php

use App\Models\Mission;
use App\Models\MissionRun;
use Livewire\Component;

new class extends Component
{
    public Mission $mission;

    public MissionRun $run;

    public ?string $viewStep = null;

    public function mount(Mission $mission, ?string $step = null): void
    {
        $this->mission = $mission;
        $this->run = MissionRun::findOrStart(auth()->user(), $mission);
        $this->viewStep = $step;
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
     * Steps the learner has already reached — done steps plus the current
     * one. Evidence Before Progress (EOS-003 §7) still applies: you can
     * look back at any of these, but never jump ahead of the current step.
     *
     * TEMPORARY — testing only: MissionRun::TESTING_UNLOCK_ALL_STEPS
     * bypasses this entirely while true. Revert it there (single source
     * of truth — also used by dayProgress()'s 'locked' flag below).
     */
    public function getReachableStepKeysProperty(): array
    {
        if (MissionRun::TESTING_UNLOCK_ALL_STEPS) {
            return $this->stepKeys;
        }

        if ($this->currentStepKey === null) {
            return $this->stepKeys;
        }

        $currentIndex = array_search($this->currentStepKey, $this->stepKeys, true);

        return array_slice($this->stepKeys, 0, $currentIndex + 1);
    }

    /**
     * Show the 3-day overview instead of a step form when: the learner
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

        if ($this->viewStep && in_array($this->viewStep, $this->reachableStepKeys, true)) {
            return false;
        }

        return $this->currentStepKey === null || $this->run->isAtTheStartOfAFreshDay();
    }

    /**
     * The step actually being displayed: the requested ?step=, if it's
     * somewhere the learner has already reached, otherwise the current step.
     */
    public function getActiveStepKeyProperty(): ?string
    {
        if ($this->viewStep && in_array($this->viewStep, $this->reachableStepKeys, true)) {
            return $this->viewStep;
        }

        return $this->currentStepKey;
    }

    /**
     * A step is being reviewed (read-only) when it already has recorded
     * Evidence — not merely when it isn't the literal current step. Under
     * normal locking the two are identical (reachableStepKeys only ever
     * contains done steps + the current one), but if that ever changes —
     * e.g. a future or not-yet-done step becomes reachable some other way —
     * it must still render as live/editable, not as an inert "already
     * reviewed" shell.
     */
    public function getIsReviewingProperty(): bool
    {
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

    public function getPreviousStepKeyProperty(): ?string
    {
        $daySteps = $this->activeDay['stepKeys'] ?? [];
        $index = array_search($this->activeStepKey, $daySteps, true);

        return $index > 0 ? $daySteps[$index - 1] : null;
    }

    public function getNextStepKeyProperty(): ?string
    {
        $daySteps = $this->activeDay['stepKeys'] ?? [];
        $index = array_search($this->activeStepKey, $daySteps, true);
        $next = $daySteps[$index + 1] ?? null;

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
            'vocabulary_builder' => 'missions.steps.vocabulary-builder',
            'listening' => 'missions.steps.listening',
            'grammar_in_context' => 'missions.steps.grammar-in-context',
            'activation' => 'missions.steps.activation',
            'ai_conversation_1' => 'missions.steps.ai-conversation1',
            'ai_feedback_1' => 'missions.steps.ai-feedback1',
            'writing' => 'missions.steps.writing',
            'ai_conversation_2' => 'missions.steps.ai-conversation2',
            'active_recall' => 'missions.steps.active-recall',
            'error_log' => 'missions.steps.error-log',
            'mission_result' => 'missions.steps.mission-result',
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
    public function stepIcon(string $key): string
    {
        return match (true) {
            $key === 'mission_brief' => 'heroicon-o-rocket-launch',
            $key === 'vocabulary_builder' => 'heroicon-o-book-open',
            $key === 'listening' => 'heroicon-o-speaker-wave',
            $key === 'grammar_in_context' => 'heroicon-o-pencil',
            $key === 'activation' => 'heroicon-o-microphone',
            str_starts_with($key, 'ai_conversation') => 'heroicon-o-chat-bubble-left-right',
            str_starts_with($key, 'ai_feedback') => 'heroicon-o-light-bulb',
            $key === 'writing' => 'heroicon-o-pencil-square',
            $key === 'active_recall' => 'heroicon-o-arrow-path',
            $key === 'error_log' => 'heroicon-o-magnifying-glass',
            $key === 'mission_result' => 'heroicon-o-flag',
            default => 'heroicon-o-map-pin',
        };
    }
};
?>

<div class="mx-auto max-w-2xl space-y-6 p-6" data-mood="{{ $mission->moodKey() }}">
    <div class="relative isolate overflow-hidden rounded-3xl bg-linear-to-br from-hero to-hero-2 p-8 text-white sm:p-9">
        <div class="pointer-events-none absolute -top-24 -right-10 -z-10 h-72 w-72 rounded-full bg-dawn opacity-40 blur-3xl"></div>
        <div class="pointer-events-none absolute -bottom-28 -left-10 -z-10 h-60 w-60 rounded-full bg-dusk opacity-30 blur-3xl"></div>
        <p class="inline-flex items-center gap-1.5 text-xs font-bold tracking-widest text-white/70 uppercase">
            <span class="h-1.5 w-1.5 rounded-full bg-accent"></span>
            {{ $mission->code }}
        </p>
        <h1 class="mt-3 max-w-[16ch] font-display text-3xl font-semibold text-balance">{{ $mission->title }}</h1>
        <p class="mt-2 max-w-[46ch] text-sm text-white/75">{{ $mission->outcome }}</p>
    </div>

    @if ($this->showOverview && $this->currentStepKey !== null)
        {{-- 3-day mission overview, styled as a journey path --}}
        <div class="relative pl-11">
            <div class="absolute top-5 bottom-5 left-[18px] w-0.5 bg-line dark:bg-line-dark"></div>

            @foreach ($run->dayProgress() as $index => $day)
                @php
                    $entryStep = $day['done']
                        ? $day['stepKeys'][0]
                        : ($day['current']
                            ? $this->currentStepKey
                            : (MissionRun::TESTING_UNLOCK_ALL_STEPS ? $day['stepKeys'][0] : null));
                @endphp
                <div class="relative mb-3.5">
                    <div class="absolute top-3.5 -left-11 flex h-9 w-9 items-center justify-center rounded-full border-2 font-display text-sm font-semibold
                        {{ $day['done']
                            ? 'border-success bg-success text-white dark:border-success-dark dark:bg-success-dark'
                            : ($day['current']
                                ? 'border-accent bg-accent text-white dark:border-accent-dark dark:bg-accent-dark'
                                : 'border-line bg-ground text-ink-faint dark:border-line-dark dark:bg-ground-dark dark:text-ink-faint-dark') }}">
                        @if ($day['done'])
                            @svg('heroicon-o-check', 'h-4 w-4')
                        @else
                            {{ $index + 1 }}
                        @endif
                    </div>

                    <div class="rounded-2xl border bg-surface p-4.5 dark:bg-surface-dark
                        {{ $day['current'] ? 'border-accent dark:border-accent-dark' : 'border-line dark:border-line-dark' }}
                        {{ $day['locked'] ? 'opacity-55' : '' }}">
                        <div class="flex items-center justify-between gap-3">
                            <p class="text-xs font-bold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">
                                Day {{ $index + 1 }} · {{ $day['label'] }}
                            </p>
                            @if ($day['done'])
                                <span class="text-xs font-semibold text-success dark:text-success-dark">Completed {{ $day['completedAt']->format('M j') }}</span>
                            @elseif ($day['startedAt'])
                                <span class="text-xs text-ink-faint dark:text-ink-faint-dark">Started {{ $day['startedAt']->format('M j') }}</span>
                            @endif
                        </div>
                        <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">
                            {{ collect($day['stepKeys'])->map(fn ($k) => $mission->stepLabel($k))->implode(' · ') }}
                        </p>

                        @if ($entryStep)
                            <a
                                href="{{ route('missions.show', [$mission, $entryStep]) }}"
                                wire:navigate
                                class="mt-3 inline-flex cursor-pointer items-center gap-1 text-xs font-bold text-accent-ink transition-colors hover:opacity-80 dark:text-accent-ink-dark"
                            >{{ $day['done'] ? 'Review' : 'Continue' }} @svg('heroicon-o-chevron-right', 'h-3 w-3')</a>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @elseif ($this->activeStepKey)
        @php
            $daySteps = $this->activeDay['stepKeys'] ?? [];
            $position = array_search($this->activeStepKey, $daySteps, true) + 1;
        @endphp
        <div class="flex items-center justify-between">
            <a
                href="{{ route('missions.show', [$mission, 'overview']) }}"
                wire:navigate
                class="inline-flex cursor-pointer items-center gap-1 rounded-full border border-line px-3 py-1.5 text-xs font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
            >
                @svg('heroicon-o-chevron-left', 'h-3.5 w-3.5')
                All Days
            </a>
            <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">
                Day {{ $this->activeDayIndex + 1 }} · {{ $this->activeDay['label'] ?? '' }}
            </p>
        </div>

        {{-- Vertical checklist, scoped to this day only --}}
        <nav class="space-y-1">
            @foreach ($daySteps as $key)
                @php
                    $done = $key !== $this->currentStepKey && in_array($key, $this->reachableStepKeys, true);
                    $active = $key === $this->activeStepKey;
                    $reachable = in_array($key, $this->reachableStepKeys, true);
                @endphp
                @if ($reachable)
                    <a
                        href="{{ route('missions.show', [$mission, $key]) }}"
                        wire:navigate
                        class="flex items-center gap-3 rounded-xl border px-3.5 py-2.5 text-sm transition-colors
                            {{ $active ? 'border-accent bg-accent text-white dark:border-accent-dark dark:bg-accent-dark' : 'border-line dark:border-line-dark' }}
                            {{ ! $active && $done ? 'text-success dark:text-success-dark' : '' }}
                            {{ ! $active && ! $done ? 'text-ink-soft dark:text-ink-soft-dark' : '' }}"
                    >
                        @svg($this->stepIcon($key), 'h-4 w-4 shrink-0')
                        <span class="flex-1 {{ $active ? 'font-semibold' : '' }}">{{ $mission->stepLabel($key) }}</span>
                        @if ($done && ! $active)
                            @svg('heroicon-o-check-circle', 'h-4 w-4 shrink-0')
                        @endif
                    </a>
                @else
                    <span
                        class="flex items-center gap-3 rounded-xl border border-line/60 px-3.5 py-2.5 text-sm text-ink-faint/70 dark:border-line-dark/60 dark:text-ink-faint-dark/70"
                    >
                        @svg($this->stepIcon($key), 'h-4 w-4 shrink-0 opacity-40')
                        <span class="flex-1">{{ $mission->stepLabel($key) }}</span>
                        @svg('heroicon-o-lock-closed', 'h-3.5 w-3.5 shrink-0')
                    </span>
                @endif
            @endforeach
        </nav>

        <div class="rounded-2xl border border-line bg-surface p-5 dark:border-line-dark dark:bg-surface-dark">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">
                    Step {{ $position }} of {{ count($daySteps) }}
                </p>
                @if ($this->isReviewing)
                    <span class="rounded-full bg-surface-sunken px-2.5 py-0.5 text-xs text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark">
                        Reviewing a completed step
                    </span>
                @endif
            </div>
            <h2 class="mt-1 font-display text-lg font-semibold">{{ $mission->stepLabel($this->activeStepKey) }}</h2>

            @if ($this->stepComponent)
                <div class="mt-4">
                    @livewire($this->stepComponent, ['run' => $run, 'readOnly' => $this->isReviewing], key($run->id.'-'.$this->activeStepKey.'-'.($this->isReviewing ? 'ro' : 'live')))
                </div>
            @else
                <p class="mt-2 text-sm text-ink-faint dark:text-ink-faint-dark">Step screen not built yet.</p>
            @endif
        </div>

        <div class="flex items-center justify-between text-sm">
            @if ($this->previousStepKey)
                <a
                    href="{{ route('missions.show', [$mission, $this->previousStepKey]) }}"
                    wire:navigate
                    class="inline-flex cursor-pointer items-center gap-1 rounded-full border border-line px-3 py-1.5 text-xs font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                >
                    @svg('heroicon-o-chevron-left', 'h-3.5 w-3.5')
                    Previous
                </a>
            @else
                <span></span>
            @endif

            @if ($this->nextStepKey)
                <a
                    href="{{ route('missions.show', [$mission, $this->nextStepKey]) }}"
                    wire:navigate
                    class="inline-flex cursor-pointer items-center gap-1 rounded-full bg-ink px-3 py-1.5 text-xs font-semibold text-ground transition-colors hover:opacity-85 dark:bg-ink-dark dark:text-ground-dark"
                >
                    Next
                    @svg('heroicon-o-chevron-right', 'h-3.5 w-3.5')
                </a>
            @elseif ($this->isReviewing)
                <a
                    href="{{ route('missions.show', [$mission, 'overview']) }}"
                    wire:navigate
                    class="inline-flex cursor-pointer items-center gap-1 rounded-full bg-ink px-3 py-1.5 text-xs font-semibold text-ground transition-colors hover:opacity-85 dark:bg-ink-dark dark:text-ground-dark"
                >
                    Back to all days
                    @svg('heroicon-o-chevron-right', 'h-3.5 w-3.5')
                </a>
            @endif
        </div>
    @else
        <article class="rounded-2xl border-2 border-ink p-5 dark:border-ink-dark">
            <p class="text-xs font-semibold tracking-wide uppercase
                {{ $run->status === 'complete' ? 'text-success dark:text-success-dark' : ($run->status === 'needs_review' ? 'text-amber-600' : 'text-red-600') }}">
                Mission {{ str($run->status)->replace('_', ' ')->title() }}
            </p>
            <h2 class="mt-1 font-display text-lg font-semibold">{{ $mission->title }} — done</h2>
        </article>
    @endif
</div>
