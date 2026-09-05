<?php

use App\Livewire\Concerns\TracksAiUsage;
use App\Models\Evidence;
use App\Models\MissionRun;
use App\Services\GeminiClient;
use App\Services\PexelsClient;
use Livewire\Component;

new class extends Component
{
    use TracksAiUsage;

    public MissionRun $run;

    public bool $readOnly = false;

    public string $text = '';

    /** @var array{strength: string, expression: string, correction: string}|null */
    public ?array $feedback = null;

    /**
     * True once Continue has saved the essay — the step then shows AI
     * feedback on the writing (same 3-part pattern as AI Feedback #1)
     * before the learner dismisses it with proceed() below, instead of
     * jumping away immediately. Progress is already saved by then; a
     * failed feedback call never blocks it, see generateFeedback().
     */
    public bool $completed = false;

    public function mount(): void
    {
        if (! $this->readOnly) {
            return;
        }

        $this->text = $this->run->latestEvidence('writing')?->content_ref ?? '';

        $data = json_decode($this->run->latestEvidence('writing_feedback')?->content_ref ?? '{}', true);

        if (isset($data['strength'], $data['expression'], $data['correction'])) {
            $this->feedback = $data;
        }
    }

    public function getWordCountProperty(): int
    {
        return count(array_filter(preg_split('/\s+/', trim($this->text))));
    }

    /**
     * One small inspirational thumbnail per writing prompt — dual-coding,
     * same principle as Vocabulary Builder/Reading Comprehension. Purely
     * decorative, fails soft like every PexelsClient call.
     *
     * @return array<string, string> keyed by prompt label
     */
    public function promptImageUrls(): array
    {
        $prompts = $this->run->mission->stepContent('writing')['prompts'] ?? [];
        $client = app(PexelsClient::class);

        return collect($prompts)
            ->filter(fn ($prompt) => is_array($prompt) && ($prompt['image_query'] ?? null))
            ->mapWithKeys(fn ($prompt) => [
                $prompt['label'] => $client->imageUrlFor(
                    $this->run->mission->code.'-writing-'.$prompt['label'],
                    $prompt['image_query'],
                ),
            ])
            ->filter()
            ->all();
    }

    public function save(): void
    {
        $minWords = $this->run->mission->stepContent('writing')['min_words'] ?? 100;

        if ($this->wordCount < $minWords) {
            $this->addError('text', "Write at least {$minWords} words before continuing.");

            return;
        }

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'writing',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => $this->text,
        ]);

        $this->generateFeedback();

        $this->dispatch('clear-draft', prefix: $this->draftPrefix());
        $this->completed = true;
    }

    /**
     * Same 3-part pattern as AI Feedback #1 (one strength, one good
     * expression, one kind correction) — 'writing' itself was the only
     * free-text step nobody ever actually read with AI. Stored as its
     * OWN phase ('writing_feedback'), deliberately not a real step key in
     * any mission, so it's just extra data alongside the essay Evidence —
     * never affects step progression, and Error Log's gatherLearnerText()
     * (which reads the 'writing' Evidence as plain text) stays untouched.
     * Silent on failure, same as Activation's reflection: Evidence for
     * the essay itself is already saved either way.
     */
    private function generateFeedback(): void
    {
        try {
            $vocabularyWords = $this->run->selectedVocabularyWords();
            $vocabularyContext = $vocabularyWords
                ? ' If any of these words appear naturally, you can mention that warmly: '
                    .collect($vocabularyWords)->map(fn ($w) => "\"{$w}\"")->implode(', ').'.'
                : '';

            $raw = app(GeminiClient::class)->chat(
                [['role' => 'user', 'text' => $this->text]],
                systemPrompt: 'You are an encouraging English teacher reviewing a short piece of writing by '
                    .$this->run->learner->levelDescription().'. '.$vocabularyContext.' '
                    .'Reply with ONLY valid JSON, no markdown fences, no extra text, in exactly this shape: '
                    .'{"strength": "one specific thing they did well, one sentence", '
                    .'"expression": "one good word or phrase they actually used", '
                    .'"correction": "one grammar or vocabulary mistake to fix, one sentence, phrased kindly"}'
            );
            $this->recordGeminiCall();

            $data = json_decode(trim($raw), true);

            if (! is_array($data) || ! isset($data['strength'], $data['expression'], $data['correction'])) {
                return;
            }

            $this->feedback = $data;

            Evidence::create([
                'mission_run_id' => $this->run->id,
                'phase' => 'writing_feedback',
                'type' => Evidence::TYPE_TEXT,
                'content_ref' => json_encode($data),
            ]);
        } catch (Throwable) {
            // Silent by design — see method docblock.
        }
    }

    public function proceed(): void
    {
        $this->redirect(route('missions.show', $this->run->mission), navigate: true);
    }

    /**
     * Must match the prefix embedded in the Blade template's x-draft
     * attribute exactly — both build it the same way from the run id.
     */
    public function draftPrefix(): string
    {
        return "eos-draft:{$this->run->id}:writing:";
    }
};
?>

@php
    $writing = $run->mission->stepContent('writing');
    $vocabularyWords = $run->selectedVocabularyWords();
    $draftPrefix = $this->draftPrefix();
    $prompts = $writing['prompts'] ?? [];
    $promptImages = $this->promptImageUrls();
