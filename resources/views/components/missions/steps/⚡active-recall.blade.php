<?php

use App\Livewire\Concerns\TracksAiUsage;
use App\Models\Evidence;
use App\Models\MissionRun;
use App\Services\SentenceChecker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Livewire\Component;

new class extends Component
{
    use TracksAiUsage;

    public MissionRun $run;

    public bool $readOnly = false;

    /** @var array<string, array<int, string>> */
    public array $answers = [];

    /** @var array<int, array{severity: string, hint: string}> keyed by index within the "expressions" section */
    public array $expressionFeedback = [];

    /**
     * Keyed by section then index — AI verdicts for "listening_facts" and
     * "present_simple_sentences", the two sections with no fixed ground
     * truth to compare against locally.
     *
     * @var array<string, array<int, array{severity: string, hint: string, checkedText: string}>>
     */
    public array $aiFeedback = [];

    /** @var array<string, array<int, string>> keyed by section then index — per-input check failure message */
    public array $checkErrors = [];

    /**
     * True once Continue has passed the minimum-filled check and Evidence
     * is saved — the step then shows a recap (including how many of the
     * learner's own words they actually recalled) before they dismiss it
     * with proceed() below.
     */
    public bool $completed = false;

    /** @var array{correct: int, total: int}|null */
    public ?array $recallResult = null;

    /** @var array{good: int, total: int}|null */
    public ?array $listeningFactsResult = null;

    /** @var array{good: int, total: int}|null */
    public ?array $presentSimpleResult = null;

    /**
     * The learner's next DUE recurring-error pattern (see
     * ErrorPatternReview / User::syncErrorPatternReview()) — null until a
     * pattern has both recurred across 2+ missions AND is actually due on
     * its own spaced-repetition schedule, not just "whatever the single
     * worst pattern happens to be right now". Deliberately NOT one of the
     * paginated sections() above: it's cross-mission spaced-repetition
     * practice, entirely separate from this mission's own recall content,
     * so it's shown as its own small optional card and saved as its own
     * Evidence phase (never blocks Continue).
     */
    public ?string $recurringErrorCategory = null;

    public ?string $recurringErrorExample = null;

    public ?string $recurringErrorCorrection = null;

    public string $recurringPracticeAnswer = '';

    /** @var array{severity: string, hint: string}|null */
    public ?array $recurringPracticeFeedback = null;

    public ?string $recurringPracticeError = null;

    public function mount(): void
    {
        $saved = $this->readOnly
            ? json_decode($this->run->latestEvidence('active_recall')?->content_ref ?? '{}', true)
            : [];

        foreach ($this->sections() as $section) {
            $this->answers[$section['key']] = array_pad($saved[$section['key']] ?? [], $section['count'], '');
        }

        // Local (no AI call) scoring only — safe to recompute on every
        // review visit. The AI-checked sections' quality summary is only
        // ever shown right after save(), never recomputed on review, to
        // avoid a live AI call on every page visit (same as every other
        // AI-checked step: readOnly never re-checks).
        if ($this->readOnly) {
            $this->scoreExpressions();
        }

        $this->loadRecurringPractice();
    }

    /**
     * Live: whichever recurring pattern is actually due right now (real
     * SM-2 scheduling, see ErrorPatternReview) — recomputed fresh on
     * every visit, so it's fine if which one shows up here drifts as more
     * Error Log entries are logged in later missions. Read-only: replays
     * exactly what was shown and submitted at the time, from this run's
     * own saved Evidence, regardless of whether that category still
     * happens to be due today (its schedule may well have moved on by a
     * later, separate review elsewhere).
     */
    private function loadRecurringPractice(): void
    {
        if ($this->readOnly) {
            $saved = json_decode($this->run->latestEvidence('active_recall_spaced_practice')?->content_ref ?? 'null', true);

            if (is_array($saved)) {
                $this->recurringErrorCategory = $saved['category'] ?? null;
                $this->recurringErrorExample = $saved['example'] ?? null;
                $this->recurringErrorCorrection = $saved['correction'] ?? null;
                $this->recurringPracticeAnswer = $saved['answer'] ?? '';
                $this->recurringPracticeFeedback = $saved['feedback'] ?? null;
            }

            return;
        }

        $due = $this->run->learner->errorPatternReviews()
            ->where('next_review_at', '<=', now())
            ->orderBy('next_review_at')
            ->first();

        $this->recurringErrorCategory = $due?->category;
        $this->recurringErrorExample = $due?->last_error;
        $this->recurringErrorCorrection = $due?->last_correction;
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

    /**
     * The "expressions" section has a known ground truth (the learner's own
     * real selection from Vocabulary Builder), so — unlike the other,
     * open-ended recall sections — it can be verified locally, no AI
     * needed, the same way Grammar in Context's Quick Check compares
     * against an already-known correct answer.
     */
    public function checkExpression(int $index): void
    {
        $answer = trim($this->answers['expressions'][$index] ?? '');

        if ($answer === '') {
            return;
        }

        $this->runExpressionCheck($index, $answer);
    }

    private function runExpressionCheck(int $index, string $answer): void
    {
        $target = $this->run->selectedVocabularyWords();

        // Nothing to verify against yet — stay silent rather than falsely
        // marking every answer wrong.
        if (! $target) {
            return;
        }

        $isCorrect = collect($target)->contains(fn ($word) => $this->normalize($word) === $this->normalize($answer));

        $this->expressionFeedback[$index] = $isCorrect
            ? ['severity' => 'none', 'hint' => '']
            : ['severity' => 'minor', 'hint' => "That doesn't match one of your own words — try again."];
    }

    private function normalize(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', strtolower(rtrim(trim($text), '.!?'))));
    }

    /**
     * "listening_facts" and "present_simple_sentences" have no fixed ground
     * truth to compare against locally, so they use the standard AI check
     * pattern instead (see EOS-009 §8) — grounded in the real Listening
     * topic summary for the first, and the standard present-simple
     * judgment (reused verbatim from Grammar in Context) for the second.
     * Deliberately non-blocking, same as the "expressions" section: this
     * step is honest self-testing, not a pass/fail gate.
     */
    public function checkListeningFact(int $index): void
    {
        $this->checkOpenField('listening_facts', $index);
    }

    public function checkPresentSimpleSentence(int $index): void
    {
        $this->checkOpenField('present_simple_sentences', $index);
    }

    private function checkOpenField(string $section, int $index): void
    {
        $answer = trim($this->answers[$section][$index] ?? '');

        if ($answer === '') {
            $this->checkErrors[$section][$index] = 'Write something first.';

            return;
        }

        unset($this->checkErrors[$section][$index]);
        $this->runSentenceCheck($section, $index, $answer);
    }

    private function runSentenceCheck(string $section, int $index, string $text): void
    {
        try {
            $data = match ($section) {
                'listening_facts' => app(SentenceChecker::class)->check(
                    judgment: 'Judge whether what the learner wrote is a genuine, natural, complete English '
                        .'sentence about the SAME GENERAL TOPIC as a B1-level listening they heard earlier — '
                        .'recalled from memory, without looking back at it (not just a bare word or fragment, '
                        .'and not about a completely different topic). This is a coarse topic check only — do '
                        .'NOT fact-check specific details against the topic summary (who said what, exact '
                        .'opinions, etc.); the summary is background, not a source to grade accuracy against.',
                    majorCriteria: 'it is just a bare word or fragment (not a real sentence), it is about a '
                        .'completely different topic than the listening',
                    context: $this->listeningRecallContext(),
                    text: $text,
                    extraGuidance: 'Treat anything on-topic and correctly formed as "none", even if a small '
                        .'detail is debatable — never claim the learner\'s facts are wrong, since you were only '
                        .'given a short summary, not the full listening.'.$this->run->aiToneGuidance(),
                ),
                'present_simple_sentences' => app(SentenceChecker::class)->check(
                    judgment: 'Judge whether the learner wrote a genuine, natural personal sentence, correctly '
                        .'using the present simple tense.',
                    majorCriteria: 'the verb is not in the present simple tense, or it is not a genuine personal '
                        .'statement',
                    context: 'a personal sentence using the present simple tense',
                    text: $text,
                    extraGuidance: $this->run->aiToneGuidance(),
                ),
                default => null,
            };

            if ($data === null) {
                return;
            }
            $this->recordGeminiCall();

            $this->aiFeedback[$section][$index] = $data + ['checkedText' => $text];
        } catch (ConnectionException|RequestException) {
            // RequestException's message carries the raw HTTP response body
            // (which can be an arbitrarily large error page, not a clean
            // API message) — never show that to the learner.
            $this->checkErrors[$section][$index] = "Couldn't reach the AI service — please try again.";
        } catch (Throwable $e) {
            $this->checkErrors[$section][$index] = "Couldn't check this one: {$e->getMessage()}";
        }
    }

    /**
     * Checks the spaced-repetition sentence against the CONCRETE example
     * the learner actually got wrong before (not just an abstract category
     * name) — grounds the AI in a real originally-wrong/corrected pair so
     * it can judge whether the new sentence genuinely avoids the same
     * pattern. Same non-blocking try/catch idiom as runSentenceCheck():
     * this never gates Continue.
     */
    public function checkRecurringPractice(): void
    {
        $answer = trim($this->recurringPracticeAnswer);

        if ($answer === '') {
            $this->recurringPracticeError = 'Write something first.';

            return;
        }

        $this->recurringPracticeError = null;

        try {
            $this->recurringPracticeFeedback = app(SentenceChecker::class)->check(
                judgment: 'The learner previously made this mistake: "'.$this->recurringErrorExample.'" '
                    .'(corrected: "'.$this->recurringErrorCorrection.'"). Judge whether the NEW sentence below is '
                    .'a genuine, natural personal sentence that correctly avoids repeating that same error pattern.',
                majorCriteria: 'the sentence repeats the same mistake, or is not a genuine sentence',
                context: 'a new sentence practicing the same grammar/vocabulary pattern the learner previously got wrong',
                text: $answer,
                extraGuidance: $this->run->aiToneGuidance(),
            );
            $this->recordGeminiCall();
        } catch (ConnectionException|RequestException) {
            $this->recurringPracticeError = "Couldn't reach the AI service — please try again.";
        } catch (Throwable $e) {
            $this->recurringPracticeError = "Couldn't check this one: {$e->getMessage()}";
        }
    }

    private function listeningRecallContext(): string
    {
        $context = 'a sentence recalling something the learner remembers from a B1-level listening, without looking back at it';
        $summary = $this->run->mission->stepContent('listening')['topic_summary'] ?? null;

        return $summary ? "{$context}. The listening was about: {$summary}" : $context;
    }

    /**
     * Auto-checks any filled-but-unchecked (or edited-since-last-check)
     * answer in an AI-checked section — mirrors the standard "Continue
     * auto-checks" pattern, but purely to populate the recap; never blocks.
     */
    private function scoreOpenSection(string $section): ?array
    {
        $filled = collect($this->answers[$section] ?? [])->map(fn ($a) => trim((string) $a))->filter()->values();

        if ($filled->isEmpty()) {
            return null;
        }

        foreach ($filled as $index => $text) {
            $alreadyChecked = ($this->aiFeedback[$section][$index]['checkedText'] ?? null) === $text;

            if (! $alreadyChecked) {
                $this->runSentenceCheck($section, $index, $text);
            }
        }

        $good = collect($this->aiFeedback[$section] ?? [])
            ->filter(fn ($feedback) => ($feedback['severity'] ?? null) === 'none')
            ->count();

        return ['good' => $good, 'total' => $filled->count()];
    }

    /**
     * Scores the "expressions" section against the learner's real selected
     * words for the recap — purely informational, never gates Continue,
     * since the point is honest self-testing, not a pass/fail bar.
     */
    private function scoreExpressions(): void
    {
        $target = $this->run->selectedVocabularyWords();

        if (! $target || ! isset($this->answers['expressions'])) {
            return;
        }

        $filled = collect($this->answers['expressions'])->map(fn ($a) => trim((string) $a))->filter()->values();

        foreach ($filled as $index => $answer) {
            $this->runExpressionCheck($index, $answer);
        }

        $correct = $filled->filter(
            fn ($answer) => collect($target)->contains(fn ($word) => $this->normalize($word) === $this->normalize($answer))
        )->count();

        $this->recallResult = ['correct' => $correct, 'total' => $filled->count()];
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

        $this->scoreExpressions();
        $this->listeningFactsResult = $this->scoreOpenSection('listening_facts');
        $this->presentSimpleResult = $this->scoreOpenSection('present_simple_sentences');

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'active_recall',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode($result),
        ]);

        $this->saveRecurringPractice();

        $this->dispatch('clear-draft', prefix: $this->draftPrefix());

        // Progress is already saved — this only decides what the learner
        // sees next: the recap, which they dismiss with proceed() below.
        $this->completed = true;
    }

    /**
     * Extra-phase Evidence, same pattern as Writing's 'writing_feedback' —
     * 'active_recall_spaced_practice' is deliberately NOT a real step key
     * in any mission's stepKeys(), so MissionRun::currentStepKey() and
     * dayProgress() silently ignore it and it can never block or advance
     * progress. Entirely skipped if the learner left it blank (it's
     * optional, and there may be no recurring pattern to show at all).
     */
    private function saveRecurringPractice(): void
    {
        if ($this->recurringErrorCategory === null || trim($this->recurringPracticeAnswer) === '') {
            return;
        }

        $alreadyChecked = ($this->recurringPracticeFeedback !== null)
            && $this->recurringPracticeError === null;

        if (! $alreadyChecked) {
            $this->checkRecurringPractice();
        }

        // Advances the real spaced-repetition schedule exactly once per
        // submission (not per "Check" click) — severity maps onto SM-2's
        // quality scale the same way My Words' own AI-checked review does.
        if ($this->recurringPracticeFeedback !== null) {
            $review = $this->run->learner->errorPatternReviews()->where('category', $this->recurringErrorCategory)->first();

            $review?->review(match ($this->recurringPracticeFeedback['severity']) {
                'major' => 1,
                'minor' => 4,
                default => 5,
            });
        }

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'active_recall_spaced_practice',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode([
                'category' => $this->recurringErrorCategory,
                'example' => $this->recurringErrorExample,
                'correction' => $this->recurringErrorCorrection,
                'answer' => trim($this->recurringPracticeAnswer),
                'feedback' => $this->recurringPracticeFeedback,
            ]),
        ]);
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
        return "eos-draft:{$this->run->id}:active_recall:";
    }
};
?>

