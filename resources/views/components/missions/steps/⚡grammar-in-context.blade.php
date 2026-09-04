<?php

use App\Livewire\Concerns\TracksAiUsage;
use App\Livewire\Concerns\TracksCheckAttempts;
use App\Models\Evidence;
use App\Models\MissionRun;
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

    /**
     * True once the learner has moved into practice — kept server-side (not
     * just in Alpine's x-data) because a Livewire re-render can re-run the
     * x-data init expression, and without this it would snap back to
     * 'lesson' every time, e.g. after clicking Check.
     */
    public bool $practiceStarted = false;

    /** @var array<int, string> */
    public array $frequencySentences = [];

    /** @var array<int, array{severity: string, hint: string, checkedText: string}> keyed by frequencySentences index */
    public array $feedback = [];

    /** @var array<int, string> keyed by frequencySentences index — per-input check failure message */
    public array $checkErrors = [];

    /**
     * Quick Check's result — set client-side by <x-quick-round>'s
     * on-complete Alpine statement once the round finishes. Ungraded and
     * skippable like every Quick Round, so this stays null if the learner
     * skips it or never gets there.
     *
     * @var array{correct: int, total: int}|null
     */
    public ?array $quickCheckScore = null;

    public function mount(): void
    {
        if (! $this->readOnly) {
            return;
        }

        $data = json_decode($this->run->latestEvidence('grammar_in_context')?->content_ref ?? '{}', true);
        $starters = $this->run->mission->stepContent('grammar_in_context')['frequency_starters'] ?? [];
        $savedSentences = collect($data['frequency_sentences'] ?? [])->keyBy('starter');

        foreach ($starters as $index => $starter) {
            $this->frequencySentences[$index] = $savedSentences[$starter]['completion'] ?? '';
        }

        $this->quickCheckScore = $data['quick_check_score'] ?? null;
    }

    /**
     * @return list<array{prompt: string, options: list<string>, correct: int, difficulty?: string}>
     */
    public function quickCheckCards(): array
    {
        $items = $this->run->mission->stepContent('grammar_in_context')['quick_check'] ?? [];

        return collect($items)
            ->map(fn ($item) => [
                'prompt' => $item['wrong'],
                'options' => $item['options'],
                'correct' => $item['correct'],
                ...(isset($item['difficulty']) ? ['difficulty' => $item['difficulty']] : []),
            ])
            ->all();
    }

    public function startPractice(): void
    {
        $this->practiceStarted = true;
    }

    public function checkOne(int $index): void
    {
        $starters = $this->run->mission->stepContent('grammar_in_context')['frequency_starters'] ?? [];
        $starter = $starters[$index] ?? null;

        if (! $starter) {
            return;
        }

        $sentence = trim($this->frequencySentences[$index] ?? '');

        if ($sentence === '') {
            $this->checkErrors[$index] = 'Write something first.';

            return;
        }

        $this->runCheck($index, $starter, $sentence);
    }

    /**
     * Asks the shared SentenceChecker to judge one frequency sentence,
     * storing the verdict tagged with the exact text it applies to, so a
     * later edit doesn't leave a stale verdict attached to different text.
     * See EOS-009 §8 "الگوی چک جمله" for the shared rules.
     */
    private function runCheck(int $index, string $starter, string $sentence): void
    {
        unset($this->checkErrors[$index]);

        try {
            $data = app(SentenceChecker::class)->check(
                judgment: 'Judge whether the learner finished this sentence starter into a true, natural '
                    .'personal sentence, correctly using the present simple tense.',
                majorCriteria: 'the verb is not in the present simple tense, the sentence does not actually '
                    .'continue the given starter, or it is not a genuine personal statement',
                context: "a personal sentence that starts with \"{$starter}\" and continues in the present simple tense",
                text: $sentence,
                extraGuidance: $this->run->aiToneGuidance(),
            );
            $this->recordGeminiCall();

            $this->feedback[$index] = $data + ['checkedText' => $sentence];
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
     * After 3 failed attempts on the same sentence, the learner can ask the
     * AI to just write the corrected version — see TracksCheckAttempts.
     */
    public function revealCorrection(int $index): void
    {
        $starters = $this->run->mission->stepContent('grammar_in_context')['frequency_starters'] ?? [];
        $starter = $starters[$index] ?? null;
        $sentence = trim($this->frequencySentences[$index] ?? '');

        if (! $starter || $sentence === '') {
            return;
        }

        $this->revealCorrectionFor(
            key: $index,
            context: "a personal sentence that starts with \"{$starter}\" and continues in the present simple tense",
            text: $sentence,
            errorBagKey: $index,
            onCorrected: function (string $corrected) use ($index) {
                $this->frequencySentences[$index] = $corrected;
                $this->feedback[$index] = ['severity' => 'none', 'hint' => '', 'checkedText' => $corrected];
            },
        );
    }

    public function declineReveal(int $index): void
    {
        $this->declineCheckReveal($index);
    }

    private function normalize(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', strtolower(rtrim(trim($text), '.!?'))));
    }

    /**
     * Wraps the first occurrence of the adverb in an example sentence with
     * a highlight so its position in the sentence — the whole point of the
     * word-order rule — is visible at a glance, not just stated in prose.
     */
    public function highlightAdverb(string $example, string $adverb): string
    {
        return preg_replace(
            '/\b'.preg_quote($adverb, '/').'\b/',
            '<strong class="text-ink underline decoration-2 underline-offset-2 dark:text-ink-dark">$0</strong>',
            e($example),
            1
        );
    }

    public function save(): void
    {
        $starters = $this->run->mission->stepContent('grammar_in_context')['frequency_starters'] ?? [];

        $filledSentences = collect($this->frequencySentences)
            ->map(fn ($s, $i) => ['index' => $i, 'starter' => $starters[$i] ?? null, 'text' => trim((string) $s)])
            ->filter(fn ($s) => $s['text'] !== '');

        if ($filledSentences->count() < 3) {
            $this->addError('frequencySentences', 'Complete at least 3 sentences before continuing.');

            return;
        }

        // Every filled sentence needs a fresh verdict before Continue is
        // allowed through — reuse an existing one only if it was checked
        // against this exact text (an edit since the last check invalidates it).
        foreach ($filledSentences as $item) {
            $alreadyChecked = ($this->feedback[$item['index']]['checkedText'] ?? null) === $item['text'];

            if (! $alreadyChecked) {
                $this->runCheck($item['index'], $item['starter'], $item['text']);
            }
        }

        $hasMajorIssue = $filledSentences->contains(
            fn ($item) => ($this->feedback[$item['index']]['severity'] ?? null) === 'major'
        );

        if ($hasMajorIssue) {
            $this->addError('frequencySentences', 'Fix the highlighted sentence before continuing.');

            return;
        }

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'grammar_in_context',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode([
                'frequency_sentences' => $filledSentences
                    ->map(fn ($s) => ['starter' => $s['starter'], 'completion' => $s['text']])
                    ->values(),
                // Optional bonus practice — saved if attempted, but never
                // required and never blocks Continue.
                'quick_check_score' => $this->quickCheckScore,
            ]),
        ]);

        $this->syncGrammarPoint($filledSentences->first());

        $this->dispatch('clear-draft', prefix: $this->draftPrefix());
        $this->redirect(route('missions.show', $this->run->mission), navigate: true);
    }

    /**
     * Enrolls this mission's grammar focus into the learner's spaced-
     * repetition queue (see User::syncGrammarPoint(), and the Review page)
     * — unconditional, every time, unlike the error-pattern system's
     * recurrence gate (a mission only ever teaches its own focus once).
     */
    private function syncGrammarPoint(array $firstFilledSentence): void
    {
        $content = $this->run->mission->stepContent('grammar_in_context');
        $focus = $content['focus'] ?? null;

        if (! $focus) {
            return;
        }

        // The starter (e.g. "I usually") is shown as a label next to the
        // input, not enforced as an excluded prefix — a learner may type
        // just the continuation OR the whole sentence starter-and-all.
        // Only prepend the starter when the typed text doesn't already
        // start with it, so the example reads naturally either way.
        $starter = $firstFilledSentence['starter'] ?? '';
        $text = trim($firstFilledSentence['text'] ?? '');
        $exampleSentence = ($starter !== '' && ! str_starts_with(strtolower($text), strtolower($starter)))
            ? trim("{$starter} {$text}")
            : $text;
        $ruleReminder = $content['lesson']['intro'] ?? $focus;

        $this->run->learner->syncGrammarPoint(
            focus: $focus,
            exampleSentence: $exampleSentence,
            ruleReminder: $ruleReminder,
            missionCode: $this->run->mission->code,
            sourceMissionRunId: $this->run->id,
        );
    }

    /**
     * Must match the prefix embedded in the Blade template's x-draft
     * attributes exactly — both build it the same way from the run id.
     */
    public function draftPrefix(): string
    {
        return "eos-draft:{$this->run->id}:grammar_in_context:";
    }
};
?>

