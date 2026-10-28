<?php

use App\Livewire\Concerns\DictatesAnswers;
use App\Livewire\Concerns\KeepsStepDraft;
use App\Livewire\Concerns\PreviewsStep;
use App\Livewire\Concerns\TracksAiUsage;
use App\Livewire\Concerns\TracksCheckAttempts;
use App\Models\Evidence;
use App\Models\MissionRun;
use App\Services\AiFailure;
use App\Services\SentenceChecker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Grammar Time, learn → try (lesson design 5): each lesson section is a
 * short rule + formula screen followed by "Which one is right?" built from
 * that section's own wrong/right pair; then the quick-check and build-the-
 * sentence cards one per screen; then three sentences about the learner,
 * one per screen, typed or said, checked from the bottom. 💡 Rule opens
 * every rule in a sheet on the practice screens.
 */
new class extends Component
{
    use DictatesAnswers;
    use KeepsStepDraft;
    use PreviewsStep;
    use TracksAiUsage;
    use TracksCheckAttempts;
    use WithFileUploads;

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

    /**
     * @var array{correct: int, total: int}|null
     */
    public ?array $wordOrderScore = null;

    public function mount(): void
    {
        if (! $this->readOnly) {
            $this->restoreStepDraft();

            return;
        }

        $data = json_decode($this->run->latestEvidence('grammar_in_context')?->content_ref ?? '{}', true);
        $starters = $this->starters();
        $savedSentences = collect($data['frequency_sentences'] ?? [])->keyBy('starter');

        foreach ($starters as $index => $starter) {
            $this->frequencySentences[$index] = $this->continuation($starter, $savedSentences[$starter]['completion'] ?? '');
        }

        $this->quickCheckScore = $data['quick_check_score'] ?? null;
        $this->wordOrderScore = $data['word_order_score'] ?? null;
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

    /**
     * "Build the sentence" round (Epic D) — a tap-the-words-in-order
     * warm-up, same ungraded/skippable shell as Quick Check. Each
     * mission seeds its own `word_order` list; see <x-word-order-round>.
     *
     * @return list<array{words: list<string>, answer: string}>
     */
    public function wordOrderCards(): array
    {
        return $this->run->mission->stepContent('grammar_in_context')['word_order'] ?? [];
    }

    /**
     * How many completed sentences this step requires — unchanged across
     * all 24 missions, and deliberately a named constant so that the
     * scaffolding taper below can be pinned to it rather than to a
     * number someone might later edit in one place and not the other.
     */
    public const REQUIRED_SENTENCES = 3;

    /** Exposed for the Blade template — a bare `self::CONST` isn't reachable there. */
    public function requiredSentences(): int
    {
        return self::REQUIRED_SENTENCES;
    }

    /**
     * The starters this mission actually offers. Every read of
     * frequency_starters goes through here so the mission's scaffold
     * level (Mission::scaffoldLevel()) applies everywhere at once —
     * mount(), checkOne(), revealCorrection(), save() and the view all
     * have to agree on the list, or an index would point at a different
     * starter in two places.
     *
     * Six starters at M01, five from M09, four from M17 — and never
     * fewer than REQUIRED_SENTENCES, so this can only ever remove choice,
     * never make the step harder to finish.
     *
     * @return array<int, string>
     */
    public function starters(): array
    {
        return $this->run->mission->taperScaffolding(
            $this->run->mission->stepContent('grammar_in_context')['frequency_starters'] ?? [],
            self::REQUIRED_SENTENCES,
        );
    }

    protected function draftPhase(): string
    {
        return 'grammar_in_context';
    }

    protected function draftedProperties(): array
    {
        return ['practiceStarted'];
    }

    /** Kept in the step's draft, so a learner who leaves comes back to practice, not the lesson. */
    public function startPractice(): void
    {
        $this->practiceStarted = true;

        $this->keepStepDraft();
    }

    public function checkOne(int $index): bool
    {
        $this->stripTypedStarters();

        $starters = $this->starters();
        $starter = $starters[$index] ?? null;

        if (! $starter) {
            return false;
        }

        $sentence = trim($this->frequencySentences[$index] ?? '');

        if ($sentence === '') {
            $this->checkErrors[$index] = 'Write something first.';

            return false;
        }

        return $this->runCheck($index, $starter, $sentence);
    }

    /**
     * What the learner typed after the starter. The starter sits inside the
     * answer box, so it is never required — but a learner who types the
     * whole sentence anyway ("I often go…") must not end up with it twice.
     */
    private function continuation(string $starter, string $text): string
    {
        $text = trim($text);

        if ($starter === '') {
            return $text;
        }

        return trim((string) preg_replace('/^'.preg_quote($starter, '/').'(?:\s+|$)/iu', '', $text, 1));
    }

    /**
     * The complete sentence ("I often" + "go to the gym.") — this, not the
     * bare continuation, is what the AI judges and what is stored as the
     * example, so a sentence is never flagged for "not starting with a
     * capital letter" just because the starter wasn't sent along.
     */
    private function fullSentence(string $starter, string $continuation): string
    {
        $continuation = $this->continuation($starter, $continuation);

        return $continuation === '' ? '' : trim("{$starter} {$continuation}");
    }

    /**
     * Folds a typed-in starter back out of every box so the box always
     * holds just the continuation.
     */
    private function stripTypedStarters(): void
    {
        foreach ($this->starters() as $index => $starter) {
            if (isset($this->frequencySentences[$index])) {
                $this->frequencySentences[$index] = $this->continuation($starter, (string) $this->frequencySentences[$index]);
            }
        }
    }

    /**
     * Builds the "what this text is meant to be" context handed to the AI —
     * shared by both check() and correct() so a check and a later reveal
     * always describe the same target. The tail ("continues in the present
     * simple tense", "continues appropriately in either the present simple
     * or present continuous tense", …) is mission-specific and comes from
     * the seeded `grammar_context` key so a different grammar point never
     * needs a Blade/PHP change here — see stepContent('grammar_in_context').
     */
    private function sentenceContext(array $grammar, string $starter): string
    {
        $tail = trim($grammar['grammar_context'] ?? '');

        return $tail !== ''
            ? "a personal sentence that starts with \"{$starter}\" and {$tail}"
            : "a personal sentence that starts with \"{$starter}\"";
    }

    /**
     * Asks the shared SentenceChecker to judge one frequency sentence,
     * storing the verdict tagged with the exact text it applies to, so a
     * later edit doesn't leave a stale verdict attached to different text.
     * See EOS-009 §8 "الگوی چک جمله" for the shared rules.
     *
     * The judgment/majorCriteria/context tail all come from the seeded
     * content (grammar_judgment / grammar_major_criteria / grammar_context)
     * rather than being hardcoded here, so this step can teach any grammar
     * point a mission seeds it with — not just M01's present simple focus.
     */
    private function runCheck(int $index, string $starter, string $sentence): bool
    {
        unset($this->checkErrors[$index]);

        $grammar = $this->run->mission->stepContent('grammar_in_context');

        try {
            $data = app(SentenceChecker::class)->check(
                judgment: $grammar['grammar_judgment'] ?? '',
                majorCriteria: $grammar['grammar_major_criteria'] ?? '',
                context: $this->sentenceContext($grammar, $starter),
                text: $this->fullSentence($starter, $sentence),
                extraGuidance: $this->run->aiToneGuidance(),
                feedbackDepth: $this->run->mission->feedbackDepth(),
            );
            $this->recordGeminiCall();

            $this->feedback[$index] = $data + ['checkedText' => $sentence];
            $this->trackCheckAttempt($index, $data['severity']);

            return true;
        } catch (ConnectionException|RequestException) {
            // RequestException's message carries the raw HTTP response body
            // (which can be an arbitrarily large error page, not a clean
            // API message) — never show that to the learner.
            $this->checkErrors[$index] = "Couldn't reach the AI service — please try again.";

            return false;
        } catch (Throwable $e) {
            $this->checkErrors[$index] = AiFailure::message($e, "Couldn't check this one");

            return false;
        }
    }

    /**
     * After 3 failed attempts on the same sentence, the learner can ask the
     * AI to just write the corrected version — see TracksCheckAttempts.
     */
    public function revealCorrection(int $index): void
    {
        $this->stripTypedStarters();

        $grammar = $this->run->mission->stepContent('grammar_in_context');
        $starters = $this->starters();
        $starter = $starters[$index] ?? null;
        $sentence = trim($this->frequencySentences[$index] ?? '');

        if (! $starter || $sentence === '') {
            return;
        }

        $this->revealCorrectionFor(
            key: $index,
            context: $this->sentenceContext($grammar, $starter),
            text: $this->fullSentence($starter, $sentence),
            errorBagKey: $index,
            onCorrected: function (string $corrected) use ($index, $starter) {
                $continuation = $this->continuation($starter, $corrected);
                $this->frequencySentences[$index] = $continuation;
                $this->feedback[$index] = ['severity' => 'none', 'hint' => '', 'checkedText' => $continuation];
            },
        );
    }

    /**
     * The mic in a sentence box: what was said (minus the starter, if it
     * was said too) becomes the box's text and is checked straight away.
     */
    public function dictateSentence(int $index): void
    {
        $starter = $this->starters()[$index] ?? null;
        $text = $starter ? $this->dictatedText($index) : null;

        if ($text === null) {
            return;
        }

        $this->frequencySentences[$index] = $this->continuation($starter, $text);
        $this->runCheck($index, $starter, $this->frequencySentences[$index]);
    }

    /**
     * Each lesson section's "Which one is right?" pair — its spot-the-
     * mistake block, or else its first don't/do pair. Null when the
     * section has neither.
     *
     * @return array{wrong: string, right: string, why: ?string}|null
     */
    public function sectionPair(array $section): ?array
    {
        foreach ($section['blocks'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'mistake_fix' && filled($block['wrong'] ?? null)) {
                return ['wrong' => $block['wrong'], 'right' => $block['right'], 'why' => $block['explanation'] ?? null];
            }
        }

        foreach ($section['blocks'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'do_dont' && filled($block['items'][0]['wrong'] ?? null)) {
                $item = $block['items'][0];

                return ['wrong' => $item['wrong'], 'right' => $item['right'], 'why' => $item['note'] ?? null];
            }
        }

        return null;
    }

    public function declineReveal(int $index): void
    {
        $this->declineCheckReveal($index);
    }

    public function save(): void
    {
        $this->stripTypedStarters();

        $starters = $this->starters();

        $filledSentences = collect($this->frequencySentences)
            ->map(fn ($s, $i) => ['index' => $i, 'starter' => $starters[$i] ?? null, 'text' => trim((string) $s)])
            ->filter(fn ($s) => $s['text'] !== '');

        if ($filledSentences->count() < self::REQUIRED_SENTENCES) {
            $this->addError('frequencySentences', 'Complete at least '.self::REQUIRED_SENTENCES.' sentences before continuing.');

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
                    ->map(fn ($s) => ['starter' => $s['starter'], 'completion' => $s['text'], 'severity' => $this->feedback[$s['index']]['severity'] ?? 'none'])
                    ->values(),
                // Optional bonus practice — saved if attempted, but never
                // required and never blocks Continue.
                'quick_check_score' => $this->quickCheckScore,
                'word_order_score' => $this->wordOrderScore,
            ]),
        ]);

        $this->syncGrammarPoint($filledSentences->first());

        $this->forgetStepDraft();
        $this->dispatch('clear-draft', prefix: $this->draftPrefix());
        $this->redirect($this->run->urlAfterSavingAStep(), navigate: true);
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

        $exampleSentence = $this->fullSentence(
            $firstFilledSentence['starter'] ?? '',
            $firstFilledSentence['text'] ?? '',
        );
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
    $sections = $lesson['sections'] ?? [];
    $starters = $this->starters();
    $sentenceCount = min($this->requiredSentences(), count($starters));
    $draftPrefix = $this->draftPrefix();
    $initialFilled = collect(range(0, max(0, count($starters) - 1)))->map(fn ($i) => trim((string) ($frequencySentences[$i] ?? '')) !== '')->values();

    // Screens in order: start · per section a rule screen and its "Which
    // one is right?" · quick check · build the sentence · one sentence each.
    $screens = [['type' => 'intro']];
    foreach ($sections as $sectionIndex => $section) {
        $screens[] = ['type' => 'rule', 'section' => $sectionIndex];
        if ($pair = $this->sectionPair($section)) {
            $screens[] = ['type' => 'pick', 'section' => $sectionIndex, 'pair' => $pair];
        }
    }
    if (! $readOnly && $this->quickCheckCards()) {
        $screens[] = ['type' => 'quick'];
    }
    if (! $readOnly && $this->wordOrderCards()) {
        $screens[] = ['type' => 'order'];
    }
    foreach (range(0, max(0, $sentenceCount - 1)) as $sentenceIndex) {
        $screens[] = ['type' => 'sentence', 'index' => $sentenceIndex];
    }
    $screenCount = count($screens);
    $roundScreens = collect($screens)->filter(fn ($screen) => in_array($screen['type'], ['quick', 'order'], true))->keys()->values();
    $firstPracticeScreen = collect($screens)->search(fn ($screen) => in_array($screen['type'], ['quick', 'order', 'sentence'], true));
    $firstSentenceScreen = collect($screens)->search(fn ($screen) => $screen['type'] === 'sentence');
    $firstUnwritten = $initialFilled->take($sentenceCount)->search(false);
    $startScreen = $practiceStarted ? $firstSentenceScreen + ($firstUnwritten === false ? $sentenceCount - 1 : $firstUnwritten) : 0;
