<?php

use App\Livewire\Concerns\TracksCheckAttempts;
use App\Models\Evidence;
use App\Models\MissionRun;
use App\Services\SentenceChecker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Livewire\Component;

new class extends Component
{
    use TracksCheckAttempts;

    public MissionRun $run;

    public bool $readOnly = false;

    /**
     * True once the learner has moved into practice at least once — after
     * this, picking a word can only ever add, never remove, so an already
     * -written example is never silently destroyed.
     */
    public bool $practiceStarted = false;

    /** @var array<int, string> ordered phrases the learner picked from the story — at least 8, no upper limit */
    public array $selectedWords = [];

    /** @var array<int, string> keyed by index, parallel to $selectedWords */
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

        $data = json_decode($this->run->latestEvidence('vocabulary_builder')?->content_ref ?? '{}', true);
        $this->selectedWords = $data['selected_words'] ?? [];

        $saved = collect($data['examples'] ?? [])->keyBy('word');

        foreach ($this->selectedWords as $index => $word) {
            $this->examples[$index] = $saved[$word]['example'] ?? '';
        }
    }

    /**
     * Toggles one word from the story in or out of the learner's set. There
     * is no upper limit — only a minimum of 8 to unlock Continue. Once
     * practice has started, deselecting is disabled (only adding is still
     * allowed) so an already-written example can never be silently lost.
     */
    public function toggleWord(string $phrase): void
    {
        $key = array_search($phrase, $this->selectedWords, true);

        if ($key !== false) {
            if (! $this->practiceStarted) {
                array_splice($this->selectedWords, $key, 1);
            }

            return;
        }

        $this->selectedWords[] = $phrase;
    }

    public function startPractice(): void
    {
        $this->practiceStarted = true;
    }

    /**
     * Splits the seeded story (one entry per real-book sub-topic) on
     * **word** markers into ordered plain-text/selectable-phrase segments
     * per paragraph, so the template can render inline clickable words
     * without knowing the marker syntax.
     */
    public function storyParagraphs(): array
    {
        $paragraphs = $this->run->mission->stepContent('vocabulary_builder')['story'] ?? [];

        return collect($paragraphs)
            ->map(function (array $paragraph) {
                $parts = preg_split('/\*\*(.+?)\*\*/', $paragraph['text'], -1, PREG_SPLIT_DELIM_CAPTURE);

                $segments = collect($parts)
                    ->map(fn ($part, $i) => $i % 2 === 0 ? ['type' => 'text', 'value' => $part] : ['type' => 'word', 'value' => $part])
                    ->filter(fn ($segment) => $segment['value'] !== '')
                    ->values();

                return ['heading' => $paragraph['heading'], 'segments' => $segments];
            })
            ->all();
    }

    public function wordMeaning(string $phrase): string
    {
        $storyWords = collect($this->run->mission->stepContent('vocabulary_builder')['story_words'] ?? []);

        return $storyWords->firstWhere('phrase', $phrase)['meaning'] ?? '';
    }

    public function checkOne(int $index): void
    {
        $word = $this->selectedWords[$index] ?? null;

        if (! $word) {
            return;
        }

        $example = trim($this->examples[$index] ?? '');

        if ($example === '') {
            $this->checkErrors[$word] = 'Write something first.';

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
            $this->trackCheckAttempt($word, $data['severity']);
        } catch (ConnectionException|RequestException) {
            // RequestException's message carries the raw HTTP response body
            // (which can be an arbitrarily large error page, not a clean
            // API message) — never show that to the learner.
            $this->checkErrors[$word] = "Couldn't reach the AI service — please try again.";
        } catch (\Throwable $e) {
            $this->checkErrors[$word] = "Couldn't check this one: {$e->getMessage()}";
        }
    }

    /**
     * After 3 failed attempts on the same word, the learner can ask the AI
     * to just write the corrected sentence — see TracksCheckAttempts.
     */
    public function revealCorrection(int $index): void
    {
        $word = $this->selectedWords[$index] ?? null;
        $example = trim($this->examples[$index] ?? '');

        if (! $word || $example === '') {
            return;
        }

        $this->revealCorrectionFor(
            key: $word,
            context: "a personal sentence using the word \"{$word}\"",
            text: $example,
            errorBagKey: $word,
            onCorrected: function (string $corrected) use ($word, $index) {
                $this->examples[$index] = $corrected;
                $this->feedback[$word] = ['severity' => 'none', 'hint' => '', 'checkedText' => $corrected];
            },
        );
    }

    public function declineReveal(int $index): void
    {
        $word = $this->selectedWords[$index] ?? null;

        if ($word) {
            $this->declineCheckReveal($word);
        }
    }

    public function save(): void
    {
        $filled = collect($this->examples)
            ->filter(fn ($example) => trim((string) $example) !== '')
            ->map(fn ($example, $index) => [
                'word' => $this->selectedWords[$index] ?? null,
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
            'content_ref' => json_encode([
                'selected_words' => $this->selectedWords,
                'examples' => $filled->values(),
            ]),
        ]);

        $this->dispatch('clear-draft', prefix: $this->draftPrefix());
        $this->redirect(route('missions.show', $this->run->mission), navigate: true);
    }

    /**
     * Must match the prefix embedded in the Blade template's x-draft
     * attributes exactly — both build it the same way from the run id.
     */
    public function draftPrefix(): string
    {
        return "eos-draft:{$this->run->id}:vocabulary_builder:";
    }
};
?>

