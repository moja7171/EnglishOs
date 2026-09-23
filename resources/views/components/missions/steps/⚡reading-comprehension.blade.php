<?php

use App\Livewire\Concerns\TracksAiUsage;
use App\Livewire\Concerns\TracksCheckAttempts;
use App\Livewire\Concerns\TracksVocabularyNotebook;
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
    use TracksVocabularyNotebook;

    public MissionRun $run;

    public bool $readOnly = false;

    /** @var array<int, string> parallel to stepContent('reading_comprehension')['questions'] */
    public array $answers = [];

    /** @var array<int, array{severity: string, hint: string, checkedText: string}> keyed by question index */
    public array $feedback = [];

    /** @var array<int, string> keyed by question index — per-input check failure message */
    public array $checkErrors = [];

    /**
     * True once Continue has passed every check and Evidence is saved —
     * the step then shows the "Add to My Words" checklist for the new
     * words met in the passage (Epic F), same pattern as Listening.
     */
    public bool $completed = false;

    /** How many answers needed no fix at all this attempt (encouragement score, Epic H). */
    public ?int $correctCount = null;

    /** The same count from the learner's own last attempt at this step, if any. */
    public ?int $previousCorrect = null;

    public function mount(): void
    {
        $this->answers = array_fill(0, count($this->questions()), '');

        if (! $this->readOnly) {
            return;
        }

        $data = json_decode($this->run->latestEvidence('reading_comprehension')?->content_ref ?? '{}', true);
        $this->answers = array_pad($data['answers'] ?? [], count($this->questions()), '');
    }

    /**
     * The passage's brand-new words (not the reused Vocabulary Builder
     * ones) — the "New words" section below the passage, and what
     * "Add to My Words" offers once this step is done.
     *
     * @return list<array{phrase: string, definition: string, pos?: string, example?: string}>
     */
    public function newWords(): array
    {
        return collect($this->run->mission->stepContent('reading_comprehension')['highlighted_phrases'] ?? [])
            ->where('type', 'new')
            ->values()
            ->all();
    }

    /**
     * @return list<array{word: string, meaning: string}>
     */
    protected function notebookCandidates(): array
    {
        return collect($this->newWords())
            ->map(fn ($item) => ['word' => $item['phrase'], 'meaning' => $item['definition'] ?? ''])
            ->values()
            ->all();
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
                feedbackDepth: $this->run->mission->feedbackDepth(),
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
     * The passage text with its reused Vocabulary-Builder phrases and
     * brand-new bonus words wrapped in a highlighted <mark>, built from
     * `highlighted_phrases` in the seeder content (Story 3). Longest
     * phrase first so a shorter phrase that happens to be a substring of
     * a longer one never steals the match. Escapes everything except the
     * `<mark>` wrapper itself, so this is the one place in this file that
     * needs {!! !!} rather than {{ }}.
     */
    public function highlightedPassageHtml(): string
    {
        $passage = $this->run->mission->stepContent('reading_comprehension')['passage'] ?? '';
        $phrases = $this->run->mission->stepContent('reading_comprehension')['highlighted_phrases'] ?? [];

        if ($passage === '' || empty($phrases)) {
            return e($passage);
        }

        $sorted = collect($phrases)->sortByDesc(fn ($p) => mb_strlen($p['phrase']))->values();
        $byPhrase = $sorted->keyBy('phrase');
        $pattern = '/('.$sorted->map(fn ($p) => preg_quote($p['phrase'], '/'))->implode('|').')/u';

        $parts = preg_split($pattern, $passage, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$passage];

        return collect($parts)->map(function (string $part) use ($byPhrase) {
            $match = $byPhrase->get($part);

            if (! $match) {
                return e($part);
            }

            $isNew = ($match['type'] ?? 'reused') === 'new';
            $tooltip = $isNew
                ? ($match['definition'] ?? '')
                : 'این کلمه رو توی New Words دیدی';
            $classes = $isNew
                ? 'rounded bg-accent/15 px-0.5 text-accent-ink cursor-help dark:bg-accent-dark/25 dark:text-accent-ink-dark'
                : 'rounded bg-success/15 px-0.5 text-success cursor-help dark:bg-success-dark/25 dark:text-success-dark';

            return '<mark class="'.$classes.'" title="'.e($tooltip).'">'.e($part).'</mark>';
        })->implode('');
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
     * @return list<array{prompt: string, options: list<string>, correct: int, difficulty?: string}>
     */
    public function comprehensionCards(): array
    {
        $items = $this->run->mission->stepContent('reading_comprehension')['comprehension_check'] ?? [];

        return collect($items)
            ->map(fn ($item) => [
                'prompt' => $item['statement'],
                'options' => ['True', 'False'],
                'correct' => $item['correct'] ? 0 : 1,
                ...(isset($item['difficulty']) ? ['difficulty' => $item['difficulty']] : []),
            ])
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

        // Read BEFORE creating this attempt's own row — a retry (?retry=1)
        // means there can already be an earlier one for this same phase.
        $this->previousCorrect = $this->readPreviousCorrectCount();

        $severities = $filled->keys()->map(fn ($index) => $this->feedback[$index]['severity'] ?? 'none')->values();

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'reading_comprehension',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode(['answers' => $filled->values(), 'severities' => $severities]),
        ]);

        $this->correctCount = $severities->filter(fn ($s) => $s === 'none')->count();

        $this->dispatch('clear-draft', prefix: $this->draftPrefix());
        $this->completed = true;
        $this->initWordsToTrack();
    }

    /**
     * The learner's own last attempt at this exact step (encouragement
     * score's comparison, Epic H) — null when this is their first attempt
     * or an old Evidence row predates severities being persisted at all.
     */
    private function readPreviousCorrectCount(): ?int
    {
        $previous = $this->run->evidence()
            ->where('phase', 'reading_comprehension')
            ->where('type', Evidence::TYPE_TEXT)
            ->latest()
            ->first();

        if (! $previous) {
            return null;
        }

        $severities = json_decode($previous->content_ref, true)['severities'] ?? null;

        return $severities ? collect($severities)->filter(fn ($s) => $s === 'none')->count() : null;
    }

    public function proceed(): void
    {
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
    $passageHtml = $this->highlightedPassageHtml();
    $draftPrefix = $this->draftPrefix();
    // Two focused sub-steps instead of one long scroll (EOS-009 §8's
    // UI/UX review) — read + warm-up first, the AI-checked questions
    // second. Nothing here needs a next-disabled gate (the quick-round
    // warm-up is always skippable), unlike Listening's gist/expression gate.
    $totalSubsteps = 2;
@endphp

<div
    class="space-y-6"
    x-data="{
        activeSubstep: 0,
        dismissed: {},
        init() {
            this.activeSubstep = window.eosDraft.restoreIndex('{{ $draftPrefix }}activeSubstep', 0);
            this.$watch('activeSubstep', (v) => window.eosDraft.persistIndex('{{ $draftPrefix }}activeSubstep', v));
        },
    }"
