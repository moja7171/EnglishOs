<?php

use App\Livewire\Concerns\DictatesAnswers;
use App\Livewire\Concerns\PreviewsStep;
use App\Livewire\Concerns\TracksAiUsage;
use App\Livewire\Concerns\TracksCheckAttempts;
use App\Livewire\Concerns\TracksVocabularyNotebook;
use App\Models\Evidence;
use App\Models\MissionRun;
use App\Services\AiFailure;
use App\Services\PexelsClient;
use App\Services\SentenceChecker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Reading, one task per screen (lesson design 9): the passage alone (tap a
 * word for its meaning), the true/false cards one per screen, then each
 * question on its own screen — typed or said, checked from the bottom,
 * the passage a tap away in a sheet. The new words join My Words by
 * themselves.
 */
new class extends Component
{
    use DictatesAnswers;
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

    public function questions(): array
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
            $this->checkErrors[$index] = AiFailure::message($e, "Couldn't check this one");
        }
    }

    /**
     * After 3 failed attempts on the same answer, the learner can ask the
     * AI to just write the corrected version — see TracksCheckAttempts.
     */
    /**
     * The mic in an answer box: what was said becomes the answer and is
     * checked straight away.
     */
    public function dictateAnswer(int $index): void
    {
        $text = isset($this->questions()[$index]) ? $this->dictatedText($index) : null;

        if ($text === null) {
            return;
        }

        $this->answers[$index] = $text;
        $this->runCheck($index, $text);
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

        $severities = $filled->keys()->map(fn ($index) => $this->feedback[$index]['severity'] ?? 'none')->values();

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'reading_comprehension',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode(['answers' => $filled->values(), 'severities' => $severities]),
        ]);

        // The passage's new words join My Words by themselves (lesson design 9).
        $this->initWordsToTrack();
        $this->addWordsToNotebook();

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
    $draftPrefix = $this->draftPrefix();
    $questions = $this->questions();
    $cards = $readOnly ? [] : $this->comprehensionCards();
    // Screens: 0 start · 1 the passage · 2 true/false · 3.. one question each.
    $quizScreen = count($cards) ? 2 : null;
    $firstQuestionScreen = 3;
    $screenCount = $firstQuestionScreen + count($questions);
    $initialFilled = collect($answers)->map(fn ($a) => trim((string) $a) !== '')->values();
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
    data-initial-filled="{{ $initialFilled->toJson() }}"
    x-data="{
        screen: 0,
        quizShare: 0,
        text: false,
        filled: [],
        edited: {},
        init() {
            this.filled = JSON.parse(this.$el.dataset.initialFilled);
            this.screen = window.eosDraft.restoreIndex('{{ $draftPrefix }}screen', 0);
            this.$watch('screen', (v) => window.eosDraft.persistIndex('{{ $draftPrefix }}screen', v));
        },
        get share() { return (this.screen + (this.screen === 2 ? this.quizShare : 0.5)) / {{ $screenCount }} },
    }"
    x-effect="$dispatch('step-progress', { share })"
    x-on:quick-round-progress="quizShare = $event.detail.share"
    x-back-close="{ open: screen > 0, close: () => screen-- }"
>
    <x-lesson-screen when="screen === 0">
        <x-step-intro kind="Reading" :minutes="$run->mission->stepDuration('reading_comprehension')" :title="$reading['hook'] ?? null" :image="$this->passageImageUrl" />

        <x-slot:actions>
            <x-continue-button on-click="screen = 1" wire-target="save">Start</x-continue-button>
        </x-slot:actions>
    </x-lesson-screen>

    {{-- The only screen that scrolls: the passage, a tap on any word for its meaning. --}}
    <x-lesson-screen when="screen === 1">
        <h2 class="font-display lesson-title text-ink dark:text-ink-dark">{{ $reading['passage_title'] ?? 'Reading' }}</h2>
        <p x-lookup class="text-base leading-loose text-ink dark:text-ink-dark">{!! $passageHtml !!}</p>

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

    @foreach ($questions as $index => $question)
        @php
            $itemFeedback = $feedback[$index] ?? null;
            $isChecked = $itemFeedback && ($itemFeedback['checkedText'] ?? null) === trim((string) ($answers[$index] ?? ''));
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
                on-input="filled[{{ $index }}] = $el.value.trim() !== ''; edited[{{ $index }}] = true"
                :error="$checkErrors[$index] ?? ($dictationErrors[$index] ?? null)"
            />
            <x-ai-thinking wire:loading wire:target="checkOne({{ $index }}), dictateAnswer, revealCorrection({{ $index }}), save" />

            <x-slot:actions>
                <x-checked-answer-actions
                    :index="$index"
                    :feedback="$itemFeedback"
                    :checked="(bool) $isChecked"
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