@php
    $storyParagraphs = $this->storyParagraphs();
    $initialFilled = collect($selectedWords)->map(fn ($word, $i) => trim($examples[$i] ?? '') !== '')->values();
    $draftPrefix = $this->draftPrefix();
@endphp

<div
    class="space-y-6"
    x-data="{
        phase: '{{ $readOnly || $practiceStarted ? 'practice' : 'story' }}',
        showStoryAgain: false,
        filled: {{ $initialFilled->toJson() }},
        dismissed: {},
        get filledCount() { return this.filled.filter(Boolean).length },
        get progressMessage() {
            const n = this.filledCount;
            if (n === 0) return 'Pick a word below and write your first example.';
            if (n === 1) return 'Nice start — keep going!';
            if (n === 2) return 'One more and you\'re ready to continue!';
            return 'Ready to continue — write more if you like!';
        },
    }"
>
    <x-hook :text="$run->mission->stepContent('vocabulary_builder')['hook'] ?? null" />

    @php $selectedCount = count($selectedWords); @endphp

    @unless ($readOnly)
        <div x-show="phase === 'story'" x-cloak class="space-y-4">
            <div>
                <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Read the story, then pick at least 8 words to practice</p>
                <p class="mt-1 text-sm text-ink-faint dark:text-ink-faint-dark">Tap any highlighted word below to select it.</p>
                <div class="mt-2">
                    <x-progress-bar>
                        <div
                            class="h-full rounded-full transition-all duration-300 {{ $selectedCount >= 8 ? 'bg-success dark:bg-success-dark' : 'bg-accent dark:bg-accent-dark' }}"
                            style="width: {{ min($selectedCount, 8) / 8 * 100 }}%"
                        ></div>
                        <x-slot:label>
                            <p class="text-xs font-semibold {{ $selectedCount >= 8 ? 'text-success dark:text-success-dark' : 'text-ink-soft dark:text-ink-soft-dark' }}">
                                {{ min($selectedCount, 8) }} of 8 selected{{ $selectedCount > 8 ? ' (+'.($selectedCount - 8).' bonus)' : '' }}
                            </p>
                        </x-slot:label>
                    </x-progress-bar>
                </div>
            </div>

            <div class="rounded-2xl border border-line bg-surface-sunken p-4 dark:border-line-dark dark:bg-surface-sunken-dark">
                @include('missions.steps.partials.vocabulary-story', ['storyParagraphs' => $storyParagraphs, 'selectedWords' => $selectedWords, 'component' => $this, 'readOnly' => $readOnly])
            </div>

            @if ($selectedCount >= 8)
                <button
                    type="button"
                    wire:click="startPractice"
                    x-on:click="phase = 'practice'"
                    class="inline-flex cursor-pointer items-center gap-1 rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
                >Continue with these {{ $selectedCount }} words @svg('heroicon-o-chevron-right', 'h-3.5 w-3.5')</button>
            @endif
        </div>
    @endunless

    <div x-show="phase === 'practice'" @unless ($readOnly) x-cloak @endunless class="space-y-6">
        <div>
            <button
                type="button"
                x-on:click="showStoryAgain = !showStoryAgain"
                class="inline-flex cursor-pointer items-center gap-1 text-xs font-semibold text-ink-faint underline decoration-dotted underline-offset-2 dark:text-ink-faint-dark"
            >
                <span x-show="!showStoryAgain" class="inline-flex items-center gap-1">@svg('heroicon-o-chevron-right', 'h-3 w-3') Show the story again</span>
                <span x-show="showStoryAgain" x-cloak class="inline-flex items-center gap-1">@svg('heroicon-o-chevron-down', 'h-3 w-3') Hide the story</span>
            </button>
            <div x-show="showStoryAgain" x-cloak class="mt-2 rounded-2xl border border-line bg-surface-sunken p-4 dark:border-line-dark dark:bg-surface-sunken-dark">
                @unless ($readOnly)
                    <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Tap any word not yet highlighted to add it to your practice list below.</p>
                @endunless
                @include('missions.steps.partials.vocabulary-story', ['storyParagraphs' => $storyParagraphs, 'selectedWords' => $selectedWords, 'component' => $this, 'readOnly' => $readOnly])
            </div>
        </div>

        <div>
            <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Choose expressions you'll really use</p>
            <p class="mt-1 text-sm text-ink-faint dark:text-ink-faint-dark">Write at least 3 personal examples using these words. Check one anytime for feedback, or we'll check the rest for you when you move on.</p>
            @unless ($readOnly)
                <div class="mt-2">
                    <x-progress-bar>
                        <div
                            class="h-full rounded-full transition-all duration-300"
                            :class="filledCount >= 3 ? 'bg-success dark:bg-success-dark' : 'bg-accent dark:bg-accent-dark'"
                            :style="`width: ${Math.min(filledCount, 3) / 3 * 100}%`"
                        ></div>
                        <x-slot:label>
                            <p
                                class="text-xs font-semibold transition-colors"
                                :class="filledCount >= 3 ? 'text-success dark:text-success-dark' : 'text-ink-soft dark:text-ink-soft-dark'"
                                x-text="progressMessage"
                            ></p>
                        </x-slot:label>
                    </x-progress-bar>
                </div>
            @endunless
        </div>

        <div wire:loading.class="pointer-events-none" wire:target="checkOne,revealCorrection,declineReveal,save" class="space-y-4">
            @foreach ($selectedWords as $index => $word)
                @php $itemFeedback = $feedback[$word] ?? null; @endphp
                <div class="rounded-xl border border-line p-3 dark:border-line-dark">
                    <p class="text-sm font-bold text-ink dark:text-ink-dark">{{ $word }}</p>
                    <p class="text-xs text-ink-faint dark:text-ink-faint-dark">{{ $this->wordMeaning($word) }}</p>
                    @unless ($readOnly)
                        <p x-show="filledCount >= 3 && !filled[{{ $index }}]" class="text-[11px] text-ink-faint italic dark:text-ink-faint-dark">optional — bonus practice</p>
                    @endunless

                    <div class="mt-2 flex items-center gap-2">
                        <input
                            type="text"
                            wire:model="examples.{{ $index }}"
                            x-on:input="filled[{{ $index }}] = $el.value.trim() !== ''; dismissed[{{ $index }}] = true"
                            placeholder="My example…"
                            @unless ($readOnly)
                                x-draft="{ key: '{{ $draftPrefix }}examples.{{ $index }}', field: 'examples.{{ $index }}' }"
                            @endunless
                            @readonly($readOnly)
                            wire:loading.attr="disabled"
                            wire:target="checkOne,revealCorrection,declineReveal,save"
                            class="w-full rounded-lg border border-line bg-transparent px-2 py-1 text-sm text-ink disabled:opacity-50 dark:border-line-dark dark:text-ink-dark"
                        >
                        <x-filled-check show="filled[{{ $index }}]" />
                        @unless ($readOnly)
                            <x-check-button method="checkOne" :index="$index" wire-target="checkOne,revealCorrection,declineReveal,save" />
                        @endunless
                    </div>

                    @unless ($readOnly)
                        <x-ai-thinking wire:loading wire:target="checkOne({{ $index }}), revealCorrection({{ $index }}), save" class="mt-2" />
                    @endunless

                    {{-- Fades out the moment the learner edits this input again — a stale
                         verdict for text that no longer exists would only mislead them. --}}
                    <div x-show="!dismissed[{{ $index }}]" x-transition.opacity.duration.300ms>
                        <x-severity-feedback :feedback="$itemFeedback" :error="$checkErrors[$word] ?? null" />
                    </div>

                    @unless ($readOnly)
                        <x-almost-reveal-notice :show="($checkAttempts[$word] ?? 0) === 2" />
                        <x-reveal-offer
                            :show="$offerReveal[$word] ?? false"
                            reveal-method="revealCorrection"
                            decline-method="declineReveal"
                            :index="$index"
                            wire-target="checkOne,revealCorrection,declineReveal,save"
                        />
                    @endunless
                </div>
            @endforeach
        </div>

        @error('examples')
            <p class="text-sm text-red-600">{{ $message }}</p>
        @enderror

        @unless ($readOnly)
            <x-continue-button
                on-click="filled.forEach((_, i) => dismissed[i] = true); $wire.save().then(() => { dismissed = {} })"
                wire-target="checkOne,revealCorrection,declineReveal,save"
                loading-label="Checking your sentences…"
            />
        @endunless
    </div>
</div>
