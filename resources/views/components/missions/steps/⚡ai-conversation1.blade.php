<?php

use App\Livewire\Concerns\TracksAiUsage;
use App\Livewire\Concerns\TracksCheckAttempts;
use App\Models\AIFeedback;
use App\Models\Evidence;
use App\Models\MissionRun;
use App\Services\AiFeedbackCard;
use App\Services\GeminiClient;
use App\Services\GroqClient;
use App\Services\SentenceChecker;
use App\Services\SpokenAnswerChecker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Mission structure redesign, Epic E: Activation (write 5 personal
 * sentences + record 2 minutes of unscripted solo speaking, with a
 * Persian reflection) is now this step's own first, unscripted round —
 * not a separate step before it — and AI Feedback #1 (the report card
 * on the interview that follows) is generated automatically as part of
 * this step's own completion recap, not a separate step the learner has
 * to press a button on afterwards. One step, one Evidence save, at the
 * very end of both rounds — matches the established "multi-substep,
 * one save" pattern (see ⚡listening.blade.php, ⚡grammar-in-context.blade.php).
 */
new class extends Component
{
    use WithFileUploads;
    use TracksAiUsage;
    use TracksCheckAttempts;

    public MissionRun $run;

    public bool $readOnly = false;

    /**
     * True once the warm-up round (sentences + recording + reflection) is
     * done and the learner has moved into the interview questions — kept
     * server-side, same reasoning as Grammar in Context's
     * $practiceStarted (a Livewire re-render must not snap this back).
     */
    public bool $warmUpDone = false;

    // --- Warm-up round (was Activation) ---

    /** @var array<int, string> */
    public array $sentences = ['', '', '', '', ''];

    /** @var array<int, array{severity: string, hint: string, checkedText: string}> keyed by sentence index */
    public array $feedback = [];

    /** @var array<int, string> keyed by sentence index — per-input check failure message */
    public array $checkErrors = [];

    public ?UploadedFile $warmUpAudioFile = null;

    /** Stored to disk (not just held in memory) the moment the warm-up round finishes — see finishWarmUp(). */
    public ?string $warmUpAudioUrl = null;

    /** Groq transcript of the warm-up recording — see Activation's old transcribeAndReflect() docblock. */
    public ?string $transcript = null;

    /** @var list<array{text: string, confidence: string}> */
    public array $segments = [];

    /** @var array{highlight: string, tip: string}|null */
    public ?array $reflection = null;

    // --- Interview round (existing AI Conversation #1) ---

    public int $round = 0;

    /** @var array<int, array{question: string, answer: string, followup: string}> */
    public array $turns = [];

    public ?UploadedFile $audioFile = null;

    public bool $processing = false;

    public ?string $error = null;

    /** @var array<int, string> keyed by round — set when the last spoken attempt was off-topic/empty */
    public array $offTopicHint = [];

    /** @var array<int, string> keyed by round — an example answer, shown only after 3 off-topic attempts */
    public array $exampleAnswer = [];

    // --- Feedback (was AI Feedback #1) — generated automatically, not on request ---

    public ?string $fbStrength = null;

    public ?string $fbExpression = null;

    public ?string $fbCorrectionOriginal = null;

    public ?string $fbCorrectionCorrected = null;

    public ?string $fbCorrectionWhy = null;

    public ?string $fbCorrectionSuggestion = null;

    public ?string $fbSeverity = null;

    /**
     * True once every question has been answered, feedback has been
     * generated, and Evidence is saved — the step then shows the full
     * recap (warm-up reflection + conversation + feedback report card)
     * before the learner dismisses it with proceed() below.
     */
    public bool $completed = false;

    /** How many warm-up sentences needed no fix at all this attempt (encouragement score, Epic H). */
    public ?int $correctCount = null;

    /** The same count from the learner's own last attempt at this step, if any. */
    public ?int $previousCorrect = null;

    public function mount(): void
    {
        if (! $this->readOnly) {
            return;
        }

        $data = json_decode($this->run->latestEvidence('ai_conversation_1')?->content_ref ?? '{}', true);

        $this->sentences = array_pad($data['sentences'] ?? [], 5, '');
        $this->transcript = $data['transcript'] ?? null;
        $this->segments = $data['segments'] ?? [];
        $this->reflection = $data['reflection'] ?? null;
        $this->turns = $data['turns'] ?? [];
        $this->round = count($this->turns);
        $this->warmUpDone = true;
        $this->completed = true;

        $this->applyFeedbackData($data['feedback'] ?? []);

        $audioEvidence = $this->run->evidence()->where('phase', 'ai_conversation_1')->where('type', Evidence::TYPE_AUDIO)->latest()->first();
        $this->warmUpAudioUrl = $audioEvidence?->content_ref;
    }

    /** @param array<string, mixed> $data */
    private function applyFeedbackData(array $data): void
    {
        $this->fbStrength = $data['strength'] ?? null;
        $this->fbExpression = $data['expression'] ?? null;
        $this->fbSeverity = $data['severity'] ?? null;

        $correction = $data['correction'] ?? [];
        $this->fbCorrectionOriginal = is_array($correction) ? ($correction['original'] ?? null) : null;
        $this->fbCorrectionCorrected = is_array($correction) ? ($correction['corrected'] ?? null) : null;
        $this->fbCorrectionWhy = is_array($correction) ? ($correction['why'] ?? null) : null;
        $this->fbCorrectionSuggestion = is_array($correction) ? ($correction['suggestion'] ?? null) : null;
    }

    public function getQuestionsProperty(): array
    {
        return $this->run->mission->stepContent('ai_conversation_1')['interview_questions'] ?? [];
    }

    public function getCurrentQuestionProperty(): ?string
    {
        return $this->questions[$this->round] ?? null;
    }

    // ---------------------------------------------------------------
    // Warm-up round (was Activation)
    // ---------------------------------------------------------------

    public function checkOne(int $index): void
    {
        $sentence = trim($this->sentences[$index] ?? '');

        if ($sentence === '') {
            $this->checkErrors[$index] = 'Write something first.';

            return;
        }

        $this->runSentenceCheck($index, $sentence);
    }

    private function runSentenceCheck(int $index, string $sentence): void
    {
        unset($this->checkErrors[$index]);

        try {
            $data = app(SentenceChecker::class)->check(
                judgment: 'Judge whether the learner wrote a genuine, natural personal sentence about their own '
                    .'daily life.',
                majorCriteria: 'it is just a fragment (not a real sentence), or it is not actually about the '
                    .'learner\'s own daily life',
                context: "a personal sentence about the learner's own daily life",
                text: $sentence,
                extraGuidance: $this->run->aiToneGuidance(),
                feedbackDepth: $this->run->mission->feedbackDepth(),
            );
            $this->recordGeminiCall();

            $this->feedback[$index] = $data + ['checkedText' => $sentence];
            $this->trackCheckAttempt("warmup_{$index}", $data['severity']);
        } catch (ConnectionException|RequestException) {
            $this->checkErrors[$index] = "Couldn't reach the AI service — please try again.";
        } catch (Throwable $e) {
            $this->checkErrors[$index] = "Couldn't check this one: {$e->getMessage()}";
        }
    }

    public function revealCorrection(int $index): void
    {
        $sentence = trim($this->sentences[$index] ?? '');

        if ($sentence === '') {
            return;
        }

        $this->revealCorrectionFor(
            key: "warmup_{$index}",
            context: "a personal sentence about the learner's own daily life",
            text: $sentence,
            errorBagKey: $index,
            onCorrected: function (string $corrected) use ($index) {
                $this->sentences[$index] = $corrected;
                $this->feedback[$index] = ['severity' => 'none', 'hint' => '', 'checkedText' => $corrected];
            },
        );
    }

    public function declineReveal(int $index): void
    {
        $this->declineCheckReveal("warmup_{$index}");
    }

    /**
     * Ends the warm-up round: validates the 5 sentences (same rule as the
     * old Activation), stores the recording to permanent disk storage
     * right away (so it survives regardless of how long the interview
     * that follows takes), transcribes it and asks for a Persian
     * reflection — all BEFORE any Evidence exists, since this step isn't
     * done yet. Never creates Evidence itself; see finishInterview().
     */
    public function finishWarmUp(): void
    {
        $this->validate([
            'sentences' => 'array',
            'sentences.*' => 'nullable|string',
            'warmUpAudioFile' => ['required', 'file', 'extensions:webm,ogg,mp3,wav,m4a', 'max:20480'],
        ]);

        $filledSentences = collect($this->sentences)
            ->map(fn ($s, $i) => ['index' => $i, 'text' => trim((string) $s)])
            ->filter(fn ($s) => $s['text'] !== '');

        if ($filledSentences->count() < 5) {
            $this->addError('sentences', 'Write all 5 personal sentences before continuing.');

            return;
        }

        foreach ($filledSentences as $item) {
            $alreadyChecked = ($this->feedback[$item['index']]['checkedText'] ?? null) === $item['text'];

            if (! $alreadyChecked) {
                $this->runSentenceCheck($item['index'], $item['text']);
            }
        }

        $hasMajorIssue = $filledSentences->contains(
            fn ($item) => ($this->feedback[$item['index']]['severity'] ?? null) === 'major'
        );

        if ($hasMajorIssue) {
            $this->addError('sentences', 'Fix the highlighted sentence before continuing.');

            return;
        }

        $mission = $this->run->mission;
        $path = $this->warmUpAudioFile->store('missions/'.strtolower($mission->code).'/evidence', 'public');
        $this->warmUpAudioUrl = Storage::disk('public')->url($path);

        $this->transcribeAndReflect();

        $this->dispatch('clear-draft', prefix: $this->draftPrefix());
        $this->warmUpDone = true;
    }

    /**
     * Transcribes the warm-up recording and asks Gemini for a short,
     * warm, non-blocking reflection in Persian — never a grade, never
     * gates progress. Ported verbatim from the old Activation step; see
     * its docblock for why Persian is the deliberate exception here.
     * Failure is silent by design — $transcript/$reflection just stay
     * null and the recap simply omits them.
     */
    private function transcribeAndReflect(): void
    {
        try {
            $result = app(GroqClient::class)->transcribeWithConfidence($this->warmUpAudioFile->getRealPath());
            $this->recordGroqCall();
            $this->transcript = trim($result['text']);
            $this->segments = $result['segments'];

            if ($this->transcript === '') {
                return;
            }

            $vocabularyWords = $this->run->selectedVocabularyWords();
            $vocabularyContext = $vocabularyWords
                ? ' The learner\'s target vocabulary words for this mission were: '
                    .collect($vocabularyWords)->map(fn ($w) => "\"{$w}\"")->implode(', ')
                    .'. If any of these appear naturally in the transcript, you can mention that warmly.'
                : '';

            $paceContext = $this->paceContext($this->transcript, $result['duration']);

            $raw = app(GeminiClient::class)->chat(
                [['role' => 'user', 'text' => "Transcript: \"{$this->transcript}\""]],
                systemPrompt: 'You are a supportive English speaking coach. '.ucfirst($this->run->learner->levelDescription())
                    .' just recorded about 2 '
                    .'minutes of solo speaking in English about their daily life — this is low-pressure fluency '
                    .'practice, not a graded test.'.$vocabularyContext.$paceContext.' Given the transcript, write a short, '
                    .'warm, simple reflection in PERSIAN (Farsi) — never English, and never grade it or use '
                    .'severity labels. Reply with ONLY valid JSON, no markdown fences: {"highlight": "...", '
                    .'"tip": "..."} — "highlight" is one short encouraging sentence in Persian about something '
                    .'they did well; "tip" is one short, gentle, actionable suggestion in Persian for next time. '
                    .'Only mention pace or filler words if the numbers given are genuinely notable (very slow, '
                    .'very fast, or many filler words) — otherwise focus the tip on content/language as usual. '
                    .'Keep both simple, plain Persian, no jargon, no English words mixed in unless quoting a '
                    .'specific English word or phrase they actually said.'
            );
            $this->recordGeminiCall();

            $data = json_decode(trim($raw), true);

            if (is_array($data) && isset($data['highlight'], $data['tip'])) {
                $this->reflection = ['highlight' => $data['highlight'], 'tip' => $data['tip']];
            }
        } catch (Throwable) {
            // Silent by design — see method docblock.
        }
    }

    private function paceContext(string $transcript, float $durationSeconds): string
    {
        if ($durationSeconds < 10.0) {
            return '';
        }

        $wordCount = count(array_filter(preg_split('/\s+/', trim($transcript))));
        $wordsPerMinute = (int) round($wordCount / ($durationSeconds / 60));
        $fillerCount = preg_match_all('/\b(um|uh|erm)\b/i', $transcript);

        return " (For your awareness only: the learner spoke at roughly {$wordsPerMinute} words per minute over "
            .round($durationSeconds).' seconds, with '.$fillerCount.' filler words like "um"/"uh".)';
    }

    // ---------------------------------------------------------------
    // Interview round (existing AI Conversation #1 behavior)
    // ---------------------------------------------------------------

    public function submitAnswer(): void
    {
        $this->error = null;
        $this->processing = true;
        $round = $this->round;

        $this->validate([
            'audioFile' => ['required', 'file', 'extensions:webm,ogg,mp3,wav,m4a', 'max:20480'],
        ]);

        try {
            $answer = trim(app(GroqClient::class)->transcribe($this->audioFile->getRealPath()));
            $this->recordGroqCall();
            $this->audioFile = null;

            $check = app(SpokenAnswerChecker::class)->checkRelevance(
                $this->currentQuestion,
                $answer,
                $this->run->learner->levelDescription(),
                $this->run->aiToneGuidance(),
            );
            $this->recordGeminiCall();

            $this->trackCheckAttempt($round, $check['severity']);

            if ($check['severity'] === 'major') {
                $this->offTopicHint[$round] = $check['hint'];

                return;
            }

            unset($this->offTopicHint[$round], $this->exampleAnswer[$round]);

            $followup = trim(app(GeminiClient::class)->chat(
                [['role' => 'user', 'text' => "Interview question: \"{$this->currentQuestion}\"\nLearner's answer: \"{$answer}\""]],
                systemPrompt: 'You are a friendly English conversation partner interviewing '
                    .$this->run->learner->levelDescription().' about their daily '
                    ."life. Given the question you asked and the learner's transcribed spoken answer, reply with exactly "
                    .'ONE short, natural follow-up question (max 15 words) that shows you listened — no preamble, no '
                    .'quotation marks, just the question.'
                    .$this->run->aiToneGuidance()
            ));
            $this->recordGeminiCall();

            $this->turns[] = [
                'question' => $this->currentQuestion,
                'answer' => $answer,
                'followup' => $followup,
            ];

            $this->round++;

            if ($this->round >= count($this->questions)) {
                $this->finishInterview();
            }
        } catch (Throwable $e) {
            $this->error = "Something went wrong talking to the AI Instructor: {$e->getMessage()}";
        } finally {
            $this->processing = false;
        }
    }

    public function revealExample(int $round): void
    {
        try {
            $this->exampleAnswer[$round] = app(SpokenAnswerChecker::class)->suggestExample(
                $this->questions[$round],
                $this->run->learner->levelDescription(),
            );
            $this->recordGeminiCall();
            $this->clearCheckAttempt($round);
        } catch (\Throwable $e) {
            $this->error = "Couldn't get an example: {$e->getMessage()}";
        }
    }

    public function declineExample(int $round): void
    {
        $this->declineCheckReveal($round);
    }

    /**
     * The interview just finished — generate AI Feedback automatically
     * (was a separate step the learner had to press a button on), then
     * save ONE Evidence TEXT row (warm-up + interview + feedback) and
     * ONE AUDIO row (the warm-up recording), same shape merge described
     * at the top of this file.
     */
    private function finishInterview(): void
    {
        $this->generateFeedback();

        // Read BEFORE creating this attempt's own row — a retry (?retry=1)
        // means there can already be an earlier one for this same phase.
        $this->previousCorrect = $this->readPreviousCorrectCount();

        $filledWarmUp = collect($this->sentences)
            ->map(fn ($s, $i) => ['index' => $i, 'text' => trim((string) $s)])
            ->filter(fn ($s) => $s['text'] !== '')
            ->values();
        $sentenceSeverities = $filledWarmUp->map(fn ($s) => $this->feedback[$s['index']]['severity'] ?? 'none')->values();
        $this->correctCount = $sentenceSeverities->filter(fn ($s) => $s === 'none')->count();

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'ai_conversation_1',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode([
                'sentences' => $filledWarmUp->pluck('text')->values(),
                'sentence_severities' => $sentenceSeverities,
                'transcript' => $this->transcript,
                'segments' => $this->segments,
                'reflection' => $this->reflection,
                'turns' => $this->turns,
                'feedback' => [
                    'strength' => $this->fbStrength,
                    'expression' => $this->fbExpression,
                    'correction' => [
                        'original' => $this->fbCorrectionOriginal,
                        'corrected' => $this->fbCorrectionCorrected,
                        'why' => $this->fbCorrectionWhy,
                        'suggestion' => $this->fbCorrectionSuggestion,
                    ],
                    'severity' => $this->fbSeverity,
                ],
            ]),
        ]);

        $audioEvidence = Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'ai_conversation_1',
            'type' => Evidence::TYPE_AUDIO,
            'content_ref' => $this->warmUpAudioUrl,
        ]);

        if ($this->fbStrength) {
            $textEvidence = Evidence::where('mission_run_id', $this->run->id)
                ->where('phase', 'ai_conversation_1')->where('type', Evidence::TYPE_TEXT)->latest()->first();

            AIFeedback::create([
                'evidence_id' => $textEvidence->id,
                'strength' => $this->fbStrength,
                'correction' => json_encode([
                    'original' => $this->fbCorrectionOriginal,
                    'corrected' => $this->fbCorrectionCorrected,
                    'why' => $this->fbCorrectionWhy,
                    'suggestion' => $this->fbCorrectionSuggestion,
                ]),
                'tone' => 'encouraging',
            ]);
        }

        unset($audioEvidence);

        $this->completed = true;
    }

    /**
     * The learner's own last attempt at this exact step's warm-up round
     * (encouragement score's comparison, Epic H) — null when this is
     * their first attempt or an old Evidence row predates severities
     * being persisted at all.
     */
    private function readPreviousCorrectCount(): ?int
    {
        $previous = $this->run->evidence()
            ->where('phase', 'ai_conversation_1')
            ->where('type', Evidence::TYPE_TEXT)
            ->latest()
            ->first();

        if (! $previous) {
            return null;
        }

        $severities = json_decode($previous->content_ref, true)['sentence_severities'] ?? null;

        return $severities ? collect($severities)->filter(fn ($s) => $s === 'none')->count() : null;
    }

    /**
     * Ported from the old AI Feedback #1 step — same prompt, same
     * required-keys contract — but fired automatically the moment the
     * interview ends, not on a manual "get my feedback" click. A failure
     * here never blocks Continue: the learner already finished the real
     * work (the interview itself); a missing report card is a shame, not
     * a reason to trap them on this screen.
     */
    private function generateFeedback(): void
    {
        try {
            $transcript = collect($this->turns)
                ->map(fn ($t) => "Q: {$t['question']}\nA: {$t['answer']}")
                ->implode("\n\n");

            $data = app(AiFeedbackCard::class)->generate(
                [['role' => 'user', 'text' => $transcript]],
                systemPrompt: 'You are an encouraging English teacher reviewing the spoken interview answers of '
                    .$this->run->learner->levelDescription().'. '
                    .'Reply with ONLY valid JSON, no markdown fences, no extra text, in exactly this shape: '
                    .'{"strength": "one full sentence, in PERSIAN (Farsi), about one specific thing they did well", '
                    .'"expression": "one full sentence, in PERSIAN (Farsi), pointing out one good English word or '
                    .'phrase they actually used — you can quote the English word/phrase itself inside the Persian '
                    .'sentence", '
                    .'"correction": {'
                    .'"original": "the learner\'s own flawed sentence, quoted exactly as they said it, in ENGLISH", '
                    .'"corrected": "the corrected version of that same sentence, in ENGLISH", '
                    .'"why": "one short sentence, in PERSIAN (Farsi), explaining the underlying grammar or '
                    .'vocabulary rule behind the mistake", '
                    .'"suggestion": "one short, concrete, actionable next step, in PERSIAN (Farsi), e.g. what to '
                    .'practice to strengthen this"'
                    .'}, '
                    .'"severity": "minor or major — how serious this grammar/vocabulary issue is"}. '
                    .'Only the "original" and "corrected" fields are in English (quoting the learner\'s own words); '
                    .'every other field must be written in plain Persian, no English words mixed in unless quoting '
                    .'a specific English word or phrase.',
                requiredKeys: ['strength', 'expression', 'severity', 'correction.original', 'correction.corrected', 'correction.why', 'correction.suggestion'],
                onCallSucceeded: fn () => $this->recordGeminiCall(),
            );

            $this->applyFeedbackData($data);

            if ($this->fbSeverity === 'major') {
                $this->run->recordStruggleSignal();
            }
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
     * attributes exactly — both build it the same way from the run id.
     */
    public function draftPrefix(): string
    {
        return "eos-draft:{$this->run->id}:ai_conversation_1:";
    }
};
?>

@php
    $conversation = $run->mission->stepContent('ai_conversation_1');
    $vocabularyWords = $run->selectedVocabularyWords();
    $initialFilled = collect($sentences)->map(fn ($s) => trim((string) $s) !== '')->values();
    $draftPrefix = $this->draftPrefix();
    $warmUpQuestions = $run->mission->stepContent('mission_brief')['warm_up_questions'] ?? [];
@endphp

{{-- Server-rendered values stay OUT of the x-data expression, same reason
     as every other multi-part step — see ⚡listening.blade.php's note. --}}
<div class="space-y-6" data-initial-filled="{{ $initialFilled->toJson() }}" x-data="{
    filled: [],
    dismissed: {},
    noChipsChallenge: false,
    get filledCount() { return this.filled.filter(Boolean).length },
    init() { this.filled = JSON.parse(this.$el.dataset.initialFilled) },
}">
    <x-hook :text="$conversation['hook'] ?? null" />

    @if ($completed)
        <div class="space-y-4 rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
            <div>
                <p class="inline-flex items-center gap-1 text-xs font-semibold tracking-wide text-success uppercase dark:text-success-dark">
                    @svg('heroicon-o-check-circle', 'h-4 w-4')
                    Talk It Out complete
                </p>
                <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">Nicely done — take a look back below before you move on.</p>
            </div>

            @if (! is_null($correctCount))
                <x-encouragement-score :correct="$correctCount" :total="5" label="warm-up sentences" :previous-correct="$previousCorrect" />
            @endif

            @if ($warmUpAudioUrl)
                <div>
                    <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Your warm-up recording</p>
                    <div class="mt-1"><x-audio-player :url="$warmUpAudioUrl" /></div>
                </div>
            @endif

            @if ($reflection)
                <div class="space-y-2 rounded-2xl border border-line bg-surface-sunken p-3 dark:border-line-dark dark:bg-surface-sunken-dark" dir="rtl">
                    <p class="text-sm text-ink dark:text-ink-dark">{{ $reflection['highlight'] }}</p>
                    <p class="flex items-start gap-1.5 text-sm text-ink-soft dark:text-ink-soft-dark">
                        @svg('heroicon-o-light-bulb', 'h-4 w-4 shrink-0 mt-0.5')
                        {{ $reflection['tip'] }}
                    </p>
                </div>
            @endif

            @if ($transcript)
                <div>
                    <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">What you said</p>
                    <x-confidence-transcript :segments="$segments" :fallback="$transcript" class="mt-1" />
                </div>
            @endif

            @if (count($turns))
                <div>
                    <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">The conversation</p>
                    <div class="mt-2 space-y-3">
                        @foreach ($turns as $turn)
                            <x-conversation-turn :prompt="$turn['question']" :answer="$turn['answer']" :followup="$turn['followup']" />
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($fbStrength)
                <div class="space-y-3">
                    <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Feedback on your conversation</p>

                    <div class="rounded-xl border-l-4 border-success bg-success/5 p-3 dark:border-success-dark dark:bg-success-dark/10">
                        <p class="flex items-center gap-1.5 text-xs font-semibold text-success uppercase dark:text-success-dark">
                            @svg('heroicon-o-check-circle', 'h-4 w-4')
                            One thing you did well
                        </p>
                        <p class="font-fa mt-1 text-sm text-ink dark:text-ink-dark" dir="rtl">{{ $fbStrength }}</p>
                    </div>

                    <div class="rounded-xl border-l-4 border-accent bg-accent/5 p-3 dark:border-accent-dark dark:bg-accent-dark/10">
                        <p class="flex items-center gap-1.5 text-xs font-semibold text-accent uppercase dark:text-accent-dark">
                            @svg('heroicon-o-book-open', 'h-4 w-4')
                            A good expression you used
                        </p>
                        <p class="font-fa mt-1 text-sm text-ink dark:text-ink-dark" dir="rtl">{{ $fbExpression }}</p>
                    </div>

                    @if (collect([$fbCorrectionOriginal, $fbCorrectionCorrected, $fbCorrectionWhy, $fbCorrectionSuggestion])->filter(fn ($v) => filled($v))->isNotEmpty())
                        <div class="rounded-xl border-l-4 {{ $fbSeverity === 'major' ? 'border-red-500 bg-red-50 dark:border-red-800 dark:bg-red-950/30' : 'border-amber-500 bg-amber-50 dark:border-amber-800 dark:bg-amber-950/30' }} p-3">
                            <p class="flex items-center gap-1.5 text-xs font-semibold {{ $fbSeverity === 'major' ? 'text-red-600 dark:text-red-400' : 'text-amber-600 dark:text-amber-400' }} uppercase">
                                @svg('heroicon-o-exclamation-triangle', 'h-4 w-4')
                                Something to fix
                            </p>
                            <p class="mt-2 text-sm text-red-600 line-through decoration-red-500">{{ $fbCorrectionOriginal }}</p>
                            <p class="mt-1 text-sm text-success dark:text-success-dark">{{ $fbCorrectionCorrected }}</p>
                            <p class="font-fa mt-2 text-sm text-ink dark:text-ink-dark" dir="rtl">{{ $fbCorrectionWhy }}</p>
                            <p class="font-fa mt-1 flex items-start gap-1.5 text-sm text-ink-soft dark:text-ink-soft-dark" dir="rtl">
                                @svg('heroicon-o-arrow-trending-up', 'h-4 w-4 shrink-0 mt-0.5')
                                {{ $fbCorrectionSuggestion }}
                            </p>
                        </div>
                    @endif
                </div>
            @endif

            @if (count($this->questions))
                <div>
                    <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Want more practice? Ask each other all of these questions for real.</p>
                    <div class="mt-1.5">
                        <x-practice-session-with-friend :mission="$run->mission" step-key="ai_conversation_1" />
                    </div>
                </div>
            @endif

            @unless ($readOnly)
                <button
                    wire:click="proceed"
                    wire:loading.attr="disabled"
                    wire:target="proceed"
                    class="cursor-pointer rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 dark:bg-accent-dark"
                >
                    <span wire:loading.remove wire:target="proceed">Continue</span>
                    <span wire:loading wire:target="proceed">Saving…</span>
                </button>
            @endunless
        </div>
    @elseif (! $warmUpDone)
        {{-- Warm-up round: was the standalone Activation step. --}}
        <div>
            <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Warm-up — write 5 personal sentences</p>
            <p class="text-xs text-ink-soft dark:text-ink-soft-dark">{{ $conversation['warm_up_task'] ?? '' }}</p>
            @if ($vocabularyWords)
                @if ($this->run->mission->scaffoldLevel() === App\Models\Mission::SCAFFOLD_MINIMAL)
                    <div class="mt-2">
                        <x-optional-challenge
                            model="noChipsChallenge"
                            label="Want to try this one without the word chips?"
                        />
                    </div>
                @endif
                <div class="mt-2" x-show="! noChipsChallenge">
                    <p class="text-xs text-ink-soft dark:text-ink-soft-dark">Tap a word to drop it into your next sentence:</p>
                    <div class="mt-1">
                        <x-vocabulary-chips
                            :words="$vocabularyWords"
                            :collapsed="$this->run->mission->scaffoldLevel() !== App\Models\Mission::SCAFFOLD_FULL"
                            field="sentences"
                            on-insert="filled[idx] = true; dismissed[idx] = true;"
                        />
                    </div>
                </div>
            @endif

            <div class="mt-2">
                <x-progress-bar>
                    <div
                        class="h-full rounded-full transition-all duration-300"
                        :class="filledCount >= 5 ? 'bg-success dark:bg-success-dark' : 'bg-accent dark:bg-accent-dark'"
                        :style="`width: ${Math.min(filledCount, 5) / 5 * 100}%`"
                    ></div>
                    <x-slot:label>
                        <p
                            class="text-xs font-semibold transition-colors"
                            :class="filledCount >= 5 ? 'text-success dark:text-success-dark' : 'text-ink-soft dark:text-ink-soft-dark'"
                            x-text="`${Math.min(filledCount, 5)} of 5 written`"
                        ></p>
                    </x-slot:label>
                </x-progress-bar>
            </div>

            <div wire:loading.class="pointer-events-none" wire:target="checkOne,revealCorrection,declineReveal,finishWarmUp" class="mt-2 space-y-3">
                @foreach ($sentences as $index => $sentence)
                    @php $itemFeedback = $feedback[$index] ?? null; @endphp
                    <div class="rounded-xl border border-line p-3 dark:border-line-dark">
                        <div class="flex items-center gap-2">
                            <input
                                type="text"
                                wire:model="sentences.{{ $index }}"
                                placeholder="{{ $index + 1 }}."
                                x-on:input="filled[{{ $index }}] = $el.value.trim() !== ''; dismissed[{{ $index }}] = true"
                                x-draft="{ key: '{{ $draftPrefix }}sentences.{{ $index }}', field: 'sentences.{{ $index }}' }"
                                wire:loading.attr="disabled"
                                wire:target="checkOne,revealCorrection,declineReveal,finishWarmUp"
                                class="w-full rounded-lg border border-line bg-transparent px-2 py-1 text-sm text-ink disabled:opacity-50 dark:border-line-dark dark:text-ink-dark"
                            >
                            <x-filled-check show="filled[{{ $index }}]" />
                            <x-check-button method="checkOne" :index="$index" wire-target="checkOne,revealCorrection,declineReveal,finishWarmUp" />
                        </div>

                        <x-ai-thinking wire:loading wire:target="checkOne({{ $index }}), revealCorrection({{ $index }}), finishWarmUp" class="mt-2" />

                        <div x-show="!dismissed[{{ $index }}]" x-transition.opacity.duration.300ms>
                            <x-severity-feedback :feedback="$itemFeedback" :error="$checkErrors[$index] ?? null" />
                        </div>

                        <x-almost-reveal-notice :show="$this->isAlmostRevealing('warmup_'.$index)" />
                        <x-reveal-offer
                            :show="$offerReveal['warmup_'.$index] ?? false"
                            :struggling="$this->run->isStruggling()"
                            reveal-method="revealCorrection"
                            decline-method="declineReveal"
                            :index="$index"
                            wire-target="checkOne,revealCorrection,declineReveal,finishWarmUp"
                        />
                    </div>
                @endforeach
            </div>
            @error('sentences')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Solo speaking — 2 minutes</p>
            <p class="text-xs text-ink-soft dark:text-ink-soft-dark">Talk about your daily life without reading. Record when you're ready.</p>

            @if (count($warmUpQuestions))
                <div class="mt-3 rounded-2xl border border-line bg-surface-sunken p-4 dark:border-line-dark dark:bg-surface-sunken-dark">
                    <p class="text-xs font-semibold text-ink dark:text-ink-dark">Same questions as Day 1 — how does it feel now?</p>
                    <ul class="mt-2 space-y-1.5">
                        @foreach ($warmUpQuestions as $question)
                            <li class="text-sm text-ink-soft dark:text-ink-soft-dark">{{ $question }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="mt-3">
                <x-voice-recorder field="warmUpAudioFile" :file="$warmUpAudioFile" file-name="warmup-speaking.webm" />
            </div>

            @error('warmUpAudioFile')
                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div class="mt-4">
            <x-continue-button
                on-click="filled.forEach((_, i) => dismissed[i] = true); $wire.finishWarmUp().then(() => { dismissed = {} })"
                wire-target="checkOne,revealCorrection,declineReveal,finishWarmUp"
                loading-label="Checking your sentences and preparing your recap…"
                ready-when="{{ $warmUpAudioFile ? 'filledCount >= 5' : 'false' }}"
                hint="{{ $warmUpAudioFile ? 'Write 5 sentences to continue' : 'Record your answer, then write 5 sentences' }}"
            />
        </div>
    @else
        {{-- Interview round: unchanged AI Conversation #1 behavior. --}}
        <div>
            <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Talk It Out</p>
            <p class="text-xs text-ink-soft dark:text-ink-soft-dark">Answer each question out loud — tap "Read aloud" if you'd rather hear it than read it. It'll ask one follow-up after each answer.</p>
        </div>

        <x-vocabulary-pills :words="$vocabularyWords" label="Words you picked — try to use some when you answer" />

        <div>
            <x-progress-bar>
                <div
                    class="h-full rounded-full bg-accent transition-all duration-300 dark:bg-accent-dark"
                    style="width: {{ count($this->questions) ? $round / count($this->questions) * 100 : 0 }}%"
                ></div>
                <x-slot:label>
                    <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">
                        Question {{ min($round + 1, count($this->questions)) }} of {{ count($this->questions) }}
                    </p>
                </x-slot:label>
            </x-progress-bar>
        </div>

        @if (count($turns))
            <div class="space-y-3">
                @foreach ($turns as $turn)
                    <x-conversation-turn :prompt="$turn['question']" :answer="$turn['answer']" :followup="$turn['followup']" />
                @endforeach
            </div>
        @endif

        @if ($this->currentQuestion)
            <div class="rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
                <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Question {{ $round + 1 }} of {{ count($this->questions) }}</p>
                <div class="mt-1 flex items-start justify-between gap-2">
                    <p class="font-display text-lg font-bold text-ink dark:text-ink-dark">{{ $this->currentQuestion }}</p>
                    <x-speak-button :text="$this->currentQuestion" />
                </div>

                <div class="mt-2">
                    <x-practice-with-friend :text="$this->currentQuestion" />
                </div>

                <div class="mt-3" wire:key="recorder-{{ $round }}" wire:loading.remove wire:target="submitAnswer">
                    <x-voice-recorder
                        field="audioFile"
                        :file="$audioFile"
                        on-recorded="submitAnswer"
                        file-name="answer.webm"
                    />
                </div>

                <x-ai-thinking wire:loading wire:target="submitAnswer" label="Transcribing and thinking of a follow-up…" class="mt-3" />

                @if ($exampleAnswer[$round] ?? null)
                    <div class="mt-2 rounded-xl border border-accent-soft bg-accent-soft/60 px-3 py-2 dark:border-accent-soft-dark dark:bg-accent-soft-dark/60">
                        <p class="text-xs font-semibold text-accent-ink uppercase dark:text-accent-ink-dark">Something like this…</p>
                        <p class="mt-1 text-sm text-ink dark:text-ink-dark">{{ $exampleAnswer[$round] }}</p>
                    </div>
                @elseif ($offTopicHint[$round] ?? null)
                    <x-severity-feedback :feedback="['severity' => 'major', 'hint' => $offTopicHint[$round]]" />
                @endif

                <x-almost-reveal-notice
                    :show="($checkAttempts[$round] ?? 0) === 2"
                    label="One more try — after that I can suggest an example to help you get started."
                />
                <x-reveal-offer
                    :show="$offerReveal[$round] ?? false"
                    :struggling="$this->run->isStruggling()"
                    reveal-method="revealExample"
                    decline-method="declineExample"
                    :index="$round"
                    wire-target="submitAnswer,revealExample,declineExample"
                    label="Want an example to help you get started?"
                />

                @error('audioFile')
                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
                @if ($error)
                    <p class="mt-2 text-sm text-red-600">{{ $error }}</p>
                @endif
            </div>
        @endif
    @endif
</div>
