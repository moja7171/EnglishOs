<?php

use App\Models\Evidence;
use App\Models\MissionRun;
use Livewire\Component;

new class extends Component
{
    public MissionRun $run;

    public bool $readOnly = false;

    public ?int $score = null;

    public function mount(): void
    {
        if ($this->readOnly && $evidence = $this->run->latestEvidence('mission_brief')) {
            $this->score = (int) $evidence->content_ref;
        }
    }

    public function save(): void
    {
        $this->validate([
            'score' => 'required|integer|min:1|max:5',
        ]);

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'mission_brief',
            'type' => Evidence::TYPE_SCORE,
            'content_ref' => (string) $this->score,
        ]);

        $this->redirect(route('missions.show', $this->run->mission), navigate: true);
    }
};
?>

@php
    $brief = $run->mission->stepContent('mission_brief');
    $phases = $run->mission->phases ?? [];
    $totalSteps = $run->mission->stepKeys();
@endphp

<div class="space-y-6">
    <div class="rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
        <p class="font-display text-sm font-semibold text-ink dark:text-ink-dark">{{ $run->mission->outcome }}</p>
    </div>

    {{-- Roadmap: a short, visible journey rather than an open-ended form --}}
    <div class="flex flex-wrap items-center gap-1.5 text-xs text-ink-faint dark:text-ink-faint-dark">
        @foreach ($phases as $phase)
            <span class="rounded-full border border-line px-2.5 py-1 dark:border-line-dark">
                {{ $phase['label'] ?? ucfirst($phase['phase'] ?? '') }}
            </span>
            @if (! $loop->last)
                <span>@svg('heroicon-o-chevron-right', 'inline h-3 w-3')</span>
            @endif
        @endforeach
        <span class="ml-1">· {{ count($totalSteps) }} short steps</span>
    </div>

    <x-hook :text="$brief['hook'] ?? null" />

    <div>
        <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Before you start</p>
        <p class="mt-1 text-sm text-ink-faint dark:text-ink-faint-dark">Answer out loud, with no preparation.</p>
        <ul class="mt-3 space-y-2">
            @foreach ($brief['warm_up_questions'] ?? [] as $question)
                <li class="rounded-xl border border-line px-3 py-2 text-sm text-ink dark:border-line-dark dark:text-ink-dark">
                    {{ $question }}
                </li>
            @endforeach
        </ul>
    </div>

    <div>
        <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Starting score</p>
        <p class="text-sm text-ink-soft dark:text-ink-soft-dark">
            How comfortable am I talking about this topic right now?
        </p>
        <p class="text-xs text-ink-faint dark:text-ink-faint-dark">We'll compare this to your score at the end of the mission.</p>
        <div class="mt-2 flex gap-2">
            @foreach (range(1, 5) as $value)
                <button
                    type="button"
                    @disabled($readOnly)
                    wire:click="$set('score', {{ $value }})"
                    @class([
                        'h-10 w-10 cursor-pointer rounded-full border text-sm font-semibold transition-colors',
                        'border-accent bg-accent text-white dark:border-accent-dark dark:bg-accent-dark' => $score === $value,
                        'border-line text-ink-soft hover:border-ink-faint dark:border-line-dark dark:text-ink-soft-dark' => $score !== $value,
                    ])
                >{{ $value }}</button>
            @endforeach
        </div>
        @error('score')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    @unless ($readOnly)
        <button
            wire:click="save"
            class="cursor-pointer rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
        >
            Continue
        </button>
    @endunless
</div>
