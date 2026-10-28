<?php

use App\Livewire\Concerns\KeepsStepDraft;
use App\Livewire\Concerns\PreviewsStep;
use App\Livewire\Concerns\TracksAiUsage;
use App\Livewire\Concerns\TracksCheckAttempts;
use App\Models\Evidence;
use App\Models\MissionRun;
use App\Services\AiFailure;
use App\Services\GeminiClient;
use App\Services\GroqClient;
use App\Services\LearnerFeedback;
use App\Services\SpokenAnswerChecker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Lesson design 13: the start, then the Q&A rounds and the role reversal
 * ("Your turn — ask me") as ONE chat, then the 3-minute challenge (a
 * timer ring, the goals ticked once it is checked, an optional Try again),
 * then the shared feedback screen.
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

    public int $roundIndex = 0;

    /** @var array<int, array{prompt: string, answer: string, followup: string}> */
    public array $turns = [];

    /**
     * The role-reversal round (Epic E) — the learner asks the AI a
     * question about the mission topic instead of answering one, right
     * after the regular Q&A rounds and before the Final Challenge.
     */
    public bool $roleReversalDone = false;

    public ?string $learnerQuestion = null;

    public ?string $aiAnswerToLearner = null;

    public ?string $finalTranscript = null;

    /** @var array<string, bool>|null */
    public ?array $checklist = null;

    public ?string $checklistNote = null;

    /**
     * The raw, undecoded Gemini response behind $checklist — stored
     * alongside it in Evidence purely so a mis-graded requirement (a real
     * false negative was observed on "1+ BBC expression" in a live
     * 2026-09-03 run) can be audited later without being able to
     * reproduce the exact same non-deterministic call.
     */
    public ?string $checklistRawResponse = null;

    public ?UploadedFile $audioFile = null;

    /** The 3-minute challenge's take on the public disk — Mission Result plays it next to Day 1's. */
    #[Locked]
    public ?string $finalAudioPath = null;

    /** @var array<string, mixed>|null A LearnerFeedback::review() result. */
    public ?array $feedback = null;

    public bool $completed = false;

    public bool $processing = false;

    public ?string $error = null;

    /** Shown when the AI could not check an answer: the learner still moves on, unevaluated. */
    public ?string $notice = null;

    /** @var array<int, string> keyed by round — set when the last spoken attempt was off-topic/empty. Final Challenge uses key "final". */
    public array $offTopicHint = [];

    /** @var array<int, string> keyed the same way — an example answer, shown only after 3 off-topic attempts */
    public array $exampleAnswer = [];

    public function mount(): void
    {
        if (! $this->readOnly) {
            $this->restoreStepDraft();

            return;
        }

        $data = json_decode($this->run->evidence()->where('phase', 'ai_conversation_2')->where('type', Evidence::TYPE_TRANSCRIPT)->latest()->first()?->content_ref ?? '{}', true);
        $this->turns = $data['rounds'] ?? [];
        $this->roundIndex = count($this->rounds);
        $this->roleReversalDone = true;
        $this->learnerQuestion = $data['role_reversal']['question'] ?? null;
        $this->aiAnswerToLearner = $data['role_reversal']['answer'] ?? null;
        $this->finalTranscript = $data['final_transcript'] ?? null;
        $this->checklist = $data['requirements'] ?? null;
        $this->checklistNote = $data['note'] ?? null;
        $this->checklistRawResponse = $data['raw_ai_response'] ?? null;
        $this->feedback = $data['feedback'] ?? null;
        $this->completed = true;
    }

    protected function draftPhase(): string
    {
        return 'ai_conversation_2';
    }

    protected function draftedProperties(): array
    {
        return ['roundIndex', 'turns', 'roleReversalDone', 'learnerQuestion', 'aiAnswerToLearner', 'finalTranscript', 'checklist', 'checklistNote', 'checklistRawResponse', 'finalAudioPath'];
    }

    /**
     * The rounds actually asked THIS attempt — see
     * MissionRun::finalTalkRounds(), shared with Mission Result's
     * Speaking Recall checklist so both always agree on which they were.
     */
    public function getRoundsProperty(): array
    {
        return $this->run->finalTalkRounds();
    }

    public function getRequirementsProperty(): array
    {
        return $this->run->mission->stepContent('ai_conversation_2')['requirements'] ?? [];
    }

    public function getFinalPromptProperty(): string
    {
        return $this->run->mission->stepContent('ai_conversation_2')['final_prompt'] ?? '';
    }

    public function getRoleReversalTopicProperty(): string
    {
        return $this->run->mission->stepContent('ai_conversation_2')['role_reversal_topic'] ?? 'your daily life';
    }

    public function getCurrentRoundPromptProperty(): ?string
    {
        return $this->rounds[$this->roundIndex] ?? null;
    }

    /** True once every Q&A round has been answered — the role-reversal round comes next. */
    public function getQaRoundsDoneProperty(): bool
    {
        return $this->roundIndex >= count($this->rounds);
    }

    /** True only once BOTH the Q&A rounds and the role-reversal round are done — the Final Challenge comes next. */
    public function getInFinalStageProperty(): bool
    {
        return $this->qaRoundsDone && $this->roleReversalDone;
    }

    public function submitRoundAnswer(): void
    {
        $this->error = null;
        $this->notice = null;
        $this->processing = true;
        $roundIndex = $this->roundIndex;

        $this->validate(['audioFile' => ['required', 'file', 'extensions:webm,ogg,mp3,wav,m4a', 'max:20480']]);

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
                    $this->currentRoundPrompt,
                    $answer,
                    $this->run->learner->levelDescription(),
                    $this->run->aiToneGuidance(),
                );
                $this->recordGeminiCall();
            } catch (\Throwable) {
                $this->notice = "Your answer was saved, but we couldn't check it just now — keep going.";
            }

            if ($check !== null) {
                $this->trackCheckAttempt($roundIndex, $check['severity']);

                if ($check['severity'] === 'major') {
                    $this->offTopicHint[$roundIndex] = $check['hint'];

                    return;
                }
            }

            unset($this->offTopicHint[$roundIndex], $this->exampleAnswer[$roundIndex]);

            try {
                $followup = trim(app(GeminiClient::class)->chat(
                    [['role' => 'user', 'text' => "Prompt: \"{$this->currentRoundPrompt}\"\nLearner's spoken response: \"{$answer}\""]],
                    systemPrompt: 'You are a friendly English conversation partner. Given the prompt and the '
                        .'learner\'s transcribed spoken response, reply with exactly ONE short, natural reaction or '
                        .'follow-up question (max 15 words) that shows you listened — no preamble, no quotation marks.'
                        .$this->run->aiToneGuidance()
                ));
                $this->recordGeminiCall();
            } catch (\Throwable) {
                $followup = '';
            }

            $this->turns[] = ['prompt' => $this->currentRoundPrompt, 'answer' => $answer, 'followup' => $followup];
            $this->roundIndex++;

            // Read above for this round, and Livewire keeps a computed
            // value for the whole request — without this the page would
            // still show the prompt just answered.
            unset($this->currentRoundPrompt, $this->qaRoundsDone, $this->inFinalStage);

            $this->keepStepDraft();
        } catch (\Throwable $e) {
            $this->error = AiFailure::message($e, "Something went wrong talking to the AI Instructor");
        } finally {
            $this->processing = false;
        }
    }

    /**
     * The role-reversal round: the learner records a genuine question
     * about the mission topic (instead of an answer), and the AI answers
     * it — closing the loop on a whole mission of being the one
     * answering questions.
     */
    public function submitLearnerQuestion(): void
    {
        $this->error = null;
        $this->notice = null;
        $this->processing = true;

        $this->validate(['audioFile' => ['required', 'file', 'extensions:webm,ogg,mp3,wav,m4a', 'max:20480']]);

        try {
            $question = trim(app(GroqClient::class)->transcribe($this->audioFile->getRealPath()));
            $this->recordGroqCall();
            $this->audioFile = null;

            // Same rule as submitRoundAnswer(): with every judge model
            // down, the question is accepted unevaluated.
            $check = null;

            try {
                $check = app(SpokenAnswerChecker::class)->checkGenuineQuestion(
                    $this->roleReversalTopic,
                    $question,
                    $this->run->learner->levelDescription(),
                );
                $this->recordGeminiCall();
            } catch (\Throwable) {
                $this->notice = "Your answer was saved, but we couldn't check it just now — keep going.";
            }

            if ($check !== null) {
                $this->trackCheckAttempt('role_reversal', $check['severity']);

                if ($check['severity'] === 'major') {
                    $this->offTopicHint['role_reversal'] = $check['hint'];

                    return;
                }
            }

            unset($this->offTopicHint['role_reversal'], $this->exampleAnswer['role_reversal']);
            $this->learnerQuestion = $question;

            try {
                $this->aiAnswerToLearner = trim(app(GeminiClient::class)->chat(
                    [['role' => 'user', 'text' => "The learner just asked you: \"{$question}\""]],
                    systemPrompt: 'You are a friendly English conversation partner having a real back-and-forth '
                        .'with '.$this->run->learner->levelDescription().'. They just asked YOU a genuine question '
                        .'about '.$this->roleReversalTopic.'. Answer it naturally and warmly, like a real person '
                        .'would — 1 to 3 short sentences, no preamble.'
                        .$this->run->aiToneGuidance()
                ));
                $this->recordGeminiCall();
            } catch (\Throwable) {
                $this->aiAnswerToLearner = "That's a great question — I'm having trouble answering right now, but well done for asking it!";
            }

            $this->roleReversalDone = true;
            unset($this->inFinalStage);

            $this->keepStepDraft();
        } catch (\Throwable $e) {
            $this->error = AiFailure::message($e, "Something went wrong talking to the AI Instructor");
        } finally {
            $this->processing = false;
        }
    }

    /**
     * Offered only after 3 genuinely off-topic/empty attempts on the same
     * round/prompt — see TracksCheckAttempts. $key is a round index, the
     * string "final" for the Final Challenge, or "role_reversal"; never
     * fills anything in for the learner, just gives them a starting idea.
     */
    public function revealExample(int|string $key): void
    {
        try {
            if ($key === 'role_reversal') {
                $this->exampleAnswer[$key] = app(SpokenAnswerChecker::class)->suggestQuestion(
                    $this->roleReversalTopic,
                    $this->run->learner->levelDescription(),
                );
                $this->recordGeminiCall();
                $this->clearCheckAttempt($key);

                return;
            }

            $prompt = $key === 'final' ? $this->finalPrompt : $this->rounds[$key];

            $this->exampleAnswer[$key] = app(SpokenAnswerChecker::class)->suggestExample(
                $prompt,
                $this->run->learner->levelDescription(),
            );
            $this->recordGeminiCall();
            $this->clearCheckAttempt($key);
        } catch (\Throwable $e) {
            $this->error = AiFailure::message($e, "Couldn't get an example");
        }
    }

    public function declineExample(int|string $key): void
    {
        $this->declineCheckReveal($key);
    }

    public function submitFinalChallenge(): void
    {
        $this->error = null;
        $this->processing = true;

        $this->validate(['audioFile' => ['required', 'file', 'extensions:webm,ogg,mp3,wav,m4a', 'max:20480']]);

        $this->finalAudioPath = $this->keepDraftRecording($this->audioFile, $this->finalAudioPath);
        $this->audioFile = null;
        $this->keepStepDraft();

        try {
            $transcript = trim(app(GroqClient::class)->transcribe(Storage::disk('public')->path($this->finalAudioPath)));
            $this->recordGroqCall();

            $check = app(SpokenAnswerChecker::class)->checkRelevance(
                $this->finalPrompt,
                $transcript,
                $this->run->learner->levelDescription(),
                $this->run->aiToneGuidance(),
            );
            $this->recordGeminiCall();

            $this->trackCheckAttempt('final', $check['severity']);

            if ($check['severity'] === 'major') {
                $this->offTopicHint['final'] = $check['hint'];

                return;
            }

            unset($this->offTopicHint['final'], $this->exampleAnswer['final']);
            $this->finalTranscript = $transcript;

            $requirementList = collect($this->requirements)->map(fn ($r) => "\"{$r}\"")->implode(', ');

            // Grounds "5+ vocabulary expressions" in the learner's own
            // Vocabulary Builder selection instead of a vague general
            // judgment — this is the same thread every mission's Final
            // Challenge should pull from (see EOS-009 §7 step 02).
            $vocabularyWords = $this->run->selectedVocabularyWords();
            $vocabularyContext = $vocabularyWords
                ? " The learner's target vocabulary words for this mission were: "
                    .collect($vocabularyWords)->map(fn ($w) => "\"{$w}\"")->implode(', ').'. For any requirement '
                    .'about vocabulary expressions, count specifically how many of these words (or a natural '
                    .'form of them) appear in the transcript — do not judge vocabulary in general.'
                : '';

            // "1+ BBC expression" is meaningless to the AI as a bare label
            // — it has no way to know what "BBC" refers to. Ground it in
            // the mission's own Listening target_phrases explicitly, same
            // pattern as the vocabulary grounding above. A real run
            // (2026-09-03) marked this false despite the transcript
            // genuinely containing "oversleep" and "morning person".
            $bbcPhrases = collect($this->run->mission->stepContent('listening')['target_phrases'] ?? [])->pluck('phrase');
            $bbcContext = $bbcPhrases->isNotEmpty()
                ? ' For any requirement mentioning a "BBC expression", it means one of these exact phrases from '
                    .'the mission\'s Listening episode: '.$bbcPhrases->map(fn ($p) => "\"{$p}\"")->implode(', ')
                    .' — check whether the transcript naturally uses any of them (or a natural form of them).'
                : '';

            $raw = app(GeminiClient::class)->chat(
                [['role' => 'user', 'text' => "Transcript: \"{$this->finalTranscript}\""]],
                systemPrompt: 'You are an English teacher checking the 3-minute speaking challenge transcript of '
                    .$this->run->learner->levelDescription()
                    ." against a requirements checklist.{$vocabularyContext}{$bbcContext} For each of these "
                    ."requirements: [{$requirementList}], decide if the transcript satisfies it. Reply with ONLY "
                    .'valid JSON, no markdown fences: {"requirements": {"<requirement label exactly as given>": '
                    .'true or false, ...}, "note": "one short encouraging sentence about their overall performance"}',
                profile: GeminiClient::PROFILE_JUDGE,
            );
            $this->recordGeminiCall();

            $data = json_decode(trim($raw), true);

            if (! is_array($data) || ! isset($data['requirements'], $data['note'])) {
                throw new RuntimeException('Unexpected AI response format.');
            }

            $this->checklist = $data['requirements'];
            $this->checklistNote = $data['note'];
            $this->checklistRawResponse = $raw;

            $this->keepStepDraft();
        } catch (\Throwable $e) {
            $this->error = AiFailure::message($e, "Something went wrong talking to the AI Instructor");
        } finally {
            $this->processing = false;
        }
    }

    /** "Try again" on the challenge: record it once more, checked afresh. */
    public function retryFinalChallenge(): void
    {
        $this->finalTranscript = null;
        $this->checklist = null;
        $this->checklistNote = null;
        $this->checklistRawResponse = null;

        $this->keepStepDraft();
    }

    public function finishConversation(): void
    {
        if (! $this->checklist) {
            return;
        }

        $this->generateFeedback();

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'ai_conversation_2',
            'type' => Evidence::TYPE_TRANSCRIPT,
            'content_ref' => json_encode([
                'rounds' => $this->turns,
                'role_reversal' => [
                    'question' => $this->learnerQuestion,
                    'answer' => $this->aiAnswerToLearner,
                ],
                'final_transcript' => $this->finalTranscript,
                'requirements' => $this->checklist,
                'note' => $this->checklistNote,
                'raw_ai_response' => $this->checklistRawResponse,
                'feedback' => $this->feedback,
            ]),
        ]);

        if ($this->finalAudioPath) {
            Evidence::create([
                'mission_run_id' => $this->run->id,
                'phase' => 'ai_conversation_2',
                'type' => Evidence::TYPE_AUDIO,
                'content_ref' => Storage::disk('public')->url($this->finalAudioPath),
            ]);
        }

        $this->forgetStepDraft();
        $this->completed = true;
    }

    /**
     * The shared sentence-by-sentence feedback on the whole talk. Silent on
     * failure: the talk itself is already saved either way.
     */
    private function generateFeedback(): void
    {
        try {
            $this->feedback = app(LearnerFeedback::class)->review(
                $this->run->learner,
                '',
                situation: 'the last speaking task of a mission: spoken answers to a few questions, one question the '
                    .'learner asked, then a 3-minute talk. Each Question is the AI partner; judge ONLY the learner\'s lines.',
                source: 'ai_conversation_2',
                spoken: true,
                onCallSucceeded: fn () => $this->recordGeminiCall(),
                answers: $this->answers(),
            );

            if ($this->feedback['severity'] === 'major') {
                $this->run->recordStruggleSignal();
            }
        } catch (\Throwable) {
            // Silent by design — see method docblock.
        }
    }

    /**
     * Every answer, the learner's own question and the 3-minute talk, in
     * order — what the feedback is given on.
     *
     * @return list<array{question: ?string, text: string}>
     */
    public function answers(): array
    {
        $answers = array_map(fn (array $turn) => ['question' => $turn['prompt'], 'text' => $turn['answer']], $this->turns);

        if (filled($this->learnerQuestion)) {
            $answers[] = ['question' => 'Your turn — ask me something', 'text' => $this->learnerQuestion];
        }

        if (filled($this->finalTranscript)) {
            $answers[] = ['question' => $this->finalPrompt, 'text' => $this->finalTranscript];
        }

        return $answers;
    }

    public function finalAudioUrl(): ?string
    {
        if ($this->finalAudioPath) {
            return Storage::disk('public')->url($this->finalAudioPath);
        }

        return $this->readOnly
            ? $this->run->evidence()->where('phase', 'ai_conversation_2')->where('type', Evidence::TYPE_AUDIO)->latest()->value('content_ref')
            : null;
    }

    public function proceed(): void
    {
        $this->redirect($this->run->urlAfterSavingAStep(), navigate: true);
    }
};
?>

