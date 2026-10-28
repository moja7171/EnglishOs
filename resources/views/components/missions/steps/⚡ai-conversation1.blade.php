<?php

use App\Livewire\Concerns\KeepsStepDraft;
use App\Livewire\Concerns\PreviewsStep;
use App\Livewire\Concerns\TracksAiUsage;
use App\Livewire\Concerns\TracksCheckAttempts;
use App\Models\AIFeedback;
use App\Models\Evidence;
use App\Models\MissionRun;
use App\Services\AiFailure;
use App\Services\LearnerFeedback;
use App\Services\GeminiClient;
use App\Services\GroqClient;
use App\Services\SpokenAnswerChecker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Locked;
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
    use KeepsStepDraft;
    use PreviewsStep;
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

    public ?UploadedFile $warmUpAudioFile = null;

    /** Stored to disk the moment the upload finishes — see updatedWarmUpAudioFile(). */
    public ?string $warmUpAudioUrl = null;

    /** The same recording's path on the public disk, for transcribing it. */
    #[Locked]
    public ?string $warmUpAudioPath = null;

    /** Groq transcript of the warm-up recording — see Activation's old transcribeAndReflect() docblock. */
    public ?string $transcript = null;

    /** @var list<array{text: string, confidence: string}> */
    public array $segments = [];

    /** @var array{highlight: string, tip: string}|null */
    public ?array $reflection = null;

    // --- Interview round (existing AI Conversation #1) ---

    public int $round = 0;

    /** @var array<int, array{question: string, answer: string, check?: array{status: string, original: ?string, corrected: ?string, why: string}|null, followup?: string}> 'check' since the follow-up question was dropped; older turns have 'followup'. */
    public array $turns = [];

    public ?UploadedFile $audioFile = null;

    public bool $processing = false;

    public ?string $error = null;

    /** Shown when the AI could not check an answer: the learner still moves on, unevaluated. */
    public ?string $notice = null;

    /** @var array<int, string> keyed by round — set when the last spoken attempt was off-topic/empty */
    public array $offTopicHint = [];

    /** @var array<int, string> keyed by round — an example answer, shown only after 3 off-topic attempts */
    public array $exampleAnswer = [];

    // --- Feedback (was AI Feedback #1) — generated automatically, not on request ---

    /** @var array<string, mixed>|null A LearnerFeedback::review() result (or an older saved shape). */
    public ?array $feedback = null;

    /**
     * True once every question has been answered, feedback has been
     * generated, and Evidence is saved — the step then shows the full
     * recap (warm-up reflection + conversation + feedback report card)
     * before the learner dismisses it with proceed() below.
     */
    public bool $completed = false;

    public function mount(): void
    {
        if (! $this->readOnly) {
            $this->restoreStepDraft();

            return;
        }

        // A preview shows the step as it opens (nothing recorded yet), not
        // as a completed recap — the view makes it inert.
        if ($this->preview) {
            return;
        }

        // The step saves a text row and an audio row in the same second, so
        // "latest" alone can pick the recording; the transcript is the text one.
        $textEvidence = $this->run->evidence()->where('phase', 'ai_conversation_1')->where('type', Evidence::TYPE_TEXT)->latest()->first();
        $data = json_decode($textEvidence?->content_ref ?? '{}', true);

        $this->transcript = $data['transcript'] ?? null;
        $this->segments = $data['segments'] ?? [];
        $this->reflection = $data['reflection'] ?? null;
        $this->turns = $data['turns'] ?? [];
        $this->round = count($this->turns);
        $this->warmUpDone = true;
        $this->completed = true;

        $this->feedback = ($data['feedback'] ?? null) ?: null;

        $audioEvidence = $this->run->evidence()->where('phase', 'ai_conversation_1')->where('type', Evidence::TYPE_AUDIO)->latest()->first();
        $this->warmUpAudioUrl = $audioEvidence?->content_ref;
    }

    public function getQuestionsProperty(): array
    {
        return $this->run->mission->stepContent('ai_conversation_1')['interview_questions'] ?? [];
    }

    protected function draftPhase(): string
    {
        return 'ai_conversation_1';
    }

    protected function draftedProperties(): array
    {
        return ['warmUpDone', 'warmUpAudioUrl', 'warmUpAudioPath', 'transcript', 'segments', 'reflection', 'round', 'turns'];
    }

    public function getCurrentQuestionProperty(): ?string
    {
        return $this->questions[$this->round] ?? null;
    }

    // ---------------------------------------------------------------
    // Warm-up round (was Activation)
    // ---------------------------------------------------------------

    /**
     * The warm-up recording is stored to permanent disk storage (and the
     * step's draft) the moment its upload finishes — a 2-minute take must
     * survive the learner leaving before they press Continue.
     */
    public function updatedWarmUpAudioFile(): void
    {
        $this->validate([
            'warmUpAudioFile' => ['required', 'file', 'extensions:webm,ogg,mp3,wav,m4a', 'max:20480'],
        ]);

        $this->warmUpAudioPath = $this->keepDraftRecording($this->warmUpAudioFile, $this->warmUpAudioPath);
        $this->warmUpAudioUrl = Storage::disk('public')->url($this->warmUpAudioPath);
        $this->warmUpAudioFile = null;

        $this->keepStepDraft();
    }

    /**
     * Ends the warm-up round: transcribes the kept recording and asks for
     * a Persian reflection — all BEFORE any Evidence exists, since this
     * step isn't done yet. Never creates Evidence itself; see
     * finishInterview().
     */
    public function finishWarmUp(): void
    {
        if ($this->warmUpAudioPath === null) {
            $this->addError('warmUpAudioFile', 'Record your warm-up first.');

            return;
        }

        $this->transcribeAndReflect();

        $this->dispatch('clear-draft', prefix: $this->draftPrefix());
        $this->warmUpDone = true;

        $this->keepStepDraft();
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
            $result = app(GroqClient::class)->transcribeWithConfidence(Storage::disk('public')->path($this->warmUpAudioPath));
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
                    .'specific English word or phrase they actually said. Always write every English word or '
                    .'phrase in Latin letters inside «», e.g. «study» — never spell an English word in Persian letters.',
                profile: GeminiClient::PROFILE_JUDGE,
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
        $this->notice = null;
        $this->processing = true;
        $round = $this->round;

        $this->validate([
            'audioFile' => ['required', 'file', 'extensions:webm,ogg,mp3,wav,m4a', 'max:20480'],
        ]);

        try {
            $answer = trim(app(GroqClient::class)->transcribe($this->audioFile->getRealPath()));
            $this->recordGroqCall();
            $this->audioFile = null;

            // Once every judge model has failed (GeminiClient has already
            // logged why), the answer is accepted unevaluated — the learner
            // must never be stuck on a round because the AI is down.
            $check = null;

            try {
                $check = app(SpokenAnswerChecker::class)->checkRelevance(
                    $this->currentQuestion,
                    $answer,
                    $this->run->learner->levelDescription(),
                    $this->run->aiToneGuidance(),
                );
                $this->recordGeminiCall();
            } catch (Throwable) {
                $this->notice = "Your answer was saved, but we couldn't check it just now — keep going.";
            }

            if ($check !== null) {
                $this->trackCheckAttempt($round, $check['severity']);

                if ($check['severity'] === 'major') {
                    $this->offTopicHint[$round] = $check['hint'];

                    return;
                }
            }

            unset($this->offTopicHint[$round], $this->exampleAnswer[$round]);

            // One line under the answer — a fix or a few words of praise —
            // in place of the old follow-up question nobody answered.
            try {
                $check = app(SpokenAnswerChecker::class)->quickCorrection(
                    $this->currentQuestion,
                    $answer,
                    $this->run->learner->levelDescription(),
                );
                $this->recordGeminiCall();
            } catch (Throwable) {
                $check = null;
            }

            $this->turns[] = [
                'question' => $this->currentQuestion,
                'answer' => $answer,
                'check' => $check,
            ];

            $this->round++;

            // Read above for this round, and Livewire keeps a computed
            // value for the whole request — without this the page would
            // still show the question just answered.
            unset($this->currentQuestion);

            if ($this->round >= count($this->questions)) {
                $this->finishInterview();
            } else {
                $this->keepStepDraft();
            }
        } catch (Throwable $e) {
            $this->error = AiFailure::message($e, "Something went wrong talking to the AI Instructor");
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
            $this->error = AiFailure::message($e, "Couldn't get an example");
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

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'ai_conversation_1',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode([
                'transcript' => $this->transcript,
                'segments' => $this->segments,
                'reflection' => $this->reflection,
                'turns' => $this->turns,
                'feedback' => $this->feedback,
            ]),
        ]);

        $audioEvidence = Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'ai_conversation_1',
            'type' => Evidence::TYPE_AUDIO,
            'content_ref' => $this->warmUpAudioUrl,
        ]);

        if ($this->feedback) {
            $textEvidence = Evidence::where('mission_run_id', $this->run->id)
                ->where('phase', 'ai_conversation_1')->where('type', Evidence::TYPE_TEXT)->latest()->first();
            $firstFix = collect($this->feedback['sentences'])->firstWhere('status', 'fix');

            AIFeedback::create([
                'evidence_id' => $textEvidence->id,
                'strength' => $this->feedback['positives'][0] ?? $this->feedback['summary'],
                'correction' => json_encode([
                    'original' => $firstFix['original'] ?? null,
                    'corrected' => $firstFix['corrected'] ?? null,
                    'why' => $firstFix['why'] ?? null,
                    'suggestion' => $this->feedback['negatives'][0] ?? null,
                ]),
                'tone' => 'encouraging',
            ]);
        }

        unset($audioEvidence);

        $this->forgetStepDraft();
        $this->completed = true;
    }

    /**
     * Ported from the old AI Feedback #1 step, now the shared
     * sentence-by-sentence feedback (see LearnerFeedback) — fired automatically the moment the
     * interview ends, not on a manual "get my feedback" click. A failure
     * here never blocks Continue: the learner already finished the real
     * work (the interview itself); a missing report card is a shame, not
     * a reason to trap them on this screen.
     */
    private function generateFeedback(): void
    {
        try {
            $this->feedback = app(LearnerFeedback::class)->review(
                $this->run->learner,
                '',
                situation: 'a short speaking task: a free warm-up talk, then a spoken interview. Each Question is '
                    .'the interviewer; judge ONLY the learner\'s lines.',
                source: 'ai_conversation_1',
                spoken: true,
                onCallSucceeded: fn () => $this->recordGeminiCall(),
                answers: $this->answers(),
            );

            if ($this->feedback['severity'] === 'major') {
                $this->run->recordStruggleSignal();
            }
        } catch (Throwable) {
            // Silent by design — see method docblock.
        }
    }

    /**
     * The warm-up talk and each interview answer, in order — what the
     * feedback is given on and what the recap shows one card for.
     *
     * @return list<array{question: ?string, text: string}>
     */
    public function answers(): array
    {
        $warmUp = filled($this->transcript) ? [['question' => 'Warm-up', 'text' => $this->transcript]] : [];

        return array_merge($warmUp, array_map(
            fn (array $turn) => ['question' => $turn['question'], 'text' => $turn['answer']],
            array_values(array_filter($this->turns, fn (array $turn) => filled($turn['answer'] ?? null))),
        ));
    }

    public function proceed(): void
    {
        $this->redirect($this->run->urlAfterSavingAStep(), navigate: true);
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
    $warmUpQuestions = $run->mission->stepContent('mission_brief')['warm_up_questions'] ?? [];
    $questionCount = count($this->questions);
    $startScreen = $completed ? 3 : ($warmUpDone ? 2 : ($warmUpAudioUrl ? 1 : 0));
@endphp

@if ($readOnly)
    <div class="flex flex-col gap-4 pt-2" @if ($preview) inert @endif>
        <x-step-intro kind="Talk It Out" :title="$conversation['hook'] ?? null" />
        @if ($completed)
            <x-learner-feedback :feedback="$feedback" :answers="$this->answers()">
                @if ($warmUpAudioUrl)
                    <x-slot:more>
                        <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Your warm-up recording</p>
                        <x-audio-player :url="$warmUpAudioUrl" />
                    </x-slot:more>
                @endif
            </x-learner-feedback>
        @endif
    </div>
@else
{{-- Lesson design 8: the start, a short solo warm-up (the four Day 1
     questions, one take), the interview as a real chat (the question read
     out, the answer as a bubble with its one-line check, the mic bottom
     centre, 💡 Idea for an example answer), then the shared feedback. --}}
<div
    x-data="{ screen: {{ $startScreen }}, idea: false }"
    x-effect="$dispatch('step-progress', { share: screen === 2 ? (2 + {{ $questionCount ? $round / $questionCount : 0 }}) / 4 : (screen + 0.5) / 4 })"
    x-back-close="{ open: screen === 1, close: () => screen-- }"
>
    <x-lesson-screen when="screen === 0">
        <x-step-intro kind="Talk It Out" :minutes="$run->mission->stepDuration('ai_conversation_1')" :title="$conversation['hook'] ?? null" />

        <x-slot:actions>
            <x-continue-button on-click="screen = 1" wire-target="finishWarmUp">Start</x-continue-button>
        </x-slot:actions>
    </x-lesson-screen>

    <x-lesson-screen when="screen === 1">
        <span class="inline-flex w-fit rounded-full bg-accent-soft px-3 py-1 text-xs font-extrabold tracking-wide text-accent-ink uppercase dark:bg-accent-soft-dark dark:text-accent-ink-dark">Talk alone · 2 min</span>
        <div class="flex flex-col gap-2">
            @foreach ($warmUpQuestions as $question)
                <p class="rounded-2xl bg-surface px-4 py-2.5 text-sm font-semibold text-ink dark:bg-surface-dark dark:text-ink-dark">{{ $question }}</p>
            @endforeach
        </div>
        <div class="flex justify-center">
            <x-voice-recorder big field="warmUpAudioFile" :file="$warmUpAudioUrl" file-name="warmup-speaking.webm" />
        </div>
        @error('warmUpAudioFile')
            <p class="text-sm text-danger-ink">{{ $message }}</p>
        @enderror

        <x-slot:actions>
            <x-continue-button
                on-click="$wire.finishWarmUp().then(() => { if ($wire.warmUpDone) screen = 2 })"
                wire-target="finishWarmUp"
                loading-label="Getting ready…"
                ready-when="{{ $warmUpAudioUrl ? 'true' : 'false' }}"
            />
        </x-slot:actions>
    </x-lesson-screen>

    <section x-show="screen === 2" x-cloak class="animate-lesson-screen-in flex min-h-[calc(100dvh-11rem)] flex-col pt-2">
        <div class="flex flex-1 flex-col justify-end gap-4">
            @foreach ($turns as $turn)
                <x-conversation-turn chat :prompt="$turn['question']" :answer="$turn['answer']" :check="$turn['check'] ?? null" />
            @endforeach

            @if ($this->currentQuestion)
                <div
                    wire:key="question-{{ $round }}"
                    x-effect="if (screen === 2) $nextTick(() => window.eosVoice?.speak(@js($this->currentQuestion)))"
                    class="flex max-w-[85%] items-start gap-1.5 self-start rounded-2xl rounded-bl-md bg-surface px-3.5 py-2.5 text-base font-semibold text-ink dark:bg-surface-dark dark:text-ink-dark"
                >
                    <x-speak-word :word="$this->currentQuestion" top />
                </div>

                @if ($offTopicHint[$round] ?? null)
                    <p class="max-w-[85%] self-end rounded-2xl bg-accent-soft px-3.5 py-2 text-sm text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">{{ $offTopicHint[$round] }}</p>
                @endif
                <x-almost-reveal-notice
                    :show="($checkAttempts[$round] ?? 0) === 2"
                    label="One more try — after that I can suggest an example to help you get started."
                />
            @endif

            <x-ai-thinking wire:loading wire:target="submitAnswer" label="Listening to your answer…" />
            @error('audioFile')
                <p class="text-sm text-danger-ink">{{ $message }}</p>
            @enderror
            @if ($notice)
                <p class="text-sm text-ink-soft dark:text-ink-soft-dark">{{ $notice }}</p>
            @endif
            @if ($error)
                <p class="text-sm text-danger-ink">{{ $error }}</p>
            @endif
        </div>

        @if ($this->currentQuestion)
            <div class="sticky bottom-0 mt-4 flex items-center justify-between gap-3 bg-ground py-3 dark:bg-ground-dark">
                <button
                    type="button"
                    x-on:click="idea = true; @if (! ($exampleAnswer[$round] ?? null)) $wire.revealExample({{ $round }}) @endif"
                    class="min-w-16 cursor-pointer rounded-full bg-surface px-3 py-1.5 text-xs font-extrabold text-ink-soft dark:bg-surface-dark dark:text-ink-soft-dark"
                >💡 Idea</button>
                <div wire:key="recorder-{{ $round }}" wire:loading.remove wire:target="submitAnswer" class="flex flex-1 justify-center">
                    <x-voice-recorder big field="audioFile" :file="$audioFile" on-recorded="submitAnswer" file-name="answer.webm" />
                </div>
                <span class="min-w-16 rounded-full bg-surface px-3 py-1.5 text-center text-xs font-extrabold text-ink-soft tabular-nums dark:bg-surface-dark dark:text-ink-soft-dark">{{ min($round + 1, $questionCount) }} / {{ $questionCount }}</span>
            </div>

            <x-bottom-sheet show="idea" label="An example answer">
                <x-slot:title>💡 Something like this</x-slot:title>
                <x-ai-thinking wire:loading wire:target="revealExample" label="Thinking of an example…" />
                @if ($exampleAnswer[$round] ?? null)
                    <p class="text-base leading-relaxed text-ink dark:text-ink-dark">{{ $exampleAnswer[$round] }}</p>
                @endif
            </x-bottom-sheet>
        @endif
    </section>

    <x-feedback-screen when="screen === 3" :feedback="$feedback" :answers="$this->answers()" :waiting="! $completed">
        @if ($warmUpAudioUrl)
            <x-slot:said>
                <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Your warm-up recording</p>
                <x-audio-player :url="$warmUpAudioUrl" />
            </x-slot:said>
        @endif

        <x-slot:actions>
            <x-continue-button on-click="$wire.proceed()" wire-target="proceed" />
        </x-slot:actions>
    </x-feedback-screen>

    {{-- The last answer finishes the interview: move to the feedback. --}}
    @if ($completed)
        <span x-init="screen = 3" class="hidden"></span>
    @endif
</div>
@endif