@endphp

@if ($readOnly)
    <div class="flex flex-col gap-5 pt-2">
        <x-step-intro kind="Grammar Time" :title="$grammar['focus'] ?? 'Grammar'" />
        @foreach ($sections as $section)
            @include('missions.steps.partials.grammar-rule-card', ['section' => $section])
        @endforeach
        @if ($quickCheckScore)
            <p class="text-sm text-ink-faint dark:text-ink-faint-dark">Fix the sentence: you scored {{ $quickCheckScore['correct'] }} of {{ $quickCheckScore['total'] }}.</p>
        @endif
        @if ($wordOrderScore)
            <p class="text-sm text-ink-faint dark:text-ink-faint-dark">Build the sentence: you scored {{ $wordOrderScore['correct'] }} of {{ $wordOrderScore['total'] }}.</p>
        @endif
        @foreach ($starters as $index => $starter)
            @if (filled($frequencySentences[$index] ?? null))
                <p class="rounded-2xl bg-surface px-4 py-3 text-base text-ink dark:bg-surface-dark dark:text-ink-dark"><strong>{{ $starter }}</strong> {{ $frequencySentences[$index] }}</p>
            @endif
        @endforeach
    </div>
@else
<div
    data-round-screens="{{ $roundScreens->toJson() }}"
    x-data="{
        screen: {{ $startScreen }},
        roundShare: 0,
        roundScreens: [],
        ruleSheet: false,
        picked: {},
        edited: {},
        init() {
            this.roundScreens = JSON.parse(this.$el.dataset.roundScreens);
        },
        get share() { return (this.screen + (this.roundScreens.includes(this.screen) ? this.roundShare : 0.5)) / {{ $screenCount }} },
    }"
    x-effect="$dispatch('step-progress', { share })"
    x-on:quick-round-progress="roundShare = $event.detail.share"
    x-back-close.step="{ open: screen > 0, close: () => screen-- }"
