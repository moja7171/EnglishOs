<?php

use App\Livewire\Concerns\TracksCheckAttempts;
use App\Livewire\Concerns\TracksVocabularyNotebook;
use App\Models\Evidence;
use App\Models\MissionRun;
use App\Services\SentenceChecker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use TracksCheckAttempts;
    use TracksVocabularyNotebook;
    use WithFileUploads;

    public MissionRun $run;

    public bool $readOnly = false;

    /**
     * True once Continue has passed every check and Evidence is saved —
     * the step then shows a recap of the target expressions before the
     * learner actually navigates on, instead of jumping away immediately.
     */
    public bool $completed = false;

    /** @var array<int, string> */
    public array $gistPoints = ['', '', ''];

    /** @var array<int, string> */
    public array $expressionsHeard = ['', '', ''];

    /**
     * Third listening — one specific fact, checked locally (a known correct
     * answer exists, so no AI call is needed — same reasoning as Grammar in
     * Context's Quick Check). Required, and blocks Continue like gist/
     * expressions do, since it's testing real comprehension of one detail.
     */
    public string $detailAnswer = '';

    /** @var array<int, string> optional bonus — one blank per target phrase, never required */
    public array $gapFillAnswers = ['', '', '', '', ''];

    /** @var array<string, array{severity: string, hint: string, checkedText: string}> keyed by field key */
    public array $feedback = [];

    /** @var array{severity: string, hint: string}|null local verdict for detailAnswer */
    public ?array $detailFeedback = null;

    /** @var array<int, array{severity: string, hint: string}> local verdicts for gapFillAnswers, keyed by index */
    public array $gapFillFeedback = [];

    /** @var array<string, string> keyed by field key — per-input check failure message */
    public array $checkErrors = [];

    /**
     * Shadowing — optional self-practice, repeating a real transcript line
     * out loud. No AI grading and no Evidence: the point is comparing your
     * own voice to the real audio, not another graded checkpoint (Article
     * 12 — AI guides, it doesn't need to referee every single thing).
     */
    public ?UploadedFile $shadowRecording = null;

    public ?int $activeShadowLine = null;

    public function mount(): void
    {
        if (! $this->readOnly) {
            return;
        }

        $data = json_decode($this->run->latestEvidence('listening')?->content_ref ?? '{}', true);

        $this->gistPoints = array_pad($data['gist_points'] ?? [], 3, '');
        $this->expressionsHeard = array_pad($data['expressions_heard'] ?? [], 3, '');
        $this->detailAnswer = $data['detail_answer'] ?? '';
        $this->gapFillAnswers = array_pad($data['gap_fill_answers'] ?? [], 5, '');
    }

    public function checkGist(int $index): void
    {
        $text = trim($this->gistPoints[$index] ?? '');

        if ($text === '') {
            $this->checkErrors["gist_{$index}"] = 'Write something first.';

            return;
        }

        $this->runCheck("gist_{$index}", $this->gistContext(), $text);
    }

    public function checkExpression(int $index): void
    {
        $text = trim($this->expressionsHeard[$index] ?? '');

        if ($text === '') {
            $this->checkErrors["expr_{$index}"] = 'Write something first.';

            return;
        }

        $this->runCheck("expr_{$index}", $this->expressionContext(), $text);
    }

    /**
     * The detail question has one real, known-correct answer (unlike gist/
     * expressions, which deliberately avoid fact-checking) — so, like
     * Grammar in Context's Quick Check, this is a plain local comparison,
     * no AI call needed.
     */
    public function checkDetailAnswer(int $index = 0): void
    {
        $question = $this->run->mission->stepContent('listening')['detail_question'] ?? null;
        $answer = trim($this->detailAnswer);

        if (! $question) {
            return;
        }

        if ($answer === '') {
            $this->checkErrors['detail'] = 'Write something first.';

            return;
        }

        unset($this->checkErrors['detail']);

        $normalized = $this->normalize($answer);
        $isCorrect = collect($question['accepted'])->contains(
            fn ($accepted) => str_contains($normalized, $this->normalize($accepted))
        );

        $this->detailFeedback = $isCorrect
            ? ['severity' => 'none', 'hint' => '', 'checkedText' => $answer]
            : ['severity' => 'major', 'hint' => 'Not quite — listen again for the exact detail.', 'checkedText' => $answer];
    }

    /**
     * Gap-fill also has one known-correct answer per blank (the target
     * phrase itself), but it's optional bonus practice — 'minor' only, so
     * it never blocks Continue the way the required fields above do.
     */
    public function checkGapFill(int $index): void
    {
        $phrases = $this->run->mission->stepContent('listening')['target_phrases'] ?? [];
        $target = $phrases[$index]['phrase'] ?? null;
        $answer = trim($this->gapFillAnswers[$index] ?? '');

        if (! $target) {
            return;
        }

        if ($answer === '') {
            $this->checkErrors["gap_{$index}"] = 'Write something first.';

            return;
        }

        unset($this->checkErrors["gap_{$index}"]);

        $isCorrect = $this->normalize($answer) === $this->normalize($target);

        $this->gapFillFeedback[$index] = $isCorrect
            ? ['severity' => 'none', 'hint' => '']
            : ['severity' => 'minor', 'hint' => 'Not quite — try to recall the exact phrase you heard.'];
    }

    private function normalize(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', strtolower(rtrim(trim($text), '.!?'))));
    }

    /**
     * Selecting a new line to shadow clears any previous recording so an
     * old take is never mistaken for a take of the newly-picked line.
     */
    public function selectShadowLine(int $index): void
    {
        $this->activeShadowLine = $index;
        $this->shadowRecording = null;
    }

    /**
     * A faithful summary of the real transcript (seeded per mission) so the
     * AI check can catch an answer that is fluent English but unrelated to
     * what was actually said, not just judge grammar in isolation.
     */
    private function topicSummary(): ?string
    {
        return $this->run->mission->stepContent('listening')['topic_summary'] ?? null;
    }

    private function gistContext(): string
    {
        $context = 'a complete English sentence describing one thing the learner understood from a B1-level listening';

        return $this->topicSummary() ? "{$context}. The listening was about: {$this->topicSummary()}" : $context;
    }

    private function expressionContext(): string
    {
        $context = 'a personal sentence using an expression the learner heard in a B1-level listening';

        return $this->topicSummary() ? "{$context}. The listening was about: {$this->topicSummary()}" : $context;
    }

    /**
     * Asks the shared SentenceChecker to judge one field, generalized with
     * a per-field context instead of a single target word, plus a
     * topic-relevance judgment grounded in the real transcript summary.
     * Verdict is tagged with the exact text it applies to, so a later edit
     * doesn't leave a stale verdict attached to different text. See
     * EOS-009 §8 "الگوی چک جمله" for the shared rules.
     */
    private function runCheck(string $key, string $context, string $text): void
    {
        unset($this->checkErrors[$key]);

        try {
            $data = app(SentenceChecker::class)->check(
                judgment: 'Judge whether what the learner wrote is a genuine, natural, complete English '
                    .'sentence about the SAME GENERAL TOPIC as the listening (not just a bare word or fragment, '
                    .'and not about a completely different topic). This is a coarse topic check only — do NOT '
                    .'fact-check specific details against the topic summary (who said what, exact opinions, '
                    .'etc.); the summary is background, not a source to grade accuracy against.',
                majorCriteria: 'it is just a bare word or fragment (not a real sentence), it is about a '
                    .'completely different topic than the listening',
                context: $context,
                text: $text,
                extraGuidance: 'Treat anything on-topic and correctly formed as "none", even if a small detail '
                    .'is debatable — never claim the learner\'s facts are wrong, since you were only given a '
                    .'short summary, not the full listening.'.$this->run->aiToneGuidance(),
            );

            $this->feedback[$key] = $data + ['checkedText' => $text];
            $this->trackCheckAttempt($key, $data['severity']);
        } catch (ConnectionException|RequestException) {
            // RequestException's message carries the raw HTTP response body
            // (which can be an arbitrarily large error page, not a clean
            // API message) — never show that to the learner.
            $this->checkErrors[$key] = "Couldn't reach the AI service — please try again.";
        } catch (Throwable $e) {
            $this->checkErrors[$key] = "Couldn't check this one: {$e->getMessage()}";
        }
    }

    /**
     * After 3 failed attempts on the same field, the learner can ask the AI
     * to just write the corrected sentence — see TracksCheckAttempts.
     */
    public function revealGist(int $index): void
    {
        $text = trim($this->gistPoints[$index] ?? '');

        if ($text === '') {
            return;
        }

        $key = "gist_{$index}";

        $this->revealCorrectionFor(
            key: $key,
            context: $this->gistContext(),
            text: $text,
            errorBagKey: $key,
            onCorrected: function (string $corrected) use ($index, $key) {
                $this->gistPoints[$index] = $corrected;
                $this->feedback[$key] = ['severity' => 'none', 'hint' => '', 'checkedText' => $corrected];
            },
        );
    }

    public function declineGist(int $index): void
    {
        $this->declineCheckReveal("gist_{$index}");
    }

    public function revealExpression(int $index): void
    {
        $text = trim($this->expressionsHeard[$index] ?? '');

        if ($text === '') {
            return;
        }

        $key = "expr_{$index}";

        $this->revealCorrectionFor(
            key: $key,
            context: $this->expressionContext(),
            text: $text,
            errorBagKey: $key,
            onCorrected: function (string $corrected) use ($index, $key) {
                $this->expressionsHeard[$index] = $corrected;
                $this->feedback[$key] = ['severity' => 'none', 'hint' => '', 'checkedText' => $corrected];
            },
        );
    }

    public function declineExpression(int $index): void
    {
        $this->declineCheckReveal("expr_{$index}");
    }

    public function save(): void
    {
        $gist = collect($this->gistPoints)->map(fn ($p) => trim($p))->filter();

        if ($gist->count() < 3) {
            $this->addError('gistPoints', 'Write all 3 things you understood before continuing.');

            return;
        }

        $hasDetailQuestion = (bool) ($this->run->mission->stepContent('listening')['detail_question'] ?? null);
        $detailAnswer = trim($this->detailAnswer);

        if ($hasDetailQuestion && $detailAnswer === '') {
            $this->addError('detailAnswer', 'Answer the detail question before continuing.');

            return;
        }

        $entries = collect();

        foreach ($this->gistPoints as $index => $text) {
            $text = trim($text);
            if ($text !== '') {
                $entries->push(['key' => "gist_{$index}", 'context' => $this->gistContext(), 'text' => $text]);
            }
        }

        foreach ($this->expressionsHeard as $index => $text) {
            $text = trim($text);
            if ($text !== '') {
                $entries->push(['key' => "expr_{$index}", 'context' => $this->expressionContext(), 'text' => $text]);
            }
        }

        // Every filled sentence needs a fresh Gemini verdict before Continue
        // is allowed through — reuse an existing one only if it was checked
        // against this exact text (an edit since the last check invalidates it).
        foreach ($entries as $entry) {
            $alreadyChecked = ($this->feedback[$entry['key']]['checkedText'] ?? null) === $entry['text'];

            if (! $alreadyChecked) {
                $this->runCheck($entry['key'], $entry['context'], $entry['text']);
            }
        }

        // The detail question is local (no AI call), but follows the same
        // "re-check only if edited since last check" rule.
        $detailAlreadyChecked = ($this->detailFeedback['checkedText'] ?? null) === $detailAnswer;

        if (! $detailAlreadyChecked) {
            $this->checkDetailAnswer();
        }

        $hasMajorIssue = $entries->contains(
            fn ($entry) => ($this->feedback[$entry['key']]['severity'] ?? null) === 'major'
        ) || ($this->detailFeedback['severity'] ?? null) === 'major';

        if ($hasMajorIssue) {
            $this->addError('sentences', 'Fix the highlighted sentence before continuing.');

            return;
        }

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'listening',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode([
                'gist_points' => $gist->values(),
                'expressions_heard' => collect($this->expressionsHeard)->map(fn ($e) => trim($e))->filter()->values(),
                'detail_answer' => $detailAnswer,
                // Optional bonus practice — saved if attempted, but never
                // required and never blocks Continue.
                'gap_fill_answers' => collect($this->gapFillAnswers)->map(fn ($a) => trim($a))->filter()->values(),
            ]),
        ]);

        // Progress is already saved — this only decides what the learner sees
        // next: the language recap, which they dismiss with proceed() below.
        $this->dispatch('clear-draft', prefix: $this->draftPrefix());
        $this->completed = true;
        $this->initWordsToTrack();
    }

    public function proceed(): void
    {
        $this->redirect(route('missions.show', $this->run->mission), navigate: true);
    }

    /**
     * @return list<array{word: string, meaning: string}>
     */
    protected function notebookCandidates(): array
    {
        $phrases = $this->run->mission->stepContent('listening')['target_phrases'] ?? [];

        return collect($phrases)
            ->map(fn ($item) => ['word' => $item['phrase'], 'meaning' => $item['meaning'] ?? ''])
            ->values()
            ->all();
    }

    /**
     * Must match the prefix embedded in the Blade template's x-draft
     * attributes exactly — both build it the same way from the run id.
     */
    public function draftPrefix(): string
    {
        return "eos-draft:{$this->run->id}:listening:";
    }
};
?>

