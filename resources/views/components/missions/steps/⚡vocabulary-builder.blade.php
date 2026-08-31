<?php

use App\Models\Evidence;
use App\Models\MissionRun;
use App\Services\SentenceChecker;
use Illuminate\Http\Client\ConnectionException;
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

        $this->runCheck($word, $example);
    }

    /**
     * Asks the shared SentenceChecker to judge one word/sentence pair,
     * storing the verdict (tagged with the exact text it applies to, so a
     * later edit doesn't leave a stale "major"/"none" verdict attached to
     * different text). See EOS-009 §8 "الگوی چک جمله" for the shared rules.
     */
    private function runCheck(string $word, string $example): void
    {
        unset($this->checkErrors[$word]);

        try {
            $data = app(SentenceChecker::class)->check(
                judgment: 'Judge whether the learner used the target word correctly, naturally, and as a '
                    .'genuine personal sentence (not just repeating the dictionary definition).',
                majorCriteria: 'the word is missing or used with the wrong meaning, the sentence just repeats '
                    .'the definition',
                context: "a personal sentence using the word \"{$word}\"",
                text: $example,
            );

            $this->feedback[$word] = $data + ['checkedText' => $example];
        } catch (ConnectionException) {
            $this->checkErrors[$word] = "Couldn't reach the AI service — please try again.";
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

        // Every filled sentence needs a fresh Gemini verdict before Continue
        // is allowed through — reuse an existing one only if it was checked
        // against this exact text (an edit since the last check invalidates it).
        foreach ($filled as $item) {
            if (! $item['word']) {
                continue;
            }

            $alreadyChecked = ($this->feedback[$item['word']]['checkedText'] ?? null) === $item['example'];

            if (! $alreadyChecked) {
                $this->runCheck($item['word'], $item['example']);
            }
        }

        $hasMajorIssue = $filled->contains(
            fn ($item) => $item['word'] && ($this->feedback[$item['word']]['severity'] ?? null) === 'major'
        );

        if ($hasMajorIssue) {
            $this->addError('examples', 'Fix the highlighted sentence before continuing.');

            return;
        }

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'vocabulary_builder',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => $filled->toJson(),
        ]);

        $this->redirect(route('missions.show', $this->run->mission), navigate: true);
    }
};
?>

@php
    $vocabulary = $run->mission->stepContent('vocabulary_builder')['vocabulary'] ?? [];
    $initialFilled = collect($vocabulary)->map(fn ($item, $i) => trim($examples[$i] ?? '') !== '')->values();
@endphp

<div
    class="space-y-6"
    x-data="{
        filled: {{ $initialFilled->toJson() }},
        dismissed: {},
        get filledCount() { return this.filled.filter(Boolean).length },
        get progressMessage() {
            const n = this.filledCount, total = {{ count($vocabulary) }};
            if (n === 0) return 'Pick a word below and write your first example.';
            if (n === 1) return 'Nice start — keep going!';
            if (n === 2) return 'One more and you\'re ready to continue!';
            if (n < total) return 'Ready to continue — want one more for bonus practice?';
            return 'All done — great work!';
        },
    }"