>
    @foreach ($screens as $screenIndex => $screen)
        @if (in_array($screen['type'], ['quick', 'order', 'sentence'], true))
            <div x-show="screen === {{ $screenIndex }}" x-cloak class="flex justify-end">
                <button type="button" x-on:click="ruleSheet = true" class="cursor-pointer rounded-full bg-accent-soft px-3 py-1 text-xs font-extrabold text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">💡 Rule</button>
            </div>
        @endif

        @switch($screen['type'])
            @case('intro')
                <x-lesson-screen when="screen === {{ $screenIndex }}">
                    <x-step-intro kind="Grammar Time" :minutes="$run->mission->stepDuration('grammar_in_context')" :title="$grammar['focus'] ?? 'Grammar'" />
                    @if (filled($grammar['hook'] ?? null))
                        <p class="text-base leading-relaxed text-ink-soft dark:text-ink-soft-dark">{{ $grammar['hook'] }}</p>
                    @endif

                    <x-slot:actions>
                        <x-continue-button on-click="screen++" wire-target="save">Start</x-continue-button>
                    </x-slot:actions>
                </x-lesson-screen>
                @break

            @case('rule')
                <x-lesson-screen when="screen === {{ $screenIndex }}">
                    @include('missions.steps.partials.grammar-rule-card', ['section' => $sections[$screen['section']]])

                    <x-slot:actions>
                        <x-continue-button on-click="screen++{{ $screenIndex + 1 === $firstPracticeScreen ? '; $wire.startPractice()' : '' }}" wire-target="save">Got it</x-continue-button>
                    </x-slot:actions>
                </x-lesson-screen>
                @break

            @case('pick')
                @php
                    $pair = $screen['pair'];
                    $options = crc32($pair['wrong']) % 2 === 0 ? [[$pair['wrong'], false], [$pair['right'], true]] : [[$pair['right'], true], [$pair['wrong'], false]];
                @endphp
                <x-lesson-screen when="screen === {{ $screenIndex }}">
                    <span class="inline-flex w-fit rounded-full bg-accent-soft px-3 py-1 text-xs font-extrabold tracking-wide text-accent-ink uppercase dark:bg-accent-soft-dark dark:text-accent-ink-dark">Which one is right?</span>
                    <div class="flex flex-col gap-2.5">
                        @foreach ($options as [$sentence, $isRight])
                            <button
                                type="button"
                                x-on:click="if (picked[{{ $screenIndex }}] === undefined) { picked[{{ $screenIndex }}] = {{ $isRight ? 'true' : 'false' }}; {{ $isRight ? 'window.eosSound?.playSuccess()' : '' }} }"
                                class="choice text-left"
                                :data-state="picked[{{ $screenIndex }}] === undefined ? 'idle' : ({{ $isRight ? "'correct'" : "(picked[{$screenIndex}] === false ? 'wrong' : 'muted')" }})"
                            ><span class="min-w-0 flex-1 leading-snug">{{ $sentence }}</span></button>
                        @endforeach
                    </div>

                    <x-slot:actions>
                        <x-answer-sheet when="picked[{{ $screenIndex }}] !== undefined" tone="picked[{{ $screenIndex }}] ? 'good' : 'almost'" title="picked[{{ $screenIndex }}] ? '✓ Correct!' : 'Not quite'">
                            {{ $pair['why'] ?? '' }}
                            <x-slot:actions>
                                <x-continue-button on-click="screen++{{ $screenIndex + 1 === $firstPracticeScreen ? '; $wire.startPractice()' : '' }}" wire-target="save" />
                            </x-slot:actions>
                        </x-answer-sheet>
                    </x-slot:actions>
                </x-lesson-screen>
                @break

            @case('quick')
                <section x-show="screen === {{ $screenIndex }}" x-cloak class="animate-lesson-screen-in">
                    <x-quick-round
                        lesson
                        label="Fix the sentence"
                        :cards="$this->quickCheckCards()"
                        :draft-key="$preview ? null : $draftPrefix.'quickCheck'"
                        on-complete="$wire.set('quickCheckScore', { correct: correctCount, total: cards.length }, false); if (screen === {{ $screenIndex }}) screen++"
                        on-skip="screen++"
                    />
                </section>
                @break

            @case('order')
                <section x-show="screen === {{ $screenIndex }}" x-cloak class="animate-lesson-screen-in">
                    <x-word-order-round
                        lesson
                        :cards="$this->wordOrderCards()"
                        :draft-key="$preview ? null : $draftPrefix.'wordOrder'"
                        on-complete="$wire.set('wordOrderScore', { correct: correctCount, total: cards.length }, false); if (screen === {{ $screenIndex }}) screen++"
                        on-skip="screen++"
                    />
                </section>
                @break

            @case('sentence')
                @php
                    $index = $screen['index'];
                    $starter = $starters[$index];
                    $itemFeedback = $feedback[$index] ?? null;
                    $isLast = $index === $sentenceCount - 1;
                @endphp
                <x-lesson-screen when="screen === {{ $screenIndex }}" wire:key="grammar-sentence-{{ $index }}">
                    <span class="inline-flex w-fit rounded-full bg-accent-soft px-3 py-1 text-xs font-extrabold tracking-wide text-accent-ink uppercase dark:bg-accent-soft-dark dark:text-accent-ink-dark">About you · {{ $index + 1 }} of {{ $sentenceCount }}</span>

                    <x-type-or-say
                        model="frequencySentences.{{ $index }}"
                        method="dictateSentence"
                        :param="$index"
                        :starter="$starter"
                        draft-key="{{ $draftPrefix }}frequencySentences.{{ $index }}"
                        placeholder="finish the sentence…"
                        on-input="edited[{{ $index }}] = true"
                        :error="$dictationErrors[$index] ?? null"
                    />
                    <x-ai-thinking wire:loading wire:target="checkOne({{ $index }}), dictateSentence, revealCorrection({{ $index }}), save" />

                    <x-slot:actions>
                        <x-checked-answer-actions
                            :index="$index"
                            :model="'frequencySentences.'.$index"
                            :feedback="$itemFeedback"
                                    :error="$checkErrors[$index] ?? null"
                            check="$wire.checkOne({{ $index }})"
                            :next="$isLast ? '$wire.save()' : 'screen++'"
                            wire-target="checkOne,dictateSentence,revealCorrection,save"
                            :offer-reveal="$offerReveal[$index] ?? false"
                            :almost-revealing="$this->isAlmostRevealing($index)"
                        />
                    </x-slot:actions>
                </x-lesson-screen>
                @break
        @endswitch
    @endforeach

    @error('frequencySentences')
        <p class="text-sm text-danger-ink">{{ $message }}</p>
    @enderror

    <x-bottom-sheet show="ruleSheet" label="The rules">
        <x-slot:title>💡 The rules</x-slot:title>
        @foreach ($sections as $section)
            @include('missions.steps.partials.grammar-rule-card', ['section' => $section])
        @endforeach
    </x-bottom-sheet>
</div>
@endif