>
    <x-hook :text="$reading['hook'] ?? null" />

    @if ($completed)
        <div class="space-y-4 rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
            <p class="inline-flex items-center gap-1 text-xs font-semibold tracking-wide text-success uppercase dark:text-success-dark">
                @svg('heroicon-o-check-circle', 'h-4 w-4')
                Reading complete
            </p>

            @if (! is_null($correctCount))
                <x-encouragement-score :correct="$correctCount" :total="count($this->questions())" label="answers" :previous-correct="$previousCorrect" />
            @endif

            @if (count($this->newWords()))
                <div>
                    <p class="text-sm text-ink-soft dark:text-ink-soft-dark">Here are the new words from today's passage — pick which ones join your spaced-repetition notebook.</p>
                    <div class="mt-2 space-y-2">
                        @foreach ($this->newWords() as $index => $word)
                            <label class="flex cursor-pointer items-start gap-2.5 rounded-xl border border-line p-3 dark:border-line-dark">
                                <input
                                    type="checkbox"
                                    wire:model="wordsToTrack.{{ $index }}"
                                    class="mt-0.5 h-4 w-4 shrink-0 cursor-pointer rounded border-line text-accent focus:ring-accent dark:border-line-dark dark:bg-surface-dark dark:text-accent-dark"
                                >
                                <span>
                                    <span class="block text-sm font-bold text-ink dark:text-ink-dark">{{ $word['phrase'] }}</span>
                                    <span class="block text-xs text-ink-faint dark:text-ink-faint-dark">{{ $word['definition'] }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="flex flex-wrap items-center gap-3">
                @unless ($readOnly)
                    @if ($trackedWords)
                        <span class="inline-flex items-center gap-1 text-sm font-semibold text-success dark:text-success-dark">
                            @svg('heroicon-o-check-circle', 'h-4 w-4') Added to My Words
                        </span>
                    @elseif (count($this->newWords()))
                        <button
                            type="button"
                            wire:click="addWordsToNotebook"
                            wire:loading.attr="disabled"
                            wire:target="addWordsToNotebook"
                            class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-line px-4 py-2 text-sm font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <span wire:loading.remove wire:target="addWordsToNotebook" class="inline-flex items-center gap-1 whitespace-nowrap">@svg('heroicon-o-book-open', 'h-4 w-4') Add to My Words</span>
                            <span wire:loading wire:target="addWordsToNotebook">Adding…</span>
                        </button>
                    @endif

                    <button
                        wire:click="proceed"
                        wire:loading.attr="disabled"
                        wire:target="proceed"
                        class="cursor-pointer rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <span wire:loading.remove wire:target="proceed">Continue</span>
                        <span wire:loading wire:target="proceed">Please wait…</span>
                    </button>
                @endunless
            </div>
        </div>
    @else

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
                <div class="min-w-0 flex-1">
                    <p class="text-xs font-semibold tracking-wide text-accent-ink uppercase dark:text-accent-ink-dark">{{ $reading['passage_title'] ?? 'Reading' }}</p>
                    <p class="mt-2 text-sm leading-relaxed text-ink dark:text-ink-dark">{!! $passageHtml !!}</p>
                    @if (! empty($reading['highlighted_phrases'] ?? []))
                        <p class="mt-2 flex items-center gap-3 text-[11px] text-ink-faint dark:text-ink-faint-dark">
                            <span class="inline-flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full bg-success/60 dark:bg-success-dark/60"></span> words you already know</span>
                            <span class="inline-flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full bg-accent/60 dark:bg-accent-dark/60"></span> new words — tap for meaning</span>
                        </p>
                    @endif
                </div>
            </div>
        </div>

        {{-- New words (Epic F): the passage's brand-new vocabulary, with a
             plain-language meaning, part of speech, and an example — shown
             directly, not behind a hover-only tooltip (that never worked
             on mobile, same fix already made for Vocabulary Builder). --}}
        @if (count($this->newWords()))
            <div class="mt-4">
                <p class="text-sm font-semibold text-ink dark:text-ink-dark">New words</p>
                <div class="mt-2 space-y-2">
                    @foreach ($this->newWords() as $word)
                        <div class="rounded-xl border border-line p-3 dark:border-line-dark">
                            <div class="flex items-baseline gap-2">
                                <p class="text-sm font-bold text-ink dark:text-ink-dark">{{ $word['phrase'] }}</p>
                                @if (! empty($word['pos']))
                                    <p class="text-xs text-ink-faint italic dark:text-ink-faint-dark">{{ $word['pos'] }}</p>
                                @endif
                            </div>
                            <p class="text-xs text-ink-faint dark:text-ink-faint-dark">{{ $word['definition'] }}</p>
                            @if (! empty($word['example']))
                                <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">"{{ $word['example'] }}"</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @unless ($readOnly)
            <div class="mt-4">
                <p class="text-sm font-semibold text-ink dark:text-ink-dark">Quick check</p>
                <p class="text-xs text-ink-soft dark:text-ink-soft-dark">True or false — just a warm-up, skip anytime.</p>
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
                            wire:model.live="answers.{{ $index }}"
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
                        <x-almost-reveal-notice :show="$this->isAlmostRevealing($index)" />
                        <x-reveal-offer
                            :show="$offerReveal[$index] ?? false"
                            :struggling="$this->run->isStruggling()"
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

        {{-- Always on screen, disabled until every answer is written.
             answers use wire:model.live, so completeness is already known
             server-side on every keystroke — no extra Alpine tracking. --}}
        @unless ($readOnly)
            <div class="mt-4">
                <x-continue-button
                    on-click="$wire.save()"
                    wire-target="checkOne,revealCorrection,declineReveal,save"
                    loading-label="Checking your answers…"
                    ready-when="{{ collect($answers)->every(fn ($a) => trim((string) $a) !== '') ? 'true' : 'false' }}"
                    hint="Answer every question to continue"
                />
            </div>
        @endunless
    </div>

    <div class="mt-4">
        <x-substep-nav index-var="activeSubstep" :total="$totalSubsteps" />
    </div>
    @endif
</div>
