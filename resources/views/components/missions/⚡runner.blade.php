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
     */
    public function getReachableStepKeysProperty(): array
    {
        if ($this->currentStepKey === null) {
            return $this->stepKeys;
        }

        $currentIndex = array_search($this->currentStepKey, $this->stepKeys, true);

        return array_slice($this->stepKeys, 0, $currentIndex + 1);
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

        // Null here means the mission is fully complete and no specific
        // step was requested — the view falls back to the completion card.
        return $this->currentStepKey;
    }

    public function getIsReviewingProperty(): bool
    {
        return $this->activeStepKey !== $this->currentStepKey;
    }

    public function getPreviousStepKeyProperty(): ?string
    {
        $index = array_search($this->activeStepKey, $this->stepKeys, true);

        return $index > 0 ? $this->stepKeys[$index - 1] : null;
    }

    public function getNextStepKeyProperty(): ?string
    {
        $index = array_search($this->activeStepKey, $this->stepKeys, true);
        $next = $this->stepKeys[$index + 1] ?? null;

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
};
?>

<div class="mx-auto max-w-2xl space-y-6 p-6">
    <header class="border-b border-neutral-300 pb-4 dark:border-neutral-700">
        <p class="font-mono text-xs tracking-widest text-neutral-500 uppercase">{{ $mission->code }}</p>
        <h1 class="text-2xl font-extrabold">{{ $mission->title }}</h1>
        <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-400">{{ $mission->outcome }}</p>
    </header>

    {{-- Wizard stepper --}}
    <nav class="flex flex-wrap gap-1.5">
        @foreach ($this->stepKeys as $key)
            @php
                $done = $key !== $this->currentStepKey && in_array($key, $this->reachableStepKeys, true);
                $active = $key === $this->activeStepKey;
                $reachable = in_array($key, $this->reachableStepKeys, true);
            @endphp
            @if ($reachable)
                <a
                    href="{{ route('missions.show', [$mission, $key]) }}"
                    wire:navigate
                    title="{{ $mission->stepLabel($key) }}"
                    class="flex h-7 w-7 items-center justify-center rounded-full border text-xs font-semibold
                        {{ $active ? 'border-neutral-900 bg-neutral-900 text-white dark:border-white dark:bg-white dark:text-neutral-900' : '' }}
                        {{ ! $active && $done ? 'border-green-600 text-green-600' : '' }}
                        {{ ! $active && ! $done ? 'border-neutral-300 text-neutral-500 dark:border-neutral-700' : '' }}"
                >{{ $done && ! $active ? '✓' : $loop->iteration }}</a>
            @else
                <span
                    title="Not reached yet"
                    class="flex h-7 w-7 items-center justify-center rounded-full border border-neutral-200 text-xs text-neutral-300 dark:border-neutral-800 dark:text-neutral-700"
                >{{ $loop->iteration }}</span>
            @endif
        @endforeach
    </nav>

    @if ($this->activeStepKey)
        @php
            $position = array_search($this->activeStepKey, $this->stepKeys, true) + 1;
            $phase = $mission->phaseFor($this->activeStepKey);
        @endphp
        <div class="rounded-lg border border-neutral-300 p-4 dark:border-neutral-700">
            <div class="flex items-center justify-between">
                <p class="font-mono text-xs text-neutral-500">
                    {{ $phase['label'] ?? ucfirst($phase['phase'] ?? '') }} ·
                    Step {{ $position }} of {{ count($this->stepKeys) }}
                </p>
                @if ($this->isReviewing)
                    <span class="rounded bg-neutral-200 px-2 py-0.5 text-xs text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400">
                        Reviewing a completed step
                    </span>
                @endif
            </div>
            <h2 class="text-lg font-bold">{{ $mission->stepLabel($this->activeStepKey) }}</h2>

            @if ($this->stepComponent)
                <div class="mt-4">
                    @livewire($this->stepComponent, ['run' => $run, 'readOnly' => $this->isReviewing], key($run->id.'-'.$this->activeStepKey.'-'.($this->isReviewing ? 'ro' : 'live')))
                </div>
            @else
                <p class="mt-2 text-sm text-neutral-500">Step screen not built yet.</p>
            @endif
        </div>

        <div class="flex items-center justify-between text-sm">
            @if ($this->previousStepKey)
                <a href="{{ route('missions.show', [$mission, $this->previousStepKey]) }}" wire:navigate class="underline">‹ Previous</a>
            @else
                <span></span>
            @endif

            @if ($this->nextStepKey)
                <a href="{{ route('missions.show', [$mission, $this->nextStepKey]) }}" wire:navigate class="underline">Next ›</a>
            @elseif ($this->isReviewing)
                <a href="{{ route('missions.show', $mission) }}" wire:navigate class="underline">Back to current step ›</a>
            @endif
        </div>
    @else
        <article class="rounded-lg border-2 border-neutral-900 p-4 dark:border-white">
            <p class="text-xs font-semibold uppercase tracking-wide
                {{ $run->status === 'complete' ? 'text-green-600' : ($run->status === 'needs_review' ? 'text-amber-600' : 'text-red-600') }}">
                Mission {{ str($run->status)->replace('_', ' ')->title() }}
            </p>
            <h2 class="mt-1 text-lg font-bold">{{ $mission->title }} — done</h2>
        </article>
    @endif
</div>
