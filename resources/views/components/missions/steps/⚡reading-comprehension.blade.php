<?php

use App\Livewire\Concerns\DictatesAnswers;
use App\Livewire\Concerns\KeepsStepDraft;
use App\Livewire\Concerns\PreviewsStep;
use App\Livewire\Concerns\TracksAiUsage;
use App\Livewire\Concerns\TracksCheckAttempts;
use App\Livewire\Concerns\TracksVocabularyNotebook;
use App\Models\Evidence;
use App\Models\MissionRun;
use App\Services\PexelsClient;
use App\Services\SentenceChecker;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Reading, one task per screen (lesson design 9): the passage alone (tap a
 * word for its meaning), the true/false cards one per screen, then the
 * questions — multiple choice when the mission has `choice_questions` (one
 * tap each, no AI), otherwise each question on its own screen, typed or
 * said and checked from the bottom, the passage a tap away in a sheet. The
 * new words join My Words by themselves.
 */
new class extends Component
{
    use DictatesAnswers;
    use KeepsStepDraft;
    use PreviewsStep;
    use TracksAiUsage;
    use TracksCheckAttempts;
    use TracksVocabularyNotebook;
    use WithFileUploads;

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
            $this->restoreStepDraft();

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
    protected function draftPhase(): string
    {
        return 'reading_comprehension';
    }

    /** The verdicts are kept, so coming back never means checking every answer again on the last Continue. */
    protected function draftedProperties(): array
    {
        return ['feedback', 'checkAttempts', 'offerReveal'];
    }

    public function newWords(): array
    {
        return collect($this->run->mission->stepContent('reading_comprehension')['highlighted_phrases'] ?? [])
            ->where('type', 'new')
            ->values()
            ->all();
    }

    /**
     * @return list<array{word: string, meaning: string, pos: ?string, example: ?string}>
     */
    protected function notebookCandidates(): array
    {
        return collect($this->newWords())
            ->map(fn ($item) => [
                'word' => $item['phrase'],
                'meaning' => $item['definition'] ?? '',
                'pos' => $item['pos'] ?? null,
                'example' => $item['example'] ?? null,
            ])
            ->values()
            ->all();
    }

    /**
     * The written questions — none when the mission asks its questions as
     * multiple choice instead (see choiceCards()).
     */
    public function questions(): array
    {
        if ($this->choiceQuestions() !== []) {
            return [];
        }

        return $this->run->mission->stepContent('reading_comprehension')['questions'] ?? [];
    }

    /**
     * @return list<array{prompt: string, options: list<string>, correct: int, difficulty?: string}>
     */
    private function choiceQuestions(): array
    {
        return array_values($this->run->mission->stepContent('reading_comprehension')['choice_questions'] ?? []);
    }

    /**
     * The multiple-choice questions as <x-quick-round> cards.
     *
     * @return list<array{prompt: string, options: list<string>, correct: int, label: string, difficulty?: string}>
     */
    public function choiceCards(): array
    {
        return array_map(fn (array $item) => [...$item, 'label' => 'Choose the answer'], $this->choiceQuestions());
    }

    /**
     * The multiple-choice round is finished: the step is done. What was
     * answered right on the first try is kept with the evidence.
     */
    public function finishChoices(int $correct, int $total): void
    {
        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'reading_comprehension',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode(['answers' => [], 'choices' => ['correct' => max(0, $correct), 'total' => max(0, $total)]]),
        ]);

        $this->finishStep();
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

    public function checkOne(int $index): bool
    {
        $answer = trim($this->answers[$index] ?? '');

        if ($answer === '') {
            $this->checkErrors[$index] = 'Write something first.';

            return false;
        }

        return $this->runCheck($index, $answer);
    }

    /**
     * Asks the shared SentenceChecker to judge one answer, storing the
     * verdict tagged with the exact text it applies to, so a later edit
     * doesn't leave a stale verdict attached to different text. See
     * EOS-009 §8 "الگوی چک جمله" for the shared rules.
     */
    private function runCheck(int $index, string $text): bool
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
            $this->noteCheckedMistake($text, $data, 'reading_comprehension');
            $this->keepStepDraft();

            return true;
        } catch (Throwable $e) {
            $this->failCheck($index, $e);
            $this->keepStepDraft();

            return false;
        }
    }

    /**
     * After 3 failed attempts on the same answer, the learner can ask the
     * AI to just write the corrected version — see TracksCheckAttempts.
     */
    /**
     * The mic in an answer box: what was said becomes the answer — the
     * learner sees it at once — and the page then checks it (see
     * <x-checked-answer-actions>, event eos-dictated).
     */
    public function dictateAnswer(int $index): void
    {
        $text = isset($this->questions()[$index]) ? $this->dictatedText($index) : null;

        if ($text === null) {
            return;
        }

        $this->answers[$index] = $text;
        $this->keepStepDraft();
        $this->dispatch('eos-dictated', key: $index);
    }

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
                $this->keepStepDraft();
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

        if ($passage === '') {
            return '';
        }

        $sorted = collect($phrases)->sortByDesc(fn ($p) => mb_strlen($p['phrase']))->values();
        $byPhrase = $sorted->keyBy('phrase');
        $pattern = $sorted->isEmpty()
            ? null
            : '/('.$sorted->map(fn ($p) => preg_quote($p['phrase'], '/'))->implode('|').')/u';

        return collect($this->passageSentences($passage))
            ->map(fn (string $sentence, int $index) => '<span data-sentence="'.$index.'" class="box-decoration-clone">'
                .$this->markPhrases($sentence, $pattern, $byPhrase).'</span>')
            ->implode('');
    }

    /**
     * The passage cut into sentences the same way window.eosVoice.speak()
     * cuts it, so sentence N here is the sentence being read aloud (the
     * follow-along highlight in the passage screen).
     *
     * @return list<string>
     */
    public function passageSentences(string $passage): array
    {
        $found = preg_match_all('/[^.!?]+[.!?]+["”’)]*\s*|[^.!?]+$/u', $passage, $matches) ? $matches[0] : [];

        return implode('', $found) === $passage ? array_values($found) : [$passage];
    }

    /**
     * One sentence with its phrases wrapped in a <mark> — a soft colour
     * behind the word, no underline.
     *
     * @param  \Illuminate\Support\Collection<string, array{phrase: string, type?: string, definition?: string}>  $byPhrase
     */
    private function markPhrases(string $sentence, ?string $pattern, $byPhrase): string
    {
        if ($pattern === null) {
            return e($sentence);
        }

        $parts = preg_split($pattern, $sentence, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$sentence];

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
                ? 'rounded-md bg-accent/25 px-1 py-0.5 font-bold text-accent-ink dark:bg-accent-dark/35 dark:text-accent-ink-dark'
                : 'rounded-md bg-success/25 px-1 py-0.5 font-bold text-ink dark:bg-success-dark/35 dark:text-ink-dark';

            return '<mark class="'.$classes.'" title="'.e($tooltip).'">'.e($part).'</mark>';
        })->implode('');
    }

    /**
     * A decorative header image for the passage's subject — dual-coding,
     * same principle as Vocabulary Builder's flashcards. Cached per
     * mission (not per learner), fails soft like every PexelsClient call.
     */
    #[Computed]
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
                'label' => 'True or false?',
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

            // An answer the AI already could not check is let through, not asked again.
            if (! $alreadyChecked && ! isset($this->checkErrors[$index])) {
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

        $severities = $filled->keys()->map(fn ($index) => $this->feedback[$index]['severity'] ?? 'none')->values();

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'reading_comprehension',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode(['answers' => $filled->values(), 'severities' => $severities]),
        ]);

        $this->finishStep();
    }

    /**
     * The passage's new words join My Words by themselves (lesson design 9),
     * the draft is cleared and the learner moves on.
     */
    private function finishStep(): void
    {
        $this->initWordsToTrack();
        $this->addWordsToNotebook();

        $this->forgetStepDraft();
        $this->dispatch('clear-draft', prefix: $this->draftPrefix());
        $this->redirect($this->run->urlAfterSavingAStep(), navigate: true);
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
    $highlightTypes = collect($reading['highlighted_phrases'] ?? [])->map(fn (array $phrase) => $phrase['type'] ?? 'reused')->unique();
    $passagePhoto = $readOnly ? null : ($this->passageImageUrl ?: app(\App\Services\MissionGrid::class)->coverUrl($run->mission->code));
    $draftPrefix = $this->draftPrefix();
    $questions = $this->questions();
    $cards = $readOnly ? [] : $this->comprehensionCards();
    $choiceCards = $readOnly ? [] : $this->choiceCards();
    // Screens: 0 start · 1 the passage · 2 true/false · 3 the multiple-choice round, or 3.. one written question each.
    $quizScreen = count($cards) ? 2 : null;
    $firstQuestionScreen = 3;
    $screenCount = $firstQuestionScreen + max(count($questions), count($choiceCards) ? 1 : 0);
@endphp

@if ($readOnly)
    <div class="flex flex-col gap-4 pt-2">
        <x-step-intro kind="Reading" :title="$reading['passage_title'] ?? 'Reading'" />
        <p x-lookup class="text-base leading-relaxed text-ink dark:text-ink-dark">{!! $passageHtml !!}</p>
        @foreach ($questions as $index => $question)
            <div class="flex flex-col gap-1 rounded-2xl bg-surface p-4 dark:bg-surface-dark">
                <p class="text-sm font-bold text-ink dark:text-ink-dark">{{ $question }}</p>
                <p class="text-sm text-ink-soft dark:text-ink-soft-dark">{{ $answers[$index] ?? '' }}</p>
            </div>
        @endforeach
    </div>
@else
<div
    x-data="{
        screen: 0,
        quizShare: 0,
        text: false,
        edited: {},
        init() {
            this.screen = window.eosDraft.restoreIndex('{{ $draftPrefix }}screen', 0);
            this.$watch('screen', (v) => window.eosDraft.persistIndex('{{ $draftPrefix }}screen', v));
        },
        get share() { return (this.screen + (this.screen === 2 ? this.quizShare : 0.5)) / {{ $screenCount }} },
    }"
    x-effect="$dispatch('step-progress', { share })"
    x-on:quick-round-progress="quizShare = $event.detail.share"
    x-back-close.step="{ open: screen > 0, close: () => screen-- }"