>
    <x-hook :text="$run->mission->stepContent('vocabulary_builder')['hook'] ?? null" />

    <div>
        <p class="text-xs font-semibold tracking-wide text-neutral-500 uppercase">Choose expressions you'll really use</p>
        <p class="mt-1 text-sm text-neutral-500">Write at least 3 personal examples using these words. Check one anytime for feedback, or we'll check the rest for you when you move on.</p>
        @unless ($readOnly)
            <div class="mt-2">
                <div class="h-1.5 w-full overflow-hidden rounded-full bg-neutral-200 dark:bg-neutral-800">
                    <div
                        class="h-full rounded-full transition-all duration-300"
                        :class="filledCount >= 3 ? 'bg-green-600' : 'bg-neutral-900 dark:bg-white'"
                        :style="`width: ${Math.min(filledCount, 3) / 3 * 100}%`"
                    ></div>
                </div>
                <p
                    class="mt-1.5 text-xs font-semibold transition-colors"
                    :class="filledCount >= 3 ? 'text-green-600' : 'text-neutral-600 dark:text-neutral-400'"
                    x-text="progressMessage"
                ></p>
            </div>
        @endunless
    </div>

    <div wire:loading.class="pointer-events-none" wire:target="checkOne,save" class="space-y-4">
        @foreach ($vocabulary as $index => $item)
            @php $itemFeedback = $feedback[$item['word']] ?? null; @endphp
            <div class="rounded border border-neutral-300 p-3 dark:border-neutral-700">
                <p class="text-sm font-bold">{{ $item['word'] }}</p>
                <p class="text-xs text-neutral-500">{{ $item['meaning'] }}</p>
                @unless ($readOnly)
                    <p x-show="filledCount >= 3 && !filled[{{ $index }}]" class="text-[11px] text-neutral-400 italic">optional — bonus practice</p>
                @endunless

                <div class="mt-2 flex items-center gap-2">
                    <input
                        type="text"
                        wire:model="examples.{{ $index }}"
                        x-on:input="filled[{{ $index }}] = $el.value.trim() !== ''; dismissed[{{ $index }}] = true"
                        placeholder="My example…"
                        @readonly($readOnly)
                        wire:loading.attr="disabled"
                        wire:target="checkOne,save"
                        class="w-full rounded border border-neutral-300 bg-transparent px-2 py-1 text-sm disabled:opacity-50 dark:border-neutral-700"
                    >
                    <span x-show="filled[{{ $index }}]" class="shrink-0 text-sm text-green-600">✓</span>
                    @unless ($readOnly)
                        <button
                            type="button"
                            x-on:click="dismissed[{{ $index }}] = true; $wire.checkOne({{ $index }}).then(() => { dismissed[{{ $index }}] = false })"
                            wire:loading.attr="disabled"
                            wire:target="checkOne,save"
                            class="shrink-0 cursor-pointer rounded border border-neutral-300 px-2 py-1 text-xs text-neutral-600 transition-colors hover:border-neutral-400 hover:bg-neutral-100 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 dark:border-neutral-700 dark:text-neutral-400 dark:hover:bg-neutral-800"
                        >
                            <span wire:loading.remove wire:target="checkOne({{ $index }})">Check</span>
                            <span wire:loading wire:target="checkOne({{ $index }})">Checking…</span>
                        </button>
                    @endunless
                </div>

                @unless ($readOnly)
                    <div wire:loading wire:target="checkOne({{ $index }}), save" class="mt-2 flex items-center gap-2 rounded-lg border border-neutral-200 bg-neutral-50 px-3 py-2 dark:border-neutral-800 dark:bg-neutral-900">
                        <span class="flex gap-1">
                            <span class="h-1.5 w-1.5 animate-typing-dot rounded-full bg-neutral-400" style="animation-delay: 0ms"></span>
                            <span class="h-1.5 w-1.5 animate-typing-dot rounded-full bg-neutral-400" style="animation-delay: 200ms"></span>
                            <span class="h-1.5 w-1.5 animate-typing-dot rounded-full bg-neutral-400" style="animation-delay: 400ms"></span>
                        </span>
                        <p class="text-sm text-neutral-500">AI is thinking…</p>
                    </div>
                @endunless

                {{-- Fades out the moment the learner edits this input again — a stale
                     verdict for text that no longer exists would only mislead them. --}}
                <div x-show="!dismissed[{{ $index }}]" x-transition.opacity.duration.300ms>
                    @if ($itemFeedback && ($itemFeedback['severity'] ?? 'none') === 'major')
                        <div class="mt-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 dark:border-red-900 dark:bg-red-950">
                            <p class="text-sm text-red-700 dark:text-red-400">{{ $itemFeedback['hint'] }}</p>
                        </div>
                    @elseif ($itemFeedback && ($itemFeedback['severity'] ?? 'none') === 'minor')
                        <div class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 dark:border-amber-900 dark:bg-amber-950">
                            <p class="text-sm text-amber-700 dark:text-amber-400">{{ $itemFeedback['hint'] }}</p>
                        </div>
                    @elseif ($itemFeedback && ($itemFeedback['severity'] ?? 'none') === 'none')
                        <div class="mt-2 rounded-lg border border-green-200 bg-green-50 px-3 py-2 dark:border-green-900 dark:bg-green-950">
                            <p class="text-sm text-green-700 dark:text-green-400">Looks good</p>
                        </div>
                    @endif
                    @if ($checkErrors[$item['word']] ?? null)
                        <p class="mt-1 text-xs text-red-600">{{ $checkErrors[$item['word']] }}</p>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    @error('examples')
        <p class="text-sm text-red-600">{{ $message }}</p>
    @enderror

    @unless ($readOnly)
        <button
            x-on:click="filled.forEach((_, i) => dismissed[i] = true); $wire.save().then(() => { dismissed = {} })"
            wire:loading.attr="disabled"
            wire:target="checkOne,save"
            class="cursor-pointer rounded bg-neutral-900 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-neutral-700 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 dark:bg-white dark:text-neutral-900 dark:hover:bg-neutral-200"
        >
            <span wire:loading.remove wire:target="save">Continue</span>
            <span wire:loading wire:target="save">Checking your sentences…</span>
        </button>
    @endunless
</div>