@php
    $content = $run->mission->stepContent('ai_conversation_2');
    $roundCount = count($this->rounds);
    $chatTotal = $roundCount + 1;
    $chatDone = min($roundIndex, $roundCount) + ($roleReversalDone ? 1 : 0);
    $askKey = $this->qaRoundsDone ? 'role_reversal' : $roundIndex;
    $startScreen = $completed ? 3 : ($roleReversalDone && ($checklist || $finalAudioPath) ? 2 : (count($turns) ? 1 : 0));
@endphp

@if ($readOnly)
    <div class="flex flex-col gap-4 pt-2" @if ($preview) inert @endif>
        <x-step-intro kind="Final Talk" :title="$content['hook'] ?? null" />
        @foreach ($turns as $turn)
            <x-conversation-turn :prompt="$turn['prompt']" :answer="$turn['answer']" :followup="$turn['followup']" />
        @endforeach
        @if ($learnerQuestion)
            <p class="max-w-[85%] self-end rounded-2xl rounded-br-md bg-accent-soft px-3.5 py-2.5 text-base text-ink dark:bg-accent-soft-dark dark:text-ink-dark">{{ $learnerQuestion }}</p>
            <p class="max-w-[85%] self-start rounded-2xl rounded-bl-md bg-surface px-3.5 py-2.5 text-base font-semibold text-ink dark:bg-surface-dark dark:text-ink-dark">{{ $aiAnswerToLearner }}</p>
        @endif
        @if ($checklist)
            @include('components.missions.steps.partials.final-talk-goals')
        @endif
        @if ($url = $this->finalAudioUrl())
            <x-audio-player :url="$url" />
        @endif
        @if ($feedback)
            <x-learner-feedback :feedback="$feedback" :answers="$this->answers()" />
        @elseif ($checklistNote)
            <p class="text-sm text-ink-soft dark:text-ink-soft-dark">{{ $checklistNote }}</p>
        @endif
    </div>
