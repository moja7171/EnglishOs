<?php

use App\Models\Evidence;
use App\Models\MissionRun;
use Livewire\Component;

new class extends Component
{
    public MissionRun $run;

    public bool $readOnly = false;

    /** @var array<int, string> */
    public array $frequencySentences = [];

    /** @var array<int, string> */
    public array $corrections = [];

    public function mount(): void
    {
        if (! $this->readOnly) {
            return;
        }

        $data = json_decode($this->run->latestEvidence('grammar_in_context')?->content_ref ?? '{}', true);
        $starters = $this->run->mission->stepContent('grammar_in_context')['frequency_starters'] ?? [];
        $savedSentences = collect($data['frequency_sentences'] ?? [])->keyBy('starter');

        foreach ($starters as $index => $starter) {
            $this->frequencySentences[$index] = $savedSentences[$starter]['completion'] ?? '';
        }

        $this->corrections = collect($data['corrections'] ?? [])->pluck('my_correction')->all();
    }

    public function save(): void
    {
        $starters = $this->run->mission->stepContent('grammar_in_context')['frequency_starters'] ?? [];
        $quickCheck = $this->run->mission->stepContent('grammar_in_context')['quick_check'] ?? [];

        $filledSentences = collect($this->frequencySentences)->filter(fn ($s) => trim((string) $s) !== '');
        if ($filledSentences->count() < 3) {
            $this->addError('frequencySentences', 'Complete at least 3 sentences before continuing.');

            return;
        }

        $filledCorrections = collect($this->corrections)->filter(fn ($c) => trim((string) $c) !== '');
        if ($filledCorrections->count() < count($quickCheck)) {
            $this->addError('corrections', 'Correct all the sentences before continuing.');

            return;
        }

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'grammar_in_context',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode([
                'frequency_sentences' => collect($starters)
                    ->map(fn ($starter, $i) => ['starter' => $starter, 'completion' => trim($this->frequencySentences[$i] ?? '')])
                    ->filter(fn ($s) => $s['completion'] !== '')
                    ->values(),
                'corrections' => collect($quickCheck)
                    ->map(fn ($item, $i) => [
                        'wrong' => $item['wrong'],
                        'my_correction' => trim($this->corrections[$i] ?? ''),
                        'correct' => $item['correct'],
                    ])
                    ->values(),
            ]),
        ]);

        $this->redirect(route('missions.show', $this->run->mission));
    }
};
?>

@php $grammar = $run->mission->stepContent('grammar_in_context'); @endphp

<div class="space-y-6">
    <p class="text-xs font-semibold tracking-wide text-neutral-500 uppercase">{{ $grammar['focus'] ?? 'Grammar' }}</p>

    <div>
        <p class="text-sm font-semibold">Make it personal</p>
        <p class="text-xs text-neutral-500">Finish at least 3 sentences about your own life.</p>
        <div class="mt-2 space-y-2">
            @foreach ($grammar['frequency_starters'] ?? [] as $index => $starter)
                <div class="flex items-center gap-2">
                    <span class="text-sm text-neutral-500">{{ $starter }}</span>
                    <input
                        type="text"
                        wire:model="frequencySentences.{{ $index }}"
                        @readonly($readOnly)
                        class="w-full rounded border border-neutral-300 bg-transparent px-2 py-1 text-sm dark:border-neutral-700"
                    >
                </div>
            @endforeach
        </div>
        @error('frequencySentences')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <p class="text-sm font-semibold">Quick check</p>
        <p class="text-xs text-neutral-500">Correct these sentences.</p>
        <div class="mt-2 space-y-3">
            @foreach ($grammar['quick_check'] ?? [] as $index => $item)
                <div>
                    <p class="text-sm text-neutral-500 line-through decoration-red-500">{{ $item['wrong'] }}</p>
                    <input
                        type="text"
                        wire:model="corrections.{{ $index }}"
                        placeholder="Correct it…"
                        @readonly($readOnly)
                        class="mt-1 w-full rounded border border-neutral-300 bg-transparent px-2 py-1 text-sm dark:border-neutral-700"
                    >
                </div>
            @endforeach
        </div>
        @error('corrections')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
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