@php
    $listening = $run->mission->stepContent('listening');
    $targetPhrases = $listening['target_phrases'] ?? [];
    $transcript = $listening['transcript'] ?? [];
    $detailQuestion = $listening['detail_question'] ?? null;
    $shadowLines = $listening['shadow_lines'] ?? [];
    $initialGistFilled = collect($gistPoints)->map(fn ($p) => trim($p) !== '')->values();
    $initialExpressionsFilled = collect($expressionsHeard)->map(fn ($p) => trim($p) !== '')->values();
    $draftPrefix = $this->draftPrefix();
    $listensRequired = 2;
    $checkTargets = 'checkGist,checkExpression,checkDetailAnswer,checkGapFill,revealGist,declineGist,revealExpression,declineExpression,save';

    // One focused sub-step per phase instead of stacking everything into
    // one long scroll (see EOS-009 §8's shared <x-substep-nav>). The
    // transcript stays outside this pager — it's tied to listenCount, not
    // to any one exercise, so it should stay visible no matter which
    // sub-step is active.
    $hasDetailQuestion = (bool) $detailQuestion;
    $gistIndex = 0;
    $exprIndex = 1;
    $detailIndex = $hasDetailQuestion ? 2 : null;
    $wrapupIndex = $hasDetailQuestion ? 3 : 2;
    $totalSubsteps = $wrapupIndex + 1;
    $initialDetailFilled = trim($detailAnswer) !== '';
    $nextDisabledParts = [
        "(activeSubstep === {$gistIndex} && !gistDone)",
        "(activeSubstep === {$exprIndex} && !expressionsDone)",
    ];
    if ($hasDetailQuestion) {
        $nextDisabledParts[] = "(activeSubstep === {$detailIndex} && !detailFilled)";
    }
    $nextDisabledExpr = implode(' || ', $nextDisabledParts);
