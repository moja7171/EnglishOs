<?php

use App\Models\Evidence;
use App\Models\MissionRun;
use Livewire\Component;

new class extends Component
{
    public MissionRun $run;

    public ?int $score = null;

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

        $this->redirect(route('missions.show', $this->run->mission));
    }
};
?>

<div class="space-y-6">
    <div class="rounded-lg border border-neutral-300 p-4 dark:border-neutral-700">
        <p class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ $run->mission->outcome }}</p>
    </div>

    <div>
        <p class="text-xs font-semibold tracking-wide text-neutral-500 uppercase">Before you start</p>
        <p class="mt-1 text-sm text-neutral-500">Answer out loud, with no preparation.</p>
        <ul class="mt-3 space-y-2">
            @foreach ($run->mission->stepContent('mission_brief')['warm_up_questions'] ?? [] as $question)
                <li class="rounded border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700">
                    {{ $question }}
                </li>
            @endforeach
        </ul>
    </div>

    <div>
        <p class="text-xs font-semibold tracking-wide text-neutral-500 uppercase">Starting score</p>
        <p class="text-sm text-neutral-600 dark:text-neutral-400">
            How comfortable am I talking about this topic right now?
        </p>
        <div class="mt-2 flex gap-2">
            @foreach (range(1, 5) as $value)
                <button
                    type="button"
                    wire:click="$set('score', {{ $value }})"
                    @class([
                        'h-10 w-10 rounded-full border text-sm font-semibold',
                        'border-neutral-900 bg-neutral-900 text-white dark:border-white dark:bg-white dark:text-neutral-900' => $score === $value,
                        'border-neutral-300 dark:border-neutral-700' => $score !== $value,
                    ])
                >{{ $value }}</button>
            @endforeach
        </div>
        @error('score')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    <button
        wire:click="save"
        class="rounded bg-neutral-900 px-4 py-2 text-sm font-semibold text-white dark:bg-white dark:text-neutral-900"
    >
        Continue
    </button>
</div>
