<?php

use App\Models\Evidence;
use App\Models\MissionRun;
use Livewire\Component;

new class extends Component
{
    public MissionRun $run;

    public bool $readOnly = false;

    /** @var array<string, array<int, string>> */
    public array $answers = [];

    public function mount(): void
    {
        $saved = $this->readOnly
            ? json_decode($this->run->latestEvidence('active_recall')?->content_ref ?? '{}', true)
            : [];

        foreach ($this->sections() as $section) {
            $this->answers[$section['key']] = array_pad($saved[$section['key']] ?? [], $section['count'], '');
        }
    }

    /**
     * The "expressions" section is sized and labelled against what the
     * learner actually picked in Vocabulary Builder (capped at 10, so a
     * learner who picked far more than 8 doesn't face an unreasonably long
     * recall list) — testing real memory of their own choices, not a
     * generic fixed count. Falls back to the seeded default if, somehow,
     * no vocabulary selection is on record yet.
     */
    private function sections(): array
    {
        $sections = $this->run->mission->stepContent('active_recall')['sections'] ?? [];
        $selected = $this->run->selectedVocabularyWords();

        if (! $selected) {
            return $sections;
        }

        $count = min(count($selected), 10);

        return collect($sections)
            ->map(fn (array $section) => $section['key'] === 'expressions'
                ? [
                    ...$section,
                    'count' => $count,
                    'label' => "Expressions I learned (you picked {$count} — how many can you recall without looking?)",
                ]
                : $section)
            ->all();
    }

    public function save(): void
    {
        $result = [];
        $missing = [];

        foreach ($this->sections() as $section) {
            $filled = collect($this->answers[$section['key']] ?? [])
                ->map(fn ($a) => trim($a))
                ->filter()
                ->values();

            if ($filled->isEmpty()) {
                $missing[] = $section['label'];
            }

            $result[$section['key']] = $filled;
        }

        if ($missing) {
            $this->addError('answers', 'Write at least one answer for: '.implode(', ', $missing).'.');

            return;
        }

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'active_recall',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode($result),
        ]);

        $this->redirect(route('missions.show', $this->run->mission), navigate: true);
    }
};
?>

<div class="space-y-6">
    <x-hook :text="$run->mission->stepContent('active_recall')['hook'] ?? null" />

    <div>
        <p class="text-xs font-semibold tracking-wide text-neutral-500 uppercase">Active Recall</p>
        <p class="text-xs text-neutral-500">{{ $this->run->mission->stepContent('active_recall')['instruction'] ?? '' }}</p>
    </div>

    @foreach ($this->sections() as $section)
        <div>
            <p class="text-sm font-semibold">{{ $section['label'] }}</p>
            <div class="mt-2 space-y-2">
                @for ($i = 0; $i < $section['count']; $i++)
                    <input
                        type="text"
                        wire:model="answers.{{ $section['key'] }}.{{ $i }}"
                        placeholder="{{ $i + 1 }}."
                        @readonly($readOnly)
                        class="w-full rounded border border-neutral-300 bg-transparent px-2 py-1 text-sm dark:border-neutral-700"
                    >
                @endfor
            </div>
        </div>
    @endforeach

    @error('answers')
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