@endphp

<div
    class="space-y-6"
    x-data="{
        dismissed: {},
        gistFilled: {{ $initialGistFilled->toJson() }},
        get gistDone() { return this.gistFilled.filter(Boolean).length === 3 },
        expressionsFilled: {{ $initialExpressionsFilled->toJson() }},
        get expressionsDone() { return this.expressionsFilled.filter(Boolean).length === 3 },
        detailFilled: {{ $initialDetailFilled ? 'true' : 'false' }},
        activeSubstep: 0,
        listenCount: 0,
        showTranscript: false,
        get transcriptUnlocked() { return this.listenCount >= {{ $listensRequired }} },
    }"
    x-on:audio-ended="listenCount++"
>
    <x-hook :text="$listening['hook'] ?? null" />

    <div>
        <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">{{ $listening['source'] ?? 'Listening' }}</p>
        <div class="mt-2">
            <x-audio-player :url="$listening['audio_url'] ?? null" on-ended="$dispatch('audio-ended')" />
        </div>

        @if (count($transcript))
            <div class="mt-3">
                @if ($readOnly)
                    <button
                        type="button"
                        x-on:click="showTranscript = !showTranscript"
                        class="inline-flex cursor-pointer items-center gap-1 text-xs font-semibold text-ink-faint underline decoration-dotted underline-offset-2 dark:text-ink-faint-dark"
                    >
                        <span x-show="!showTranscript">Show transcript</span>
                        <span x-show="showTranscript" x-cloak>Hide transcript</span>
                    </button>
                @else
                    <p x-show="!transcriptUnlocked" class="flex items-center gap-1.5 text-xs text-ink-faint dark:text-ink-faint-dark">
                        @svg('heroicon-o-lock-closed', 'h-3.5 w-3.5 shrink-0')
                        <span x-text="`Listen ${Math.min(listenCount, {{ $listensRequired }})}/{{ $listensRequired }} times to unlock the transcript — reading along too early skips the real listening practice.`"></span>
                    </p>
                    <div x-show="transcriptUnlocked" x-cloak x-transition.opacity.duration.300ms>
                        <p class="flex items-center gap-1 text-xs font-semibold text-success dark:text-success-dark">
                            @svg('heroicon-o-check-circle', 'h-3.5 w-3.5')
                            Transcript unlocked
                        </p>
                        <button
                            type="button"
                            x-on:click="showTranscript = !showTranscript"
                            class="mt-1 inline-flex cursor-pointer items-center gap-1 text-xs font-semibold text-ink-faint underline decoration-dotted underline-offset-2 dark:text-ink-faint-dark"
                        >
                            <span x-show="!showTranscript">Show transcript</span>
                            <span x-show="showTranscript" x-cloak>Hide transcript</span>
                        </button>
                    </div>
                @endif

                <div
                    x-show="showTranscript && {{ $readOnly ? 'true' : 'transcriptUnlocked' }}"
                    x-cloak
                    class="mt-2 max-h-72 space-y-2 overflow-y-auto rounded-2xl border border-line bg-surface-sunken p-4 text-sm dark:border-line-dark dark:bg-surface-sunken-dark"
                >
                    @foreach ($transcript as $turn)
                        <p>
                            <span class="font-semibold text-ink dark:text-ink-dark">{{ $turn['speaker'] }}:</span>
                            <span class="text-ink-soft dark:text-ink-soft-dark">{{ $turn['text'] }}</span>
                        </p>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    @if ($completed)
        <div class="space-y-4 rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
            <div>
                <p class="inline-flex items-center gap-1 text-xs font-semibold tracking-wide text-success uppercase dark:text-success-dark">
                    @svg('heroicon-o-check-circle', 'h-4 w-4')
                    Listening complete
                </p>
                <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">Here's the language from today's episode — pick which ones join your spaced-repetition notebook.</p>
            </div>
            <div class="space-y-2">
                @foreach ($targetPhrases as $index => $item)
                    <label class="flex cursor-pointer items-start gap-2.5 rounded-xl border border-line p-3 dark:border-line-dark">
                        <input
                            type="checkbox"
                            wire:model="wordsToTrack.{{ $index }}"
                            class="mt-0.5 h-4 w-4 shrink-0 cursor-pointer rounded border-line text-accent focus:ring-accent dark:border-line-dark dark:bg-surface-dark dark:text-accent-dark"
                        >
                        <span>
                            <span class="block text-sm font-bold text-ink dark:text-ink-dark">{{ $item['phrase'] }}</span>
                            <span class="block text-xs text-ink-faint dark:text-ink-faint-dark">{{ $item['meaning'] }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
            <div class="flex flex-wrap items-center gap-3">
                @if ($trackedWords)
                    <span class="inline-flex items-center gap-1 text-sm font-semibold text-success dark:text-success-dark">
                        @svg('heroicon-o-check-circle', 'h-4 w-4') Added to My Words
                    </span>
                @else
                    <button
                        type="button"
                        wire:click="addWordsToNotebook"
                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-line px-4 py-2 text-sm font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                    >@svg('heroicon-o-book-open', 'h-4 w-4') Add to My Words</button>
                @endif

                <button
                    wire:click="proceed"
                    class="cursor-pointer rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
                >
                    Continue
                </button>
            </div>
        </div>
    @else
    <div wire:loading.class="pointer-events-none" wire:target="{{ $checkTargets }}">
        <div class="mb-4">
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

        {{-- Sub-step: First listening — gist --}}
        <div x-show="activeSubstep === {{ $gistIndex }}" x-cloak>
            <p class="text-sm font-semibold text-ink dark:text-ink-dark">First listening — gist</p>
            <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Listen without the transcript. What is the conversation about? Write 3 full sentences about what you understood. Check one anytime for feedback, or we'll check the rest for you when you move on.</p>
            @unless ($readOnly)
                <div class="mt-2">
                    <x-progress-bar>
                        <div
                            class="h-full rounded-full transition-all duration-300"
                            :class="gistDone ? 'bg-success dark:bg-success-dark' : 'bg-accent dark:bg-accent-dark'"
                            :style="`width: ${gistFilled.filter(Boolean).length / 3 * 100}%`"
                        ></div>
                        <x-slot:label>
                            <p
                                class="text-xs font-semibold transition-colors"
                                :class="gistDone ? 'text-success dark:text-success-dark' : 'text-ink-soft dark:text-ink-soft-dark'"
                                x-text="`${gistFilled.filter(Boolean).length} of 3 written`"
                            ></p>
                        </x-slot:label>
                    </x-progress-bar>
                </div>
            @endunless
            <div class="mt-2 space-y-2">
                @foreach ($gistPoints as $index => $point)
                    @php $key = "gist_{$index}"; $itemFeedback = $feedback[$key] ?? null; @endphp
                    <div>
                        <div class="flex items-center gap-2">
                            <input
                                type="text"
                                wire:model="gistPoints.{{ $index }}"
                                placeholder="Sentence {{ $index + 1 }}…"
                                @unless ($readOnly)
                                    x-draft="{ key: '{{ $draftPrefix }}gistPoints.{{ $index }}', field: 'gistPoints.{{ $index }}' }"
                                @endunless
                                @readonly($readOnly)
                                wire:loading.attr="disabled"
                                wire:target="checkGist,revealGist,declineGist,save"
                                x-on:input="dismissed['{{ $key }}'] = true; gistFilled[{{ $index }}] = $el.value.trim() !== ''"
                                class="w-full rounded-lg border border-line bg-transparent px-2 py-1 text-sm text-ink disabled:opacity-50 dark:border-line-dark dark:text-ink-dark"
                            >
                            @unless ($readOnly)
                                <x-check-button method="checkGist" :index="$index" key-prefix="gist_" wire-target="checkGist,revealGist,declineGist,save" />
                            @endunless
                        </div>

                        @unless ($readOnly)
                            <x-ai-thinking wire:loading wire:target="checkGist({{ $index }}), revealGist({{ $index }})" class="mt-2" />
                        @endunless

                        <div x-show="!dismissed['{{ $key }}']" x-transition.opacity.duration.300ms>
                            <x-severity-feedback :feedback="$itemFeedback" :error="$checkErrors[$key] ?? null" />
                        </div>

                        @unless ($readOnly)
                            <x-almost-reveal-notice :show="($checkAttempts[$key] ?? 0) === 2" />
                            <x-reveal-offer
                                :show="$offerReveal[$key] ?? false"
                                reveal-method="revealGist"
                                decline-method="declineGist"
                                :index="$index"
                                wire-target="checkGist,revealGist,declineGist,save"
                            />
                        @endunless
                    </div>
                @endforeach
            </div>
            @error('gistPoints')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
            @unless ($readOnly)
                <p x-show="!gistDone" class="mt-2 flex items-center gap-1 text-xs text-ink-faint dark:text-ink-faint-dark">
                    @svg('heroicon-o-lock-closed', 'h-3.5 w-3.5')
                    Write all 3 to move on.
                </p>
            @endunless
        </div>

        {{-- Sub-step: Second listening — expressions --}}
        <div x-show="activeSubstep === {{ $exprIndex }}" x-cloak>
            <p class="text-sm font-semibold text-ink dark:text-ink-dark">Second listening — useful expressions</p>
            <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Write a full sentence using each expression you heard.</p>
            @unless ($readOnly)
                @if (count($targetPhrases))
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        @foreach ($targetPhrases as $item)
                            <button
                                type="button"
                                title="{{ $item['meaning'] }}"
                                x-on:click="
                                    let idx = $wire.expressionsHeard.findIndex(v => !v || v.trim() === '');
                                    if (idx === -1) idx = 0;
                                    dismissed['expr_' + idx] = true;
                                    expressionsFilled[idx] = true;
                                    $wire.set('expressionsHeard.' + idx, '{{ ucfirst($item['phrase']) }}');
                                    $nextTick(() => $refs['expr_input_' + idx]?.focus());
                                "
                                class="cursor-pointer rounded-full border border-line px-2.5 py-1 text-xs text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                            >{{ $item['phrase'] }}</button>
                        @endforeach
                    </div>
                @endif
            @endunless
            <div class="mt-2 space-y-2">
                @foreach ($expressionsHeard as $index => $expression)
                    @php $key = "expr_{$index}"; $itemFeedback = $feedback[$key] ?? null; @endphp
                    <div>
                        <div class="flex items-center gap-2">
                            <input
                                type="text"
                                x-ref="expr_input_{{ $index }}"
                                wire:model="expressionsHeard.{{ $index }}"
                                placeholder="Sentence {{ $index + 1 }}…"
                                @unless ($readOnly)
                                    x-draft="{ key: '{{ $draftPrefix }}expressionsHeard.{{ $index }}', field: 'expressionsHeard.{{ $index }}' }"
                                @endunless
                                @readonly($readOnly)
                                wire:loading.attr="disabled"
                                wire:target="checkExpression,revealExpression,declineExpression,save"
                                x-on:input="dismissed['{{ $key }}'] = true; expressionsFilled[{{ $index }}] = $el.value.trim() !== ''"
                                class="w-full rounded-lg border border-line bg-transparent px-2 py-1 text-sm text-ink disabled:opacity-50 dark:border-line-dark dark:text-ink-dark"
                            >
                            @unless ($readOnly)
                                <x-check-button method="checkExpression" :index="$index" key-prefix="expr_" wire-target="checkExpression,revealExpression,declineExpression,save" />
                            @endunless
                        </div>

                        @unless ($readOnly)
                            <x-ai-thinking wire:loading wire:target="checkExpression({{ $index }}), revealExpression({{ $index }})" class="mt-2" />
                        @endunless

                        <div x-show="!dismissed['{{ $key }}']" x-transition.opacity.duration.300ms>
                            <x-severity-feedback :feedback="$itemFeedback" :error="$checkErrors[$key] ?? null" />
                        </div>

                        @unless ($readOnly)
                            <x-almost-reveal-notice :show="($checkAttempts[$key] ?? 0) === 2" />
                            <x-reveal-offer
                                :show="$offerReveal[$key] ?? false"
                                reveal-method="revealExpression"
                                decline-method="declineExpression"
                                :index="$index"
                                wire-target="checkExpression,revealExpression,declineExpression,save"
                            />
                        @endunless
                    </div>
                @endforeach
            </div>
            @unless ($readOnly)
                <p x-show="!expressionsDone" class="mt-2 flex items-center gap-1 text-xs text-ink-faint dark:text-ink-faint-dark">
                    @svg('heroicon-o-lock-closed', 'h-3.5 w-3.5')
                    Write all 3 to move on.
                </p>
            @endunless
        </div>

        @if ($hasDetailQuestion)
            {{-- Sub-step: Third listening — a detail --}}
            <div x-show="activeSubstep === {{ $detailIndex }}" x-cloak>
                <p class="text-sm font-semibold text-ink dark:text-ink-dark">Third listening — a detail</p>
                <p class="text-xs text-ink-faint dark:text-ink-faint-dark">{{ $detailQuestion['question'] }}</p>

                <div class="mt-2">
                    <div class="flex items-center gap-2">
                        <input
                            type="text"
                            wire:model="detailAnswer"
                            placeholder="Your answer…"
                            @unless ($readOnly)
                                x-draft="{ key: '{{ $draftPrefix }}detailAnswer', field: 'detailAnswer' }"
                            @endunless
                            @readonly($readOnly)
                            wire:loading.attr="disabled"
                            wire:target="{{ $checkTargets }}"
                            x-on:input="dismissed['detail_0'] = true; detailFilled = $el.value.trim() !== ''"
                            class="w-full rounded-lg border border-line bg-transparent px-2 py-1 text-sm text-ink disabled:opacity-50 dark:border-line-dark dark:text-ink-dark"
                        >
                        @unless ($readOnly)
                            <x-check-button method="checkDetailAnswer" :index="0" key-prefix="detail_" wire-target="{{ $checkTargets }}" />
                        @endunless
                    </div>

                    <div x-show="!dismissed['detail_0']" x-transition.opacity.duration.300ms>
                        <x-severity-feedback :feedback="$detailFeedback" :error="$checkErrors['detail'] ?? null" />
                    </div>
                </div>
                @error('detailAnswer')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
                @unless ($readOnly)
                    <p x-show="!detailFilled" class="mt-2 flex items-center gap-1 text-xs text-ink-faint dark:text-ink-faint-dark">
                        @svg('heroicon-o-lock-closed', 'h-3.5 w-3.5')
                        Answer to move on.
                    </p>
                @endunless
            </div>
        @endif

        {{-- Sub-step: Wrap-up — transcript recap, bonus practice, Continue --}}
        <div x-show="activeSubstep === {{ $wrapupIndex }}" x-cloak>
            <p class="text-sm font-semibold text-ink dark:text-ink-dark">Wrap-up</p>
            <p class="text-xs text-ink-faint dark:text-ink-faint-dark">A couple of optional extras, then you're done with this episode.</p>

            @if (count($targetPhrases) && collect($targetPhrases)->contains(fn ($p) => isset($p['gap_before'])))
                <div class="mt-4">
                    <p class="text-sm font-semibold text-ink dark:text-ink-dark">Bonus — fill the gap</p>
                    <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Optional — real lines from the conversation, from memory. Doesn't affect Continue.</p>

                    <div class="mt-2 space-y-3">
                        @foreach ($targetPhrases as $index => $item)
                            @php $gapKey = "gap_{$index}"; $gapFeedback = $gapFillFeedback[$index] ?? null; @endphp
                            <div>
                                <p class="text-sm text-ink-soft dark:text-ink-soft-dark">
                                    {{ $item['gap_before'] ?? '' }}<input
                                        type="text"
                                        wire:model="gapFillAnswers.{{ $index }}"
                                        placeholder="…"
                                        @readonly($readOnly)
                                        wire:loading.attr="disabled"
                                        wire:target="{{ $checkTargets }}"
                                        x-on:input="dismissed['{{ $gapKey }}'] = true"
                                        class="inline w-28 border-b border-line bg-transparent px-1 text-center text-ink disabled:opacity-50 focus:border-accent focus:outline-none dark:border-line-dark dark:text-ink-dark dark:focus:border-accent-dark"
                                    >{{ $item['gap_after'] ?? '' }}
                                    @unless ($readOnly)
                                        <x-check-button method="checkGapFill" :index="$index" key-prefix="gap_" wire-target="{{ $checkTargets }}" />
                                    @endunless
                                </p>
                                <div x-show="!dismissed['{{ $gapKey }}']" x-transition.opacity.duration.300ms>
                                    <x-severity-feedback :feedback="$gapFeedback" :error="$checkErrors[$gapKey] ?? null" />
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            @if (count($shadowLines))
                <div class="mt-4 rounded-2xl border border-line bg-surface-sunken p-4 dark:border-line-dark dark:bg-surface-sunken-dark">
                    <p class="text-sm font-semibold text-ink dark:text-ink-dark">Bonus — shadow a line</p>
                    <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Optional. Pick a real line and repeat it out loud along with the audio — pure pronunciation practice, nothing here is graded or saved.</p>

                    <div class="mt-2 flex flex-wrap gap-1.5">
                        @foreach ($shadowLines as $index => $line)
                            <button
                                type="button"
                                wire:click="selectShadowLine({{ $index }})"
                                @class([
                                    'cursor-pointer rounded-full border px-2.5 py-1 text-xs transition-colors',
                                    'border-accent bg-accent text-white dark:border-accent-dark dark:bg-accent-dark' => $activeShadowLine === $index,
                                    'border-line text-ink-soft hover:border-ink-faint hover:bg-surface dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-dark' => $activeShadowLine !== $index,
                                ])
                            >Line {{ $index + 1 }}</button>
                        @endforeach
                    </div>

                    @if ($activeShadowLine !== null)
                        <p class="mt-3 text-xs text-ink-faint dark:text-ink-faint-dark">Bold words are usually stressed — try to make them a little longer and louder than the rest.</p>
                        <p class="mt-1 text-sm text-ink dark:text-ink-dark">"<x-stress-marked-line :text="$shadowLines[$activeShadowLine]" />"</p>
                        <div class="mt-2" wire:key="shadow-recorder-{{ $activeShadowLine }}">
                            <x-voice-recorder field="shadowRecording" :file="$shadowRecording" file-name="shadow.webm" />
                        </div>
                        <p class="mt-2 text-xs text-ink-faint dark:text-ink-faint-dark">Once you've recorded, listen back and compare your rhythm to the bold pattern above.</p>
                    @endif
                </div>
            @endif

            @if ($listening['topic_summary'] ?? null)
                <div class="mt-4">
                    <x-practice-with-friend
                        :text="$listening['topic_summary']"
                        intro="Hey — want to discuss this listening topic with me:"
                        label="Discuss this with a friend"
                    />
                </div>
            @endif

            @error('sentences')
                <p class="mt-4 text-sm text-red-600">{{ $message }}</p>
            @enderror

            @unless ($readOnly)
                <div class="mt-4">
                    <x-continue-button
                        on-click="['gist_0','gist_1','gist_2','expr_0','expr_1','expr_2','detail_0'].forEach(k => dismissed[k] = true); $wire.save().then(() => { dismissed = {} })"
                        wire-target="{{ $checkTargets }}"
                        loading-label="Checking your sentences…"
                    />
                </div>
            @endunless
        </div>
    </div>

    <div class="mt-4">
        <x-substep-nav index-var="activeSubstep" :total="$totalSubsteps" :next-disabled="$nextDisabledExpr" />
    </div>
    @endif
</div>