@php
    $grammar = $run->mission->stepContent('grammar_in_context');
    $lesson = $grammar['lesson'] ?? [];
    $lessonSections = ['conjugation', 'questions', 'frequency'];
    $initialFilled = collect($frequencySentences)->map(fn ($s) => trim((string) $s) !== '')->values();
    $draftPrefix = $this->draftPrefix();
@endphp

<div
    class="space-y-6"
    x-data="{
        phase: '{{ $readOnly || $practiceStarted ? 'practice' : 'lesson' }}',
        lessonStep: 0,
        lessonSections: {{ count($lessonSections) }},
        filled: {{ $initialFilled->toJson() }},
        dismissed: {},
        get filledCount() { return this.filled.filter(Boolean).length },
        get progressMessage() {
            const n = this.filledCount;
            if (n === 0) return 'Fill in your first sentence below.';
            if (n === 1) return 'Nice start — keep going!';
            if (n === 2) return 'One more and you\'re ready to continue!';
            return 'Ready to continue — write more if you like!';
        },
    }"
>
    <x-hook :text="$grammar['hook'] ?? null" />

    <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">{{ $grammar['focus'] ?? 'Grammar' }}</p>

    @unless ($readOnly)
        <div x-show="phase === 'lesson'" x-cloak class="space-y-4">
            @if (! empty($lesson['intro']))
                <p class="text-sm text-ink-soft dark:text-ink-soft-dark">{{ $lesson['intro'] }}</p>
            @endif

            <div>
                <x-progress-bar>
                    <div
                        class="h-full rounded-full bg-accent transition-all duration-300 dark:bg-accent-dark"
                        :style="`width: ${(lessonStep + 1) / lessonSections * 100}%`"
                    ></div>
                    <x-slot:label>
                        <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">
                            Lesson <span x-text="lessonStep + 1"></span> of <span x-text="lessonSections"></span>
                        </p>
                    </x-slot:label>
                </x-progress-bar>
            </div>

            {{-- A: how the verb changes --}}
            <div x-show="lessonStep === 0" x-cloak class="rounded-2xl border border-line bg-surface-sunken p-4 dark:border-line-dark dark:bg-surface-sunken-dark">
                <p class="text-sm font-bold">A · The verb changes with he / she / it</p>
                <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">
                    With <strong>I / we / you / they</strong> the verb stays simple. With <strong>he / she / it</strong> it takes an <strong>-s</strong> (or an irregular form, like <em>have → has</em>).
                </p>
                <div class="mt-3 space-y-2">
                    @foreach ($lesson['conjugation_examples'] ?? [] as $example)
                        <div class="grid grid-cols-2 gap-2 rounded-lg border border-line p-2 text-sm text-ink dark:border-line-dark dark:text-ink-dark">
                            <p>{{ $example['base'] }}</p>
                            <p class="font-semibold">{{ $example['third_person'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- B: questions and negatives --}}
            <div x-show="lessonStep === 1" x-cloak class="rounded-2xl border border-line bg-surface-sunken p-4 dark:border-line-dark dark:bg-surface-sunken-dark">
                <p class="text-sm font-bold">B · Questions and negatives use do / does</p>
                <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">
                    Use <strong>do</strong>/<strong>don't</strong> with I/we/you/they, and <strong>does</strong>/<strong>doesn't</strong> with he/she/it — the main verb goes back to its simple form.
                </p>
                <div class="mt-3 space-y-2 text-sm">
                    <p class="rounded-lg border border-line p-2 text-ink dark:border-line-dark dark:text-ink-dark">{{ $lesson['question_example'] ?? '' }}</p>
                    <p class="rounded-lg border border-line p-2 text-ink dark:border-line-dark dark:text-ink-dark">{{ $lesson['question_example_does'] ?? '' }}</p>
                    <p class="rounded-lg border border-line p-2 text-ink dark:border-line-dark dark:text-ink-dark">{{ $lesson['negative_example'] ?? '' }}</p>
                    <p class="rounded-lg border border-line p-2 text-ink dark:border-line-dark dark:text-ink-dark">{{ $lesson['negative_example_does'] ?? '' }}</p>
                </div>
            </div>

            {{-- C: word order for frequency adverbs --}}
            <div x-show="lessonStep === 2" x-cloak class="rounded-2xl border border-line bg-surface-sunken p-4 dark:border-line-dark dark:bg-surface-sunken-dark">
                <p class="text-sm font-bold">C · Where the frequency word goes</p>
                <div class="mt-2 flex flex-wrap items-center gap-1.5 text-xs">
                    @foreach ($lesson['frequency_scale'] ?? [] as $word)
                        <span class="rounded-full border border-line px-2 py-0.5 dark:border-line-dark">{{ $word }}</span>
                        @if (! $loop->last) <span class="text-ink-faint dark:text-ink-faint-dark">@svg('heroicon-o-chevron-right', 'inline h-3 w-3')</span> @endif
                    @endforeach
                </div>
                <div class="mt-3 space-y-2">
                    @foreach ($lesson['word_order_examples'] ?? [] as $rule)
                        <div class="rounded-lg border border-line p-2 text-sm text-ink dark:border-line-dark dark:text-ink-dark">
                            <p class="text-xs text-ink-faint dark:text-ink-faint-dark">{{ $rule['rule'] }}</p>
                            <p class="font-semibold">{!! $this->highlightAdverb($rule['example'], $rule['adverb'] ?? '') !!}</p>
                        </div>
                    @endforeach
                </div>

                @if (! empty($lesson['bridge_note']))
                    <p class="mt-3 text-xs text-ink-faint dark:text-ink-faint-dark italic">{{ $lesson['bridge_note'] }}</p>
                @endif
            </div>

            {{-- <x-substep-nav> is deliberately a small muted grouped pill,
                 not the app's bold filled-pill "Next" language used for
                 moving between mission steps at the bottom of the page —
                 that's what keeps the two from being confused with each
                 other. "Start practice" is the real, prominent commitment,
                 so it keeps the app's normal primary-button treatment. --}}
            <div class="flex items-center justify-between gap-2">
                <x-substep-nav index-var="lessonStep" :total="count($lessonSections)" />

                <button
                    type="button"
                    x-show="lessonStep === lessonSections - 1"
                    x-cloak
                    wire:click="startPractice"
                    x-on:click="phase = 'practice'"
                    class="inline-flex cursor-pointer items-center gap-1 rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
                >Start practice @svg('heroicon-o-chevron-right', 'h-3.5 w-3.5')</button>
            </div>
        </div>
    @endunless

    <div x-show="phase === 'practice'" @unless ($readOnly) x-cloak @endunless class="space-y-6">
        @unless ($readOnly)
            <button
                type="button"
                x-on:click="phase = 'lesson'; lessonStep = 0"
                class="inline-flex cursor-pointer items-center gap-1 text-xs font-semibold text-ink-faint underline decoration-dotted underline-offset-2 dark:text-ink-faint-dark"
            >@svg('heroicon-o-chevron-right', 'h-3 w-3') Review the lesson again</button>
        @endunless

        <div>
            <p class="text-sm font-semibold text-ink dark:text-ink-dark">Make it personal</p>
            <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Finish at least 3 sentences about your own life. Check one anytime for feedback, or we'll check the rest for you when you move on.</p>
            @unless ($readOnly)
                @php $vocabularyWords = $run->selectedVocabularyWords(); @endphp
                @if ($vocabularyWords)
                    <div class="mt-2">
                        <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Tap a word to drop it into your next sentence:</p>
                        <div class="mt-1">
                            <x-vocabulary-chips
                                :words="$vocabularyWords"
                                field="frequencySentences"
                                ref-prefix="freq_input_"
                                on-insert="filled[idx] = true; dismissed['freq' + idx] = true;"
                            />
                        </div>
                    </div>
                @endif
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

            <div wire:loading.class="pointer-events-none" wire:target="checkOne,revealCorrection,declineReveal,save" class="mt-2 space-y-3">
                @foreach ($grammar['frequency_starters'] ?? [] as $index => $starter)
                    @php $itemFeedback = $feedback[$index] ?? null; @endphp
                    <div class="rounded-xl border border-line p-3 dark:border-line-dark">
                        <div class="flex items-center gap-2">
                            <span class="shrink-0 text-sm text-ink-faint dark:text-ink-faint-dark">{{ $starter }}</span>
                            <input
                                type="text"
                                x-ref="freq_input_{{ $index }}"
                                wire:model="frequencySentences.{{ $index }}"
                                x-on:input="filled[{{ $index }}] = $el.value.trim() !== ''; dismissed['freq{{ $index }}'] = true"
                                @unless ($readOnly)
                                    x-draft="{ key: '{{ $draftPrefix }}frequencySentences.{{ $index }}', field: 'frequencySentences.{{ $index }}' }"
                                @endunless
                                @readonly($readOnly)
                                wire:loading.attr="disabled"
                                wire:target="checkOne,revealCorrection,declineReveal,save"
                                class="w-full rounded-lg border border-line bg-transparent px-2 py-1 text-sm text-ink disabled:opacity-50 dark:border-line-dark dark:text-ink-dark"
                            >
                            <x-filled-check show="filled[{{ $index }}]" />
                            @unless ($readOnly)
                                <x-check-button method="checkOne" :index="$index" key-prefix="freq" wire-target="checkOne,revealCorrection,declineReveal,save" />
                            @endunless
                        </div>

                        @unless ($readOnly)
                            <x-ai-thinking wire:loading wire:target="checkOne({{ $index }}), revealCorrection({{ $index }}), save" class="mt-2" />
                        @endunless

                        <div x-show="!dismissed['freq{{ $index }}']" x-transition.opacity.duration.300ms>
                            <x-severity-feedback :feedback="$itemFeedback" :error="$checkErrors[$index] ?? null" />
                        </div>

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
            @error('frequencySentences')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        @if ($readOnly)
            @if ($quickCheckScore)
                <div>
                    <p class="text-sm font-semibold text-ink dark:text-ink-dark">Quick check</p>
                    <p class="text-xs text-ink-faint dark:text-ink-faint-dark">You scored {{ $quickCheckScore['correct'] }} of {{ $quickCheckScore['total'] }}.</p>
                </div>
            @endif
        @else
            <div>
                <p class="text-sm font-semibold text-ink dark:text-ink-dark">Quick check</p>
                <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Pick the correct fix for each sentence — just a warm-up, skip anytime.</p>
                <div class="mt-2">
                    <x-quick-round :cards="$this->quickCheckCards()" on-complete="$wire.set('quickCheckScore', { correct: correctCount, total: cards.length })" />
                </div>
            </div>
        @endif

        @unless ($readOnly)
            <x-continue-button
                on-click="filled.forEach((_, i) => dismissed['freq' + i] = true); $wire.save().then(() => { dismissed = {} })"
                wire-target="checkOne,revealCorrection,declineReveal,save"
                loading-label="Checking your sentences…"
                ready-when="filledCount >= 3"
            />
        @endunless
    </div>
</div>
