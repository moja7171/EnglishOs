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

    /** @var array<string, string> keyed by word — per-input check failure message */
    public array $checkErrors = [];

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

    /**
     * Checks one word/sentence pair on demand — the learner opts in per
     * input; nothing is approved or rejected until they click it.
     */
    public function checkOne(int $index): void
    {
        $vocabulary = $this->run->mission->stepContent('vocabulary_builder')['vocabulary'] ?? [];
        $word = $vocabulary[$index]['word'] ?? null;
        $example = trim($this->examples[$index] ?? '');

        if (! $word || $example === '') {
            return;
        }

        unset($this->checkErrors[$word]);

        try {
            $raw = app(GeminiClient::class)->chat(
                [['role' => 'user', 'text' => "Word: \"{$word}\" — Sentence: \"{$example}\""]],
                systemPrompt: 'You are a supportive English writing assistant helping a B1 learner practice new '
                    .'vocabulary. Judge whether the learner used the word correctly, naturally, and as a genuine '
                    .'personal sentence (not just repeating the dictionary definition). Reply with ONLY valid JSON, '
                    .'no markdown fences, shaped exactly like: {"severity": "major" or "minor" or "none", '
                    .'"hint": "..."}. Use "major" only for real problems: the word is missing or used with the wrong '
                    .'meaning, the sentence just repeats the definition, or it is not real English. Use "minor" for '
                    .'small slips (article, preposition, tense) that do not block understanding. Use "none" when it '
                    .'is good. For "major" or "minor", the hint must be a short guiding question or nudge that helps '
                    .'the learner fix it themselves — never write the corrected sentence for them.'
            );

            $data = json_decode(trim($raw), true);

            if (! is_array($data) || ! isset($data['severity'])) {
                throw new RuntimeException('Unexpected AI response format.');
            }

            $this->feedback[$word] = $data;
        } catch (\Throwable $e) {
            $this->checkErrors[$word] = "Couldn't check this one: {$e->getMessage()}";
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
    <x-hook :text="$run->mission->stepContent('vocabulary_builder')['hook'] ?? null" />

    <div>
        <p class="text-xs font-semibold tracking-wide text-neutral-500 uppercase">Choose expressions you'll really use</p>
        <p class="mt-1 text-sm text-neutral-500">Write at least 3 personal examples using these words. Check any one with the AI assistant if you want a second opinion.</p>
    </div>

    <div class="space-y-4">
        @foreach ($run->mission->stepContent('vocabulary_builder')['vocabulary'] ?? [] as $index => $item)
            @php $itemFeedback = $feedback[$item['word']] ?? null; @endphp
            <div class="rounded border border-neutral-300 p-3 dark:border-neutral-700">
                <p class="text-sm font-bold">{{ $item['word'] }}</p>
                <p class="text-xs text-neutral-500">{{ $item['meaning'] }}</p>

                <div class="mt-2 flex items-center gap-2">
                    <input
                        type="text"
                        wire:model="examples.{{ $index }}"
                        placeholder="My example…"
                        @readonly($readOnly)
                        class="w-full rounded border border-neutral-300 bg-transparent px-2 py-1 text-sm dark:border-neutral-700"
                    >
                    @unless ($readOnly)
                        <button
                            type="button"
                            wire:click="checkOne({{ $index }})"
                            wire:loading.attr="disabled"
                            wire:target="checkOne({{ $index }})"
                            class="shrink-0 rounded border border-neutral-300 px-2 py-1 text-xs text-neutral-600 dark:border-neutral-700 dark:text-neutral-400"
                        >
                            <span wire:loading.remove wire:target="checkOne({{ $index }})">Check</span>
                            <span wire:loading wire:target="checkOne({{ $index }})">…</span>
                        </button>
                    @endunless
                </div>

                @if ($itemFeedback && ($itemFeedback['severity'] ?? 'none') === 'major')
                    <p class="mt-1 text-xs text-red-600">⚠ {{ $itemFeedback['hint'] }}</p>
                @elseif ($itemFeedback && ($itemFeedback['severity'] ?? 'none') === 'minor')
                    <p class="mt-1 text-xs text-amber-600">💡 {{ $itemFeedback['hint'] }}</p>
                @elseif ($itemFeedback && ($itemFeedback['severity'] ?? 'none') === 'none')
                    <p class="mt-1 text-xs text-green-600">✓ Looks good</p>
                @endif
                @if ($checkErrors[$item['word']] ?? null)
                    <p class="mt-1 text-xs text-red-600">{{ $checkErrors[$item['word']] }}</p>
                @endif
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
