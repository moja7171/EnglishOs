<?php

use App\Models\Evidence;
use App\Models\MissionRun;
use App\Services\GeminiClient;
use Livewire\Component;

new class extends Component
{
    public MissionRun $run;

    public bool $readOnly = false;

    /** @var array<int, string> keyed by word index */
    public array $examples = [];

    /** @var array<string, array{severity: string, hint: string}> keyed by word */
    public array $feedback = [];

    public bool $checking = false;

    public ?string $error = null;

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
        $this->error = null;
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

        $this->checking = true;

        try {
            $this->feedback = $this->checkExamples($filled);
        } catch (\Throwable $e) {
            $this->error = "Couldn't check your sentences right now: {$e->getMessage()}";

            return;
        } finally {
            $this->checking = false;
        }

        $hasMajorIssue = collect($this->feedback)->contains(fn ($f) => ($f['severity'] ?? 'none') === 'major');

        if ($hasMajorIssue) {
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

    /**
     * @return array<string, array{severity: string, hint: string}>
     */
    private function checkExamples($filled): array
    {
        $payload = $filled->map(fn ($item) => "Word: \"{$item['word']}\" — Sentence: \"{$item['example']}\"")->implode("\n");

        $raw = app(GeminiClient::class)->chat(
            [['role' => 'user', 'text' => $payload]],
            systemPrompt: 'You are a supportive English writing assistant helping a B1 learner practice new '
                .'vocabulary. For each word/sentence pair below, judge whether the learner used the word correctly, '
                .'naturally, and as a genuine personal sentence (not just repeating the dictionary definition). '
                .'Reply with ONLY a valid JSON array, no markdown fences, each item shaped exactly like: '
                .'{"word": "...", "severity": "major" or "minor" or "none", "hint": "..."}. Use "major" only for '
                .'real problems: the word is missing or used with the wrong meaning, the sentence just repeats the '
                .'definition, or it is not real English. Use "minor" for small slips (article, preposition, tense) '
                .'that do not block understanding. Use "none" when it is good. For "major" or "minor", the hint must '
                .'be a short guiding question or nudge that helps the learner fix it themselves — never write the '
                .'corrected sentence for them.'
        );

        $data = json_decode(trim($raw), true);

        if (! is_array($data)) {
            throw new RuntimeException('Unexpected AI response format.');
        }

        return collect($data)->keyBy('word')->all();
    }
};
?>

<div class="space-y-6">
    <x-hook :text="$run->mission->stepContent('vocabulary_builder')['hook'] ?? null" />

    <div>
        <p class="text-xs font-semibold tracking-wide text-neutral-500 uppercase">Choose expressions you'll really use</p>
        <p class="mt-1 text-sm text-neutral-500">Write at least 3 personal examples using these words.</p>
    </div>

    <div class="space-y-4">
        @foreach ($run->mission->stepContent('vocabulary_builder')['vocabulary'] ?? [] as $index => $item)
            @php $itemFeedback = $feedback[$item['word']] ?? null; @endphp
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
                @if ($itemFeedback && ($itemFeedback['severity'] ?? 'none') === 'major')
                    <p class="mt-1 text-xs text-red-600">⚠ {{ $itemFeedback['hint'] }}</p>
                @elseif ($itemFeedback && ($itemFeedback['severity'] ?? 'none') === 'minor')
                    <p class="mt-1 text-xs text-amber-600">💡 {{ $itemFeedback['hint'] }}</p>
                @endif
            </div>
        @endforeach
    </div>

    @error('examples')
        <p class="text-sm text-red-600">{{ $message }}</p>
    @enderror
    @if ($error)
        <p class="text-sm text-red-600">{{ $error }}</p>
    @endif

    @unless ($readOnly)
        <button
            wire:click="save"
            wire:loading.attr="disabled"
            class="rounded bg-neutral-900 px-4 py-2 text-sm font-semibold text-white dark:bg-white dark:text-neutral-900"
        >
            <span wire:loading.remove wire:target="save">Continue</span>
            <span wire:loading wire:target="save">Checking your sentences…</span>
        </button>
    @endunless
</div>