>
    <x-lesson-screen when="screen === 0">
        <x-step-intro kind="Reading" :minutes="$run->mission->stepDuration('reading_comprehension')" :title="$reading['hook'] ?? null" :image="$this->passageImageUrl" />

        <x-slot:actions>
            <x-continue-button on-click="screen = 1" wire-target="save">Start</x-continue-button>
        </x-slot:actions>
    </x-lesson-screen>

    {{-- The only screen that scrolls: the passage on a solid card over the mission photo, a tap on any word for its meaning, the sentence being read aloud lit as it goes. --}}
    <x-lesson-screen when="screen === 1">
        <div
            x-data="{
                follow(index) {
                    const sentences = this.$refs.passage.querySelectorAll('[data-sentence]');
                    const lit = ['bg-dusk/25', 'dark:bg-dusk/40', 'rounded-md'];
                    sentences.forEach((sentence, i) => sentence.classList[i === index ? 'add' : 'remove'](...lit));
                    sentences[index]?.scrollIntoView({ block: 'center', behavior: 'smooth' });
                },
            }"
            x-on:eos-follow="follow($event.detail.index)"
            @class(['relative isolate flex flex-col gap-3', 'overflow-hidden rounded-3xl p-3' => $passagePhoto])
        >
            @if ($passagePhoto)
                <img src="{{ $passagePhoto }}" alt="" class="absolute inset-0 -z-30 h-full w-full scale-125 object-cover blur-2xl">
                <div class="absolute inset-0 -z-20 bg-ground/55 dark:bg-ground-dark/65"></div>
                <div class="relative -mx-3 -mt-3 flex h-48 items-end overflow-hidden px-4 pb-14">
                    <div class="absolute inset-0 -z-10 [mask-image:linear-gradient(to_bottom,black_72%,transparent)]">
                        <img src="{{ $passagePhoto }}" alt="" class="h-full w-full object-cover">
                        <div class="absolute inset-0 bg-linear-to-t from-black/70 via-black/20 to-black/25"></div>
                    </div>
                    <h2 class="font-display lesson-title text-white drop-shadow">{{ $reading['passage_title'] ?? 'Reading' }}</h2>
                </div>
            @else
                <h2 class="font-display lesson-title text-ink dark:text-ink-dark">{{ $reading['passage_title'] ?? 'Reading' }}</h2>
            @endif

            <div @class(['relative flex flex-wrap items-center gap-x-4 gap-y-2', '-mt-8 gap-2' => $passagePhoto])>
                <x-listen-aloud :text="$reading['passage'] ?? ''" :follow="true" :class="$passagePhoto ? 'bg-surface text-ink shadow-sm dark:bg-surface-dark' : ''" />
                @if ($highlightTypes->contains('reused'))
                    <span @class(['inline-flex items-center gap-1.5 text-xs font-bold text-ink-soft dark:text-ink-soft-dark', 'rounded-full bg-surface px-3 py-1 text-ink shadow-sm dark:bg-surface-dark dark:text-ink-dark' => $passagePhoto])><span class="rounded-md bg-success/25 px-1.5 text-ink dark:bg-success-dark/35 dark:text-ink-dark">word</span>From New Words</span>
                @endif
                @if ($highlightTypes->contains('new'))
                    <span @class(['inline-flex items-center gap-1.5 text-xs font-bold text-ink-soft dark:text-ink-soft-dark', 'rounded-full bg-surface px-3 py-1 text-ink shadow-sm dark:bg-surface-dark dark:text-ink-dark' => $passagePhoto])><span class="rounded-md bg-accent/25 px-1.5 text-accent-ink dark:bg-accent-dark/35 dark:text-accent-ink-dark">word</span>New word</span>
                @endif
            </div>
            <p x-ref="passage" x-lookup class="relative rounded-3xl bg-surface p-5 text-lg leading-loose text-ink shadow-lg ring-1 ring-line dark:bg-surface-dark dark:text-ink-dark dark:ring-line-dark">{!! $passageHtml !!}</p>
        </div>

        <x-slot:actions>
            <x-continue-button on-click="screen = {{ $quizScreen ?? $firstQuestionScreen }}" wire-target="save" />
        </x-slot:actions>
    </x-lesson-screen>

    @if ($quizScreen)
        <section x-show="screen === {{ $quizScreen }}" x-cloak class="animate-lesson-screen-in pt-2">
            <x-quick-round
                lesson
                :cards="$cards"
                label="True or false?"
                draft-key="{{ $draftPrefix }}quiz"
                on-complete="if (screen === {{ $quizScreen }}) screen = {{ $firstQuestionScreen }}"
                on-skip="screen = {{ $firstQuestionScreen }}"
            />
        </section>
    @endif

    @if (count($choiceCards))
        <section x-show="screen === {{ $firstQuestionScreen }}" x-cloak class="animate-lesson-screen-in pt-2">
            <x-quick-round
                lesson
                :cards="$choiceCards"
                label="Choose the answer"
                draft-key="{{ $draftPrefix }}choices"
                on-complete="if (screen === {{ $firstQuestionScreen }}) $wire.finishChoices(correctCount, firstTries)"
                on-skip="$wire.finishChoices(0, 0)"
            />
        </section>
    @endif

    @foreach ($questions as $index => $question)
        @php
            $itemFeedback = $feedback[$index] ?? null;
            $isLast = $index === count($questions) - 1;
        @endphp
        <x-lesson-screen when="screen === {{ $firstQuestionScreen + $index }}" wire:key="reading-question-{{ $index }}">
            <span class="inline-flex w-fit rounded-full bg-accent-soft px-3 py-1 text-xs font-extrabold tracking-wide text-accent-ink uppercase dark:bg-accent-soft-dark dark:text-accent-ink-dark">Answer · {{ $index + 1 }} of {{ count($questions) }}</span>
            <p class="lesson-prompt text-ink dark:text-ink-dark">{{ $question }}</p>

            <x-type-or-say
                model="answers.{{ $index }}"
                method="dictateAnswer"
                :param="$index"
                draft-key="{{ $draftPrefix }}answers.{{ $index }}"
                placeholder="Answer in a full sentence…"
                on-input="edited[{{ $index }}] = true"
                :error="$dictationErrors[$index] ?? null"
            />
            <x-ai-thinking wire:loading wire:target="checkOne({{ $index }}), revealCorrection({{ $index }}), save" />

            <x-slot:actions>
                <x-checked-answer-actions
                    :index="$index"
                    :model="'answers.'.$index"
                    :feedback="$itemFeedback"
                    :error="$checkErrors[$index] ?? null"
                    check="$wire.checkOne({{ $index }})"
                    :next="$isLast ? '$wire.save()' : 'screen++'"
                    wire-target="checkOne,dictateAnswer,revealCorrection,save"
                    :offer-reveal="$offerReveal[$index] ?? false"
                    :almost-revealing="$this->isAlmostRevealing($index)"
                />
            </x-slot:actions>
        </x-lesson-screen>
    @endforeach

    @error('answers')
        <p class="text-sm text-danger-ink">{{ $message }}</p>
    @enderror

    {{-- 📄 Text: the passage, a tap away on every question screen. --}}
    <button
        type="button"
        x-show="screen >= {{ $quizScreen ?? $firstQuestionScreen }}"
        x-cloak
        x-on:click="text = true"
        class="fixed top-24 right-4 z-30 cursor-pointer rounded-full bg-accent-soft px-3 py-1.5 text-xs font-extrabold text-accent-ink shadow-sm dark:bg-accent-soft-dark dark:text-accent-ink-dark"
    >📄 Text</button>
    <x-bottom-sheet show="text" label="The text">
        <x-slot:title>{{ $reading['passage_title'] ?? 'Reading' }}</x-slot:title>
        <p x-lookup class="text-base leading-loose text-ink dark:text-ink-dark">{!! $passageHtml !!}</p>
    </x-bottom-sheet>
</div>
@endif
