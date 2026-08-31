<?php

use App\Models\Evidence;
use App\Models\MissionRun;
use Livewire\Component;

new class extends Component
{
    public MissionRun $run;

    public bool $readOnly = false;

    /** @var array<int, string> */
    public array $gistPoints = ['', '', ''];

    /** @var array<int, string> */
    public array $expressionsHeard = ['', '', ''];

    public string $expressionMissed = '';

    public string $expressionToUse = '';

    public function mount(): void
    {
        if (! $this->readOnly) {
            return;
        }

        $data = json_decode($this->run->latestEvidence('listening')?->content_ref ?? '{}', true);

        $this->gistPoints = array_pad($data['gist_points'] ?? [], 3, '');
        $this->expressionsHeard = array_pad($data['expressions_heard'] ?? [], 3, '');
        $this->expressionMissed = $data['expression_missed'] ?? '';
        $this->expressionToUse = $data['expression_to_use'] ?? '';
    }

    public function save(): void
    {
        $gist = collect($this->gistPoints)->map(fn ($p) => trim($p))->filter();

        if ($gist->count() < 3) {
            $this->addError('gistPoints', 'Write all 3 things you understood before continuing.');

            return;
        }

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'listening',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode([
                'gist_points' => $gist->values(),
                'expressions_heard' => collect($this->expressionsHeard)->map(fn ($e) => trim($e))->filter()->values(),
                'expression_missed' => trim($this->expressionMissed),
                'expression_to_use' => trim($this->expressionToUse),
            ]),
        ]);

        $this->redirect(route('missions.show', $this->run->mission), navigate: true);
    }
};
?>

@php $listening = $run->mission->stepContent('listening'); @endphp

<div class="space-y-6">
    <x-hook :text="$listening['hook'] ?? null" />

    <div>
        <p class="text-xs font-semibold tracking-wide text-neutral-500 uppercase">{{ $listening['source'] ?? 'Listening' }}</p>
        @if (! empty($listening['audio_url']))
            <audio controls preload="none" class="mt-2 w-full">
                <source src="{{ $listening['audio_url'] }}" type="audio/mpeg">
            </audio>
        @endif
    </div>

    <div>
        <p class="text-sm font-semibold">First listening — gist</p>
        <p class="text-xs text-neutral-500">Listen without the transcript. What is the conversation about? Write 3 things you understood.</p>
        <div class="mt-2 space-y-2">
            @foreach ($gistPoints as $index => $point)
                <input
                    type="text"
                    wire:model="gistPoints.{{ $index }}"
                    placeholder="{{ $index + 1 }}."
                    @readonly($readOnly)
                    class="w-full rounded border border-neutral-300 bg-transparent px-2 py-1 text-sm dark:border-neutral-700"
                >
            @endforeach
        </div>
        @error('gistPoints')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <p class="text-sm font-semibold">Second listening — useful expressions</p>
        <p class="text-xs text-neutral-500">Three useful expressions you heard.</p>
        <div class="mt-2 space-y-2">
            @foreach ($expressionsHeard as $index => $expression)
                <input
                    type="text"
                    wire:model="expressionsHeard.{{ $index }}"
                    placeholder="{{ $index + 1 }}."
                    @readonly($readOnly)
                    class="w-full rounded border border-neutral-300 bg-transparent px-2 py-1 text-sm dark:border-neutral-700"
                >
            @endforeach
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <p class="text-sm font-semibold">One expression I missed</p>
            <input
                type="text"
                wire:model="expressionMissed"
                @readonly($readOnly)
                class="mt-2 w-full rounded border border-neutral-300 bg-transparent px-2 py-1 text-sm dark:border-neutral-700"
            >
        </div>
        <div>
            <p class="text-sm font-semibold">One expression I want to use</p>
            <input
                type="text"
                wire:model="expressionToUse"
                @readonly($readOnly)
                class="mt-2 w-full rounded border border-neutral-300 bg-transparent px-2 py-1 text-sm dark:border-neutral-700"
            >
        </div>
    </div>

    @unless ($readOnly)
        <button
            wire:click="save"
            class="rounded bg-neutral-900 px-4 py-2 text-sm font-semibold text-white dark:bg-white dark:text-neutral-900"
        >
            Continue
        </button>
    @endunless
</div>