@endphp

<div class="space-y-4">
    <x-hook :text="$writing['hook'] ?? null" />

    @if ($completed || ($readOnly && $feedback))
        <div class="space-y-4 rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
            <div>
                <p class="inline-flex items-center gap-1 text-xs font-semibold tracking-wide text-success uppercase dark:text-success-dark">
                    @svg('heroicon-o-check-circle', 'h-4 w-4')
                    Writing complete
                </p>
                @if ($feedback)
                    <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">A quick look at your writing before you move on.</p>
                @endif
            </div>

            @if ($feedback)
                <div class="space-y-2">
                    <div class="rounded-xl border border-line p-3 dark:border-line-dark">
                        <p class="text-xs font-semibold text-success uppercase dark:text-success-dark">One thing you did well</p>
                        <p class="mt-1 text-sm text-ink dark:text-ink-dark">{{ $feedback['strength'] }}</p>
                    </div>
                    <div class="rounded-xl border border-line p-3 dark:border-line-dark">
                        <p class="text-xs font-semibold text-ink-faint uppercase dark:text-ink-faint-dark">A good expression you used</p>
                        <p class="mt-1 text-sm text-ink dark:text-ink-dark">{{ $feedback['expression'] }}</p>
                    </div>
                    <div class="rounded-xl border border-line p-3 dark:border-line-dark">
                        <p class="text-xs font-semibold text-amber-600 uppercase">One thing to improve</p>
                        <p class="mt-1 text-sm text-ink dark:text-ink-dark">{{ $feedback['correction'] }}</p>
                    </div>
                </div>
            @endif

            @unless ($readOnly)
                <button
                    wire:click="proceed"
                    class="cursor-pointer rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
                >
                    Continue
                </button>
            @endunless
        </div>
    @endif

    @unless ($completed)
    <div>
        <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Writing</p>
        <h2 class="font-display text-lg font-bold text-ink dark:text-ink-dark">{{ $writing['title'] ?? 'Writing' }}</h2>
    </div>

    <div>
        <p class="text-xs font-semibold text-ink-faint uppercase dark:text-ink-faint-dark">Write about:</p>
        <div class="mt-2 flex gap-3 overflow-x-auto pb-1">
            @foreach ($prompts as $prompt)
                @php $label = is_array($prompt) ? ($prompt['label'] ?? '') : $prompt; @endphp
                <div class="flex shrink-0 flex-col items-center gap-1">
                    @if ($imageUrl = $promptImages[$label] ?? null)
                        <img src="{{ $imageUrl }}" alt="" class="h-14 w-14 rounded-xl object-cover">
                    @else
                        <div class="flex h-14 w-14 items-center justify-center rounded-xl border border-line dark:border-line-dark"></div>
                    @endif
                    <span class="text-xs text-ink-soft dark:text-ink-soft-dark">{{ $label }}</span>
                </div>
            @endforeach
        </div>
    </div>

    <x-vocabulary-pills :words="$vocabularyWords" />

    @if (count($writing['try_to_use'] ?? []))
        <div>
            <p class="text-xs font-semibold text-ink-faint uppercase dark:text-ink-faint-dark">Connectors that help</p>
            <div class="mt-1 flex flex-wrap gap-1.5">
                @foreach ($writing['try_to_use'] as $word)
                    <span class="rounded-full border border-line px-2 py-0.5 text-xs text-ink-soft dark:border-line-dark dark:text-ink-soft-dark">{{ $word }}</span>
                @endforeach
            </div>
        </div>
    @endif

    <textarea
        wire:model.live="text"
        rows="10"
        placeholder="Start writing…"
        @unless ($readOnly)
            x-draft="{ key: '{{ $draftPrefix }}text', field: 'text' }"
        @endunless
        @readonly($readOnly)
        class="w-full rounded-xl border border-line bg-transparent p-3 text-sm text-ink dark:border-line-dark dark:text-ink-dark"
    ></textarea>

    <div class="flex items-center justify-between text-xs">
        <span class="{{ $this->wordCount >= ($writing['min_words'] ?? 100) ? 'text-success dark:text-success-dark' : 'text-ink-faint dark:text-ink-faint-dark' }}">
            {{ $this->wordCount }} words (target {{ $writing['min_words'] ?? 100 }}–{{ $writing['max_words'] ?? 150 }})
        </span>
    </div>

    @error('text')
        <p class="text-sm text-red-600">{{ $message }}</p>
    @enderror

    {{-- text uses wire:model.live, so wordCount is already known
         server-side on every keystroke — no extra Alpine tracking needed,
         just don't render the bar until the minimum is reached. --}}
    @if (! $readOnly && $this->wordCount >= ($writing['min_words'] ?? 100))
        <x-sticky-bar>
            <button
                wire:click="save"
                wire:loading.attr="disabled"
                wire:target="save"
                class="cursor-pointer rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 dark:bg-accent-dark"
            >
                <span wire:loading.remove wire:target="save">Continue</span>
                <span wire:loading wire:target="save">Saving…</span>
            </button>
        </x-sticky-bar>
    @endif
    @endunless
</div>
