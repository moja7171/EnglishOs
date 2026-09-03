<?php

use App\Livewire\Concerns\TracksAiUsage;
use App\Livewire\Concerns\TracksCheckAttempts;
use App\Models\Evidence;
use App\Models\MissionRun;
use App\Services\PexelsClient;
use App\Services\SentenceChecker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Livewire\Component;

new class extends Component
{
    use TracksAiUsage;
    use TracksCheckAttempts;

    public MissionRun $run;

    public bool $readOnly = false;

    /** @var array<int, string> parallel to stepContent('reading_comprehension')['questions'] */
    public array $answers = [];

    /** @var array<int, array{severity: string, hint: string, checkedText: string}> keyed by question index */
    public array $feedback = [];

    /** @var array<int, string> keyed by question index — per-input check failure message */
    public array $checkErrors = [];

    public function mount(): void
    {
        $this->answers = array_fill(0, count($this->questions()), '');

        if (! $this->readOnly) {
            return;
        }

        $data = json_decode($this->run->latestEvidence('reading_comprehension')?->content_ref ?? '{}', true);
        $this->answers = array_pad($data['answers'] ?? [], count($this->questions()), '');
    }

    private function questions(): array
    {
        return $this->run->mission->stepContent('reading_comprehension')['questions'] ?? [];
    }

    private function topicSummary(): ?string
    {
        return $this->run->mission->stepContent('reading_comprehension')['topic_summary'] ?? null;
    }

    private function context(int $index): string
    {
        $question = $this->questions()[$index] ?? '';
        $context = "a complete English sentence answering the comprehension question \"{$question}\" "
            .'about a short A2+ reading passage';

        return $this->topicSummary() ? "{$context}. The passage was about: {$this->topicSummary()}" : $context;
    }

    public function checkOne(int $index): void
    {
        $answer = trim($this->answers[$index] ?? '');

        if ($answer === '') {
            $this->checkErrors[$index] = 'Write something first.';

            return;
        }

        $this->runCheck($index, $answer);
    }

    /**
     * Asks the shared SentenceChecker to judge one answer, storing the
     * verdict tagged with the exact text it applies to, so a later edit
     * doesn't leave a stale verdict attached to different text. See
     * EOS-009 §8 "الگوی چک جمله" for the shared rules.
     */
    private function runCheck(int $index, string $text): void
    {
        unset($this->checkErrors[$index]);

        try {
            $data = app(SentenceChecker::class)->check(
                judgment: 'Judge whether what the learner wrote is a genuine, natural, complete English '
                    .'sentence that reasonably answers the comprehension question, on the SAME GENERAL '
                    .'TOPIC as the reading passage (not just a bare word or fragment, and not about a '
                    .'completely different topic). This is a coarse check only — do NOT fact-check the '
                    .'answer word-for-word against the topic summary; the summary is background, not a '
                    .'source to grade precision against.',
                majorCriteria: 'it is just a bare word or fragment (not a real sentence), it is about a '
                    .'completely different topic than the passage',
                context: $this->context($index),
                text: $text,
                extraGuidance: 'Treat anything on-topic and correctly formed as "none", even if a small '
                    .'detail is debatable — never claim the learner\'s facts are wrong, since you were '
                    .'only given a short summary, not the full passage.'.$this->run->aiToneGuidance(),
            );
            $this->recordGeminiCall();

            $this->feedback[$index] = $data + ['checkedText' => $text];
            $this->trackCheckAttempt($index, $data['severity']);
        } catch (ConnectionException|RequestException) {
            // RequestException's message carries the raw HTTP response body
            // (which can be an arbitrarily large error page, not a clean
            // API message) — never show that to the learner.
            $this->checkErrors[$index] = "Couldn't reach the AI service — please try again.";
        } catch (Throwable $e) {
            $this->checkErrors[$index] = "Couldn't check this one: {$e->getMessage()}";
        }
    }

    /**
     * After 3 failed attempts on the same answer, the learner can ask the
     * AI to just write the corrected version — see TracksCheckAttempts.
     */
    public function revealCorrection(int $index): void
    {
        $answer = trim($this->answers[$index] ?? '');

        if ($answer === '') {
            return;
        }

        $this->revealCorrectionFor(
            key: $index,
            context: $this->context($index),
            text: $answer,
            errorBagKey: $index,
            onCorrected: function (string $corrected) use ($index) {
                $this->answers[$index] = $corrected;
                $this->feedback[$index] = ['severity' => 'none', 'hint' => '', 'checkedText' => $corrected];
            },
        );
    }

    public function declineReveal(int $index): void
    {
        $this->declineCheckReveal($index);
    }

    /**
     * A decorative header image for the passage's subject — dual-coding,
     * same principle as Vocabulary Builder's flashcards. Cached per
     * mission (not per learner), fails soft like every PexelsClient call.
     */
    public function passageImageUrl(): ?string
    {
        $query = $this->run->mission->stepContent('reading_comprehension')['image_query'] ?? null;

        if (! $query) {
            return null;
        }

        return app(PexelsClient::class)->imageUrlFor($this->run->mission->code.'-reading', $query);
    }

    /**
     * @return list<array{prompt: string, options: list<string>, correct: int}>
     */
    public function comprehensionCards(): array
    {
        $items = $this->run->mission->stepContent('reading_comprehension')['comprehension_check'] ?? [];

        return collect($items)
            ->map(fn ($item) => ['prompt' => $item['statement'], 'options' => ['True', 'False'], 'correct' => $item['correct'] ? 0 : 1])
            ->all();
    }

    public function save(): void
    {
        $filled = collect($this->answers)->map(fn ($a) => trim((string) $a));

        if ($filled->contains('')) {
            $this->addError('answers', 'Answer both questions before continuing.');

            return;
        }

        // Every answer needs a fresh verdict before Continue is allowed
        // through — reuse an existing one only if it was checked against
        // this exact text (an edit since the last check invalidates it).
        foreach ($filled as $index => $text) {
            $alreadyChecked = ($this->feedback[$index]['checkedText'] ?? null) === $text;

            if (! $alreadyChecked) {
                $this->runCheck($index, $text);
            }
        }

        $hasMajorIssue = $filled->keys()->contains(
            fn ($index) => ($this->feedback[$index]['severity'] ?? null) === 'major'
        );

        if ($hasMajorIssue) {
            $this->addError('answers', 'Fix the highlighted answer before continuing.');

            return;
        }

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'reading_comprehension',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode(['answers' => $filled->values()]),
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
        return "eos-draft:{$this->run->id}:reading_comprehension:";
    }
};
?>