@else
<div
    x-data="{ screen: {{ $startScreen }}, idea: false, noPrep: false }"
    x-effect="$dispatch('step-progress', { share: screen === 1 ? (1 + {{ $chatDone / $chatTotal }}) / 4 : (screen + 0.5) / 4 })"
    x-back-close.step="{ open: screen === 1, close: () => screen-- }"
>
    <x-lesson-screen when="screen === 0">
        <x-step-intro kind="Final Talk" :minutes="$run->mission->stepDuration('ai_conversation_2')" :title="$content['hook'] ?? null" />

        <x-slot:actions>
            <x-continue-button on-click="screen = 1" wire-target="submitRoundAnswer">Start</x-continue-button>
        </x-slot:actions>
    </x-lesson-screen>

    {{-- The Q&A rounds and "Your turn — ask me" as one chat. --}}
    <section x-show="screen === 1" x-cloak class="animate-lesson-screen-in flex min-h-[calc(100dvh-11rem)] flex-col pt-2">
        @if ($this->run->mission->scaffoldLevel() === App\Models\Mission::SCAFFOLD_MINIMAL && ! $this->qaRoundsDone)
            <button type="button" x-on:click="noPrep = ! noPrep" class="flex w-fit cursor-pointer items-center gap-2 self-end rounded-full bg-surface px-3 py-1.5 text-xs font-extrabold text-ink-soft dark:bg-surface-dark dark:text-ink-soft-dark">
                No-prep challenge
                <span class="relative h-4 w-7 rounded-full transition-colors" :class="noPrep ? 'bg-accent dark:bg-accent-dark' : 'bg-line dark:bg-line-dark'"><span class="absolute top-0.5 h-3 w-3 rounded-full bg-white transition-all" :class="noPrep ? 'left-3.5' : 'left-0.5'"></span></span>
            </button>
        @endif

        <div class="flex flex-1 flex-col justify-end gap-4">
            @foreach ($turns as $turn)
                <x-conversation-turn chat :prompt="$turn['prompt']" :answer="$turn['answer']" :followup="$turn['followup']" />
            @endforeach

            @if (! $this->qaRoundsDone)
                <div wire:key="round-{{ $roundIndex }}" x-data="{ revealed: false }" class="flex flex-col">
                    <div
                        x-show="! noPrep || revealed"
                        x-effect="if (screen === 1 && (! noPrep || revealed)) $nextTick(() => window.eosVoice?.speak(@js($this->currentRoundPrompt)))"
                        class="flex max-w-[85%] items-start gap-1.5 self-start rounded-2xl rounded-bl-md bg-surface px-3.5 py-2.5 text-base font-semibold text-ink dark:bg-surface-dark dark:text-ink-dark"
                    >
                        <x-speak-word :word="$this->currentRoundPrompt" top />
                    </div>
                    <button type="button" x-show="noPrep && ! revealed" x-on:click="revealed = true" class="max-w-[85%] cursor-pointer self-start rounded-2xl rounded-bl-md border-2 border-dashed border-line px-3.5 py-2.5 text-sm font-bold text-ink-soft dark:border-line-dark dark:text-ink-soft-dark">Ready? Tap to see the question</button>
                </div>
            @else
                <span class="self-center rounded-full bg-accent-soft px-3 py-1 text-xs font-extrabold text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">Your turn — ask me something</span>
                @if ($learnerQuestion)
                    <p class="max-w-[85%] self-end rounded-2xl rounded-br-md bg-accent-soft px-3.5 py-2.5 text-base text-ink dark:bg-accent-soft-dark dark:text-ink-dark">{{ $learnerQuestion }}</p>
                    <div
                        x-effect="if (screen === 1) $nextTick(() => window.eosVoice?.speak(@js($aiAnswerToLearner)))"
                        class="flex max-w-[85%] items-start gap-1.5 self-start rounded-2xl rounded-bl-md bg-surface px-3.5 py-2.5 text-base font-semibold text-ink dark:bg-surface-dark dark:text-ink-dark"
                    >
                        <x-speak-word :word="(string) $aiAnswerToLearner" top />
                    </div>
                @else
                    <p class="self-center text-center text-xs font-bold text-ink-faint dark:text-ink-faint-dark">About {{ $this->roleReversalTopic }}</p>
                @endif
            @endif

            @if ($offTopicHint[$askKey] ?? null)
                <p class="max-w-[85%] self-end rounded-2xl bg-accent-soft px-3.5 py-2 text-sm text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">{{ $offTopicHint[$askKey] }}</p>
            @endif
            <x-almost-reveal-notice
                :show="($checkAttempts[$askKey] ?? 0) === 2"
                label="One more try — after that I can suggest an example to help you get started."
            />

            <x-ai-thinking wire:loading wire:target="submitRoundAnswer,submitLearnerQuestion" label="Listening to your answer…" />
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

        @if (! $roleReversalDone)
            <div class="sticky bottom-0 mt-4 flex items-center justify-between gap-3 bg-ground py-3 dark:bg-ground-dark">
                <button
                    type="button"
                    x-on:click="idea = true; @if (! ($exampleAnswer[$askKey] ?? null)) $wire.revealExample(@js($askKey)) @endif"
                    class="min-w-16 cursor-pointer rounded-full bg-surface px-3 py-1.5 text-xs font-extrabold text-ink-soft dark:bg-surface-dark dark:text-ink-soft-dark"
                >💡 Idea</button>
                <div wire:key="recorder-{{ $askKey }}" wire:loading.remove wire:target="submitRoundAnswer,submitLearnerQuestion" class="flex flex-1 justify-center">
                    <x-voice-recorder
                        big
                        field="audioFile"
                        :file="$audioFile"
                        :on-recorded="$this->qaRoundsDone ? 'submitLearnerQuestion' : 'submitRoundAnswer'"
                        :file-name="$this->qaRoundsDone ? 'question.webm' : 'answer.webm'"
                    />
                </div>
                <span class="min-w-16 rounded-full bg-surface px-3 py-1.5 text-center text-xs font-extrabold text-ink-soft tabular-nums dark:bg-surface-dark dark:text-ink-soft-dark">{{ min($chatDone + 1, $chatTotal) }} / {{ $chatTotal }}</span>
            </div>

            <x-bottom-sheet show="idea" label="An example">
                <x-slot:title>💡 Something like this</x-slot:title>
                <x-ai-thinking wire:loading wire:target="revealExample" label="Thinking of an example…" />
                @if ($exampleAnswer[$askKey] ?? null)
                    <p class="text-base leading-relaxed text-ink dark:text-ink-dark">{{ $exampleAnswer[$askKey] }}</p>
                @endif
            </x-bottom-sheet>
        @else
            <div class="mt-4 pb-2">
                <x-continue-button on-click="screen = 2" wire-target="submitLearnerQuestion" />
            </div>
        @endif
    </section>

    {{-- The 3-minute challenge: a ring that fills while recording, the
         goals ticked once the talk is checked. --}}
    <x-lesson-screen when="screen === 2">
        <span class="inline-flex w-fit rounded-full bg-accent-soft px-3 py-1 text-xs font-extrabold tracking-wide text-accent-ink uppercase dark:bg-accent-soft-dark dark:text-accent-ink-dark">Talk for 3 minutes</span>
        <p class="flex items-start gap-1.5 lesson-prompt text-ink dark:text-ink-dark"><x-speak-word :word="$this->finalPrompt" top /></p>

        @unless ($checklist)
            <div
                x-data="{ secs: 0, timer: null }"
                x-on:eos-recording.window="if ($event.detail) { timer ??= setInterval(() => secs++, 1000) } else { clearInterval(timer); timer = null }"
                class="flex justify-center"
            >
                <div class="flex h-24 w-24 items-center justify-center rounded-full" :style="`background: conic-gradient(var(--color-accent) 0 ${Math.min(secs / 180, 1) * 100}%, var(--color-line) 0 100%)`">
                    <span class="flex h-20 w-20 items-center justify-center rounded-full bg-ground text-lg font-extrabold text-ink tabular-nums dark:bg-ground-dark dark:text-ink-dark" x-text="`${Math.floor(secs / 60)}:${String(secs % 60).padStart(2, '0')}`">0:00</span>
                </div>
            </div>
        @endunless

        @include('components.missions.steps.partials.final-talk-goals')

        @if ($checklist)
            @if ($url = $this->finalAudioUrl())
                <x-audio-player :url="$url" />
            @endif
        @else
            <div class="flex justify-center" wire:loading.remove wire:target="submitFinalChallenge">
                <x-voice-recorder big field="audioFile" :file="$audioFile" on-recorded="submitFinalChallenge" file-name="final.webm" />
            </div>
            <x-ai-thinking wire:loading wire:target="submitFinalChallenge" label="Checking your talk…" />
            @if ($exampleAnswer['final'] ?? null)
                <div class="rounded-2xl bg-accent-soft px-4 py-3 dark:bg-accent-soft-dark">
                    <p class="text-xs font-extrabold text-accent-ink uppercase dark:text-accent-ink-dark">Something like this…</p>
                    <p class="mt-1 text-sm text-ink dark:text-ink-dark">{{ $exampleAnswer['final'] }}</p>
                </div>
            @elseif ($offTopicHint['final'] ?? null)
                <x-severity-feedback :feedback="['severity' => 'major', 'hint' => $offTopicHint['final']]" />
            @endif
            <x-almost-reveal-notice
                :show="($checkAttempts['final'] ?? 0) === 2"
                label="One more try — after that I can suggest an example to help you get started."
            />
            <x-reveal-offer
                :show="$offerReveal['final'] ?? false"
                :struggling="$this->run->isStruggling()"
                reveal-method="revealExample"
                decline-method="declineExample"
                index="final"
                wire-target="submitFinalChallenge,revealExample,declineExample"
                label="Want an example to help you get started?"
            />
            @error('audioFile')
                <p class="text-sm text-danger-ink">{{ $message }}</p>
            @enderror
            @if ($error)
                <p class="text-sm text-danger-ink">{{ $error }}</p>
            @endif
        @endif

        <x-slot:actions>
            <x-continue-button
                on-click="screen = 3; $wire.finishConversation()"
                wire-target="finishConversation,retryFinalChallenge"
                ready-when="{{ $checklist ? 'true' : 'false' }}"
            >See feedback</x-continue-button>
            @if ($checklist)
                <button type="button" wire:click="retryFinalChallenge" class="cursor-pointer text-center text-sm font-bold text-accent-ink dark:text-accent-ink-dark">↻ Try again</button>
            @endif
        </x-slot:actions>
    </x-lesson-screen>

    <x-feedback-screen when="screen === 3" :feedback="$feedback" :answers="$this->answers()" :waiting="! $completed">
        @if (count($this->rounds))
            <x-slot:said>
                <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Want more practice? Ask each other all of these questions for real.</p>
                <x-practice-session-with-friend :mission="$run->mission" step-key="ai_conversation_2" />
            </x-slot:said>
        @endif

        <x-slot:actions>
            <x-continue-button on-click="$wire.proceed()" wire-target="finishConversation,proceed" ready-when="{{ $completed ? 'true' : 'false' }}" />
        </x-slot:actions>
    </x-feedback-screen>
</div>
@endif