@php
    $draftPrefix = $this->draftPrefix();
    $sections = $this->sections();
    $sectionsCount = count($sections);
    $initialFilled = collect($sections)->mapWithKeys(fn ($section) => [
        $section['key'] => collect($this->answers[$section['key']] ?? [])->map(fn ($a) => trim((string) $a) !== '')->values(),
    ]);
@endphp

<div
    class="space-y-6"
    x-data="{
        filled: {{ $initialFilled->toJson() }},
        dismissed: {},
        activeSection: 0,
        countFilled(section) { return (this.filled[section] || []).filter(Boolean).length },
    }"
>
    <x-hook :text="$run->mission->stepContent('active_recall')['hook'] ?? null" />

    @if ($completed || ($readOnly && $recallResult))
        <div class="space-y-4 rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
            <div>
                <p class="inline-flex items-center gap-1 text-xs font-semibold tracking-wide text-success uppercase dark:text-success-dark">
                    @svg('heroicon-o-check-circle', 'h-4 w-4')
                    Active Recall complete
                </p>
                @if ($recallResult && $recallResult['total'] > 0)
                    <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">
                        You correctly recalled {{ $recallResult['correct'] }} of {{ $recallResult['total'] }} of your own words.
                    </p>
                @endif
                @if ($listeningFactsResult && $listeningFactsResult['total'] > 0)
                    <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">
                        {{ $listeningFactsResult['good'] }} of {{ $listeningFactsResult['total'] }} things you recalled about the listening were clear and on-topic.
                    </p>
                @endif
                @if ($presentSimpleResult && $presentSimpleResult['total'] > 0)
                    <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">
                        {{ $presentSimpleResult['good'] }} of {{ $presentSimpleResult['total'] }} sentences correctly used the present simple.
                    </p>
                @endif
            </div>
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

    @if (! $completed && $recurringErrorCategory && (! $readOnly || trim($recurringPracticeAnswer) !== ''))
        <div class="space-y-2 rounded-2xl border border-line bg-surface-sunken p-4 dark:border-line-dark dark:bg-surface-sunken-dark">
            <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Spaced practice — a pattern you keep mixing up</p>
            <p class="text-sm text-ink-soft dark:text-ink-soft-dark">
                You've mixed this up before: <span class="text-red-600 line-through decoration-red-500">{{ $recurringErrorExample }}</span>
                <span class="text-success dark:text-success-dark">{{ $recurringErrorCorrection }}</span>
            </p>
            <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Write a new sentence of your own that avoids the same mistake.</p>
            <div class="flex items-center gap-2">
                <input
                    type="text"
                    wire:model="recurringPracticeAnswer"
                    placeholder="Write a new sentence…"
                    @readonly($readOnly)
                    wire:loading.attr="disabled"
                    wire:target="checkRecurringPractice"
                    class="w-full rounded-lg border border-line bg-transparent px-2 py-1 text-sm text-ink disabled:opacity-50 dark:border-line-dark dark:text-ink-dark"
                >
                @unless ($readOnly)
                    <button
                        type="button"
                        wire:click="checkRecurringPractice"
                        wire:loading.attr="disabled"
                        wire:target="checkRecurringPractice"
                        class="shrink-0 cursor-pointer rounded-full border border-line px-2.5 py-1 text-xs font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-dark"
                    >
                        <span wire:loading.remove wire:target="checkRecurringPractice">Check</span>
                        <span wire:loading wire:target="checkRecurringPractice">Checking…</span>
                    </button>
                @endunless
            </div>
            <x-ai-thinking wire:loading wire:target="checkRecurringPractice" />
            <x-severity-feedback :feedback="$recurringPracticeFeedback" :error="$recurringPracticeError" />
        </div>
    @endif

    @unless ($completed)
        <div>
            <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Active Recall</p>
            <p class="text-xs text-ink-faint dark:text-ink-faint-dark">{{ $this->run->mission->stepContent('active_recall')['instruction'] ?? '' }}</p>
        </div>

        <div class="mb-2">
            <x-progress-bar>
                <div
                    class="h-full rounded-full bg-accent transition-all duration-300 dark:bg-accent-dark"
                    :style="`width: ${(activeSection + 1) / {{ $sectionsCount }} * 100}%`"
                ></div>
                <x-slot:label>
                    <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">
                        Part <span x-text="activeSection + 1"></span> of {{ $sectionsCount }}
                    </p>
                </x-slot:label>
            </x-progress-bar>
        </div>

        @foreach ($sections as $section)
            <div x-show="activeSection === {{ $loop->index }}" x-cloak>
                <p class="text-sm font-semibold text-ink dark:text-ink-dark">{{ $section['label'] }}</p>

                @unless ($readOnly)
                    <div class="mt-2">
                        <x-progress-bar>
                            <div
                                class="h-full rounded-full transition-all duration-300"
                                :class="countFilled('{{ $section['key'] }}') >= {{ $section['count'] }} ? 'bg-success dark:bg-success-dark' : 'bg-accent dark:bg-accent-dark'"
                                :style="`width: ${Math.min(countFilled('{{ $section['key'] }}'), {{ $section['count'] }}) / {{ $section['count'] }} * 100}%`"
                            ></div>
                            <x-slot:label>
                                <p
                                    class="text-xs font-semibold transition-colors"
                                    :class="countFilled('{{ $section['key'] }}') >= {{ $section['count'] }} ? 'text-success dark:text-success-dark' : 'text-ink-soft dark:text-ink-soft-dark'"
                                    x-text="`${Math.min(countFilled('{{ $section['key'] }}'), {{ $section['count'] }})} of {{ $section['count'] }} written`"
                                ></p>
                            </x-slot:label>
                        </x-progress-bar>
                    </div>
                @endunless

                @php
                    $checkMethod = match ($section['key']) {
                        'expressions' => 'checkExpression',
                        'listening_facts' => 'checkListeningFact',
                        'present_simple_sentences' => 'checkPresentSimpleSentence',
                        default => null,
                    };
                    $isAiChecked = in_array($section['key'], ['listening_facts', 'present_simple_sentences'], true);
                @endphp

                <div
                    wire:loading.class="pointer-events-none"
                    wire:target="checkExpression,checkListeningFact,checkPresentSimpleSentence,save"
                    class="mt-2 space-y-2"
                >
                    @for ($i = 0; $i < $section['count']; $i++)
                        @php
                            $itemFeedback = match (true) {
                                $section['key'] === 'expressions' => $expressionFeedback[$i] ?? null,
                                $isAiChecked => $aiFeedback[$section['key']][$i] ?? null,
                                default => null,
                            };
                            $itemError = $checkErrors[$section['key']][$i] ?? null;
                        @endphp
                        <div>
                            <div class="flex items-center gap-2">
                                <input
                                    type="text"
                                    wire:model="answers.{{ $section['key'] }}.{{ $i }}"
                                    placeholder="{{ $i + 1 }}."
                                    x-on:input="filled['{{ $section['key'] }}'][{{ $i }}] = $el.value.trim() !== ''; dismissed['{{ $section['key'] }}_{{ $i }}'] = true"
                                    @unless ($readOnly)
                                        x-draft="{ key: '{{ $draftPrefix }}answers.{{ $section['key'] }}.{{ $i }}', field: 'answers.{{ $section['key'] }}.{{ $i }}' }"
                                    @endunless
                                    @readonly($readOnly)
                                    wire:loading.attr="disabled"
                                    wire:target="checkExpression,checkListeningFact,checkPresentSimpleSentence,save"
                                    class="w-full rounded-lg border border-line bg-transparent px-2 py-1 text-sm text-ink disabled:opacity-50 dark:border-line-dark dark:text-ink-dark"
                                >
                                <x-filled-check show="filled['{{ $section['key'] }}'][{{ $i }}]" />
                                @if ($checkMethod && ! $readOnly)
                                    <x-check-button :method="$checkMethod" :index="$i" :key-prefix="$section['key'].'_'" wire-target="checkExpression,checkListeningFact,checkPresentSimpleSentence,save" />
                                @endif
                            </div>

                            @if ($isAiChecked && ! $readOnly)
                                <x-ai-thinking wire:loading wire:target="{{ $checkMethod }}({{ $i }}), save" class="mt-2" />
                            @endif

                            @if ($checkMethod)
                                <div x-show="!dismissed['{{ $section['key'] }}_{{ $i }}']" x-transition.opacity.duration.300ms>
                                    <x-severity-feedback :feedback="$itemFeedback" :error="$itemError" />
                                </div>
                            @endif
                        </div>
                    @endfor
                </div>
            </div>
        @endforeach

        <div x-show="activeSection === {{ $sectionsCount - 1 }}" x-cloak>
            @error('answers')
                <p class="text-sm text-red-600">{{ $message }}</p>
            @enderror

            @unless ($readOnly)
                <x-continue-button
                    on-click="Object.keys(dismissed).forEach(k => dismissed[k] = true); $wire.save().then(() => { dismissed = {} })"
                    wire-target="checkExpression,checkListeningFact,checkPresentSimpleSentence,save"
                    loading-label="Checking your answers…"
                />
            @endunless
        </div>

        <div class="mt-4">
            <x-substep-nav index-var="activeSection" :total="$sectionsCount" />
        </div>
    @endunless
</div>