@php
    $reading = $run->mission->stepContent('reading_comprehension');
    $draftPrefix = $this->draftPrefix();
    // Two focused sub-steps instead of one long scroll (EOS-009 §8's
    // UI/UX review) — read + warm-up first, the AI-checked questions
    // second. Nothing here needs a next-disabled gate (the quick-round
    // warm-up is always skippable), unlike Listening's gist/expression gate.
    $totalSubsteps = 2;
@endphp

<div class="space-y-6" x-data="{ activeSubstep: 0 }">
    <x-hook :text="$reading['hook'] ?? null" />

    <div class="mb-2">
        <x-progress-bar>
            <div
                class="h-full rounded-full bg-accent transition-all duration-300 dark:bg-accent-dark"
                :style="`width: ${(activeSubstep + 1) / {{ $totalSubsteps }} * 100}%`"
            ></div>
            <x-slot:label>
                <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">
                    Part <span x-text="activeSubstep + 1"></span> of {{ $totalSubsteps }}
                </p>
            </x-slot:label>
        </x-progress-bar>
    </div>

    {{-- Sub-step: the passage itself + the ungraded warm-up --}}
    <div x-show="activeSubstep === 0" x-cloak>
        <div class="rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
            <div class="flex items-start gap-3">
                @if ($imageUrl = $this->passageImageUrl())
                    <img src="{{ $imageUrl }}" alt="" class="h-16 w-16 shrink-0 rounded-full object-cover">
                @endif
                <div>
                    <p class="text-xs font-semibold tracking-wide text-accent-ink uppercase dark:text-accent-ink-dark">{{ $reading['passage_title'] ?? 'Reading' }}</p>
                    <p class="mt-2 text-sm leading-relaxed text-ink dark:text-ink-dark">{{ $reading['passage'] ?? '' }}</p>
                </div>
            </div>
        </div>

        @unless ($readOnly)
            <div class="mt-4">
                <p class="text-sm font-semibold text-ink dark:text-ink-dark">Quick check</p>
                <p class="text-xs text-ink-faint dark:text-ink-faint-dark">True or false — just a warm-up, skip anytime.</p>
                <div class="mt-2">
                    <x-quick-round :cards="$this->comprehensionCards()" />
                </div>
            </div>
        @endunless
    </div>

    {{-- Sub-step: the AI-checked comprehension questions --}}
    <div x-show="activeSubstep === 1" x-cloak>
        <div wire:loading.class="pointer-events-none" wire:target="checkOne,revealCorrection,declineReveal,save" class="space-y-3">
            @foreach ($reading['questions'] ?? [] as $index => $question)
                @php $itemFeedback = $feedback[$index] ?? null; @endphp
                <div class="rounded-xl border border-line p-3 dark:border-line-dark">
                    <p class="text-sm text-ink dark:text-ink-dark">{{ $question }}</p>
                    <div class="mt-2 flex items-center gap-2">
                        <input
                            type="text"
                            wire:model="answers.{{ $index }}"
                            @unless ($readOnly)
                                x-draft="{ key: '{{ $draftPrefix }}answers.{{ $index }}', field: 'answers.{{ $index }}' }"
                            @endunless
                            @readonly($readOnly)
                            wire:loading.attr="disabled"
                            wire:target="checkOne,revealCorrection,declineReveal,save"
                            class="w-full rounded-lg border border-line bg-transparent px-2 py-1 text-sm text-ink disabled:opacity-50 dark:border-line-dark dark:text-ink-dark"
                        >
                        @unless ($readOnly)
                            <x-check-button method="checkOne" :index="$index" wire-target="checkOne,revealCorrection,declineReveal,save" />
                        @endunless
                    </div>

                    @unless ($readOnly)
                        <x-ai-thinking wire:loading wire:target="checkOne({{ $index }}), revealCorrection({{ $index }}), save" class="mt-2" />
                    @endunless

                    <x-severity-feedback :feedback="$itemFeedback" :error="$checkErrors[$index] ?? null" />

                    @unless ($readOnly)
                        <x-almost-reveal-notice :show="($checkAttempts[$index] ?? 0) === 2" />
                        <x-reveal-offer
                            :show="$offerReveal[$index] ?? false"
                            reveal-method="revealCorrection"
                            decline-method="declineReveal"
                            :index="$index"
                            wire-target="checkOne,revealCorrection,declineReveal,save"
                        />
                    @endunless
                </div>
            @endforeach
        </div>
        @error('answers')
            <p class="text-sm text-red-600">{{ $message }}</p>
        @enderror

        @unless ($readOnly)
            <div class="mt-4">
                <x-continue-button
                    on-click="$wire.save()"
                    wire-target="checkOne,revealCorrection,declineReveal,save"
                    loading-label="Checking your answers…"
                />
            </div>
        @endunless
    </div>

    <div class="mt-4">
        <x-substep-nav index-var="activeSubstep" :total="$totalSubsteps" />
    </div>
</div>
