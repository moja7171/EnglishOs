<?php

use App\Models\Evidence;
use App\Models\MissionRun;
use Livewire\Component;

new class extends Component
{
    public MissionRun $run;

    public bool $readOnly = false;

    /** @var array<int, string> keyed by word index */
    public array $examples = [];

    public function mount(): void
    {
        if (! $this->readOnly) {
            return;
        }

        $evidence = $this->run->latestEvidence('vocabulary_builder');
        $saved = collect(json_decode($evidence?->content_ref ?? '[]', true))->keyBy('word');

        foreach ($this->run->mission->stepContent('vocabulary_builder')['vocabulary'] ?? [] as $index => $item) {
            $this->examples[$index] = $saved[$item['word']]['example'] ?? '';
        }
    }

    public function save(): void
    {
        $vocabulary = $this->run->mission->stepContent('vocabulary_builder')['vocabulary'] ?? [];

        $filled = collect($this->examples)
            ->filter(fn ($example) => trim((string) $example) !== '')
            ->map(fn ($example, $index) => [
                'word' => $vocabulary[$index]['word'] ?? null,
                'example' => trim($example),
            ])
            ->values();

        if ($filled->count() < 3) {
            $this->addError('examples', 'Write at least 3 personal examples before continuing.');

            return;
        }

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'vocabulary_builder',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => $filled->toJson(),
        ]);

        $this->redirect(route('missions.show', $this->run->mission));
    }
};
?>

<div class="space-y-6">
    <div>
        <p class="text-xs font-semibold tracking-wide text-neutral-500 uppercase">Choose expressions you'll really use</p>
        <p class="mt-1 text-sm text-neutral-500">Write at least 3 personal examples using these words.</p>
    </div>

    <div class="space-y-4">
        @foreach ($run->mission->stepContent('vocabulary_builder')['vocabulary'] ?? [] as $index => $item)
            <div class="rounded border border-neutral-300 p-3 dark:border-neutral-700">
                <p class="text-sm font-bold">{{ $item['word'] }}</p>
                <p class="text-xs text-neutral-500">{{ $item['meaning'] }}</p>
                <input
                    type="text"
                    wire:model="examples.{{ $index }}"
                    placeholder="My example…"
                    @readonly($readOnly)
                    class="mt-2 w-full rounded border border-neutral-300 bg-transparent px-2 py-1 text-sm dark:border-neutral-700"
                >
            </div>
        @endforeach
    </div>

    @error('examples')
        <p class="text-sm text-red-600">{{ $message }}</p>
    @enderror

    @unless ($readOnly)
        <button
            wire:click="save"
            class="rounded bg-neutral-900 px-4 py-2 text-sm font-semibold text-white dark:bg-white dark:text-neutral-900"
        >
            Continue
        </button>
    @endunless
</div>
