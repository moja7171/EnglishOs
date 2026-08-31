<?php

use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use Livewire\Component;

new class extends Component
{
    public Mission $mission;

    public MissionRun $run;

    public function mount(Mission $mission): void
    {
        $this->mission = $mission;

        // TODO: replace with the authenticated learner once auth exists.
        $learner = User::first();

        $this->run = MissionRun::findOrStart($learner, $mission);
    }

    public function getCurrentStepKeyProperty(): ?string
    {
        return $this->run->currentStepKey();
    }
};
?>

<div class="mx-auto max-w-2xl space-y-6 p-6">
    <header class="border-b border-neutral-300 pb-4 dark:border-neutral-700">
        <p class="font-mono text-xs tracking-widest text-neutral-500 uppercase">{{ $mission->code }}</p>
        <h1 class="text-2xl font-extrabold">{{ $mission->title }}</h1>
        <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-400">{{ $mission->outcome }}</p>
    </header>

    @if ($this->currentStepKey)
        @php
            $stepKeys = $mission->stepKeys();
            $position = array_search($this->currentStepKey, $stepKeys, true) + 1;
            $phase = $mission->phaseFor($this->currentStepKey);
        @endphp
        <article class="rounded-lg border border-neutral-300 p-4 dark:border-neutral-700">
            <p class="font-mono text-xs text-neutral-500">
                {{ $phase['label'] ?? ucfirst($phase['phase'] ?? '') }} ·
                Step {{ $position }} of {{ count($stepKeys) }}
            </p>
            <h2 class="text-lg font-bold">{{ $mission->stepLabel($this->currentStepKey) }}</h2>
            <p class="mt-2 text-sm text-neutral-500">Step screen not built yet — coming in Epic 2.</p>
        </article>
    @else
        <article class="rounded-lg border border-neutral-300 p-4 dark:border-neutral-700">
            <h2 class="text-lg font-bold">All steps have evidence</h2>
            <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-400">Mission Result screen not built yet.</p>
        </article>
    @endif
</div>
