<?php

use App\Livewire\Concerns\TracksAiUsage;
use App\Livewire\Concerns\TracksCheckAttempts;
use App\Models\Evidence;
use App\Models\MissionRun;
use App\Services\GeminiClient;
use App\Services\GroqClient;
use App\Services\SpokenAnswerChecker;
use Illuminate\Http\UploadedFile;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;
    use TracksAiUsage;
    use TracksCheckAttempts;

    public MissionRun $run;

    public bool $readOnly = false;

    public int $roundIndex = 0;

    /** @var array<int, array{prompt: string, answer: string, followup: string}> */
    public array $turns = [];

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

    public bool $processing = false;

    public ?string $error = null;

    /** @var array<int, string> keyed by round — set when the last spoken attempt was off-topic/empty. Final Challenge uses key "final". */
    public array $offTopicHint = [];

    /** @var array<int, string> keyed the same way — an example answer, shown only after 3 off-topic attempts */
    public array $exampleAnswer = [];

    public function mount(): void
    {
        if (! $this->readOnly) {
            return;
        }

        $data = json_decode($this->run->latestEvidence('ai_conversation_2')?->content_ref ?? '{}', true);
        $this->turns = $data['rounds'] ?? [];
        $this->roundIndex = count($this->rounds);
        $this->finalTranscript = $data['final_transcript'] ?? null;
        $this->checklist = $data['requirements'] ?? null;
        $this->checklistNote = $data['note'] ?? null;
        $this->checklistRawResponse = $data['raw_ai_response'] ?? null;
    }

    public function getRoundsProperty(): array
    {
        return $this->run->mission->stepContent('ai_conversation_2')['rounds'] ?? [];
    }

    public function getRequirementsProperty(): array
    {
        return $this->run->mission->stepContent('ai_conversation_2')['requirements'] ?? [];
    }

    public function getFinalPromptProperty(): string
    {
        return $this->run->mission->stepContent('ai_conversation_2')['final_prompt'] ?? '';
    }

    public function getCurrentRoundPromptProperty(): ?string
    {
        return $this->rounds[$this->roundIndex] ?? null;
    }

    public function getInFinalStageProperty(): bool
    {
        return $this->roundIndex >= count($this->rounds);
    }

    public function submitRoundAnswer(): void
    {
        $this->error = null;
        $this->processing = true;
        $roundIndex = $this->roundIndex;

        $this->validate(['audioFile' => ['required', 'file', 'extensions:webm,ogg,mp3,wav,m4a', 'max:20480']]);

        try {
            $answer = trim(app(GroqClient::class)->transcribe($this->audioFile->getRealPath()));
            $this->recordGroqCall();
            $this->audioFile = null;

            $check = app(SpokenAnswerChecker::class)->checkRelevance(
                $this->currentRoundPrompt,
                $answer,
                $this->run->learner->levelDescription(),
                $this->run->aiToneGuidance(),
            );
            $this->recordGeminiCall();

            $this->trackCheckAttempt($roundIndex, $check['severity']);

            if ($check['severity'] === 'major') {
                $this->offTopicHint[$roundIndex] = $check['hint'];

                return;
            }

            unset($this->offTopicHint[$roundIndex], $this->exampleAnswer[$roundIndex]);

            $followup = trim(app(GeminiClient::class)->chat(
                [['role' => 'user', 'text' => "Prompt: \"{$this->currentRoundPrompt}\"\nLearner's spoken response: \"{$answer}\""]],
                systemPrompt: 'You are a friendly English conversation partner. Given the prompt and the '
                    .'learner\'s transcribed spoken response, reply with exactly ONE short, natural reaction or '
                    .'follow-up question (max 15 words) that shows you listened — no preamble, no quotation marks.'
                    .$this->run->aiToneGuidance()
            ));
            $this->recordGeminiCall();

            $this->turns[] = ['prompt' => $this->currentRoundPrompt, 'answer' => $answer, 'followup' => $followup];
            $this->roundIndex++;
        } catch (\Throwable $e) {
            $this->error = "Something went wrong talking to the AI Instructor: {$e->getMessage()}";
        } finally {
            $this->processing = false;
        }
    }

    /**
     * Offered only after 3 genuinely off-topic/empty attempts on the same
     * round/prompt — see TracksCheckAttempts. $key is a round index or the
     * string "final" for the Final Challenge; never fills anything in for
     * the learner, just gives them a starting idea.
     */
    public function revealExample(int|string $key): void
    {
        try {
            $prompt = $key === 'final' ? $this->finalPrompt : $this->rounds[$key];

            $this->exampleAnswer[$key] = app(SpokenAnswerChecker::class)->suggestExample(
                $prompt,
                $this->run->learner->levelDescription(),
            );
            $this->recordGeminiCall();
            $this->clearCheckAttempt($key);
        } catch (\Throwable $e) {
            $this->error = "Couldn't get an example: {$e->getMessage()}";
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

        try {
            $transcript = trim(app(GroqClient::class)->transcribe($this->audioFile->getRealPath()));
            $this->recordGroqCall();
            $this->audioFile = null;

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
                    .'true or false, ...}, "note": "one short encouraging sentence about their overall performance"}'
            );
            $this->recordGeminiCall();

            $data = json_decode(trim($raw), true);

            if (! is_array($data) || ! isset($data['requirements'], $data['note'])) {
                throw new RuntimeException('Unexpected AI response format.');
            }

            $this->checklist = $data['requirements'];
            $this->checklistNote = $data['note'];
            $this->checklistRawResponse = $raw;
        } catch (\Throwable $e) {
            $this->error = "Something went wrong talking to the AI Instructor: {$e->getMessage()}";
        } finally {
            $this->processing = false;
        }
    }

    public function finishConversation(): void
    {
        if (! $this->checklist) {
            return;
        }

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'ai_conversation_2',
            'type' => Evidence::TYPE_TRANSCRIPT,
            'content_ref' => json_encode([
                'rounds' => $this->turns,
                'final_transcript' => $this->finalTranscript,
                'requirements' => $this->checklist,
                'note' => $this->checklistNote,
                'raw_ai_response' => $this->checklistRawResponse,
            ]),
        ]);

        $this->redirect(route('missions.show', $this->run->mission), navigate: true);
    }
};
?>

<div class="space-y-6">
    <x-hook :text="$run->mission->stepContent('ai_conversation_2')['hook'] ?? null" />

    <div>
        <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">AI Conversation #2 — Final Challenge</p>
        <p class="text-xs text-ink-faint dark:text-ink-faint-dark">This session should be harder than the first one.</p>
    </div>

    @if (count($turns))
        <div class="space-y-3">
            @foreach ($turns as $turn)
                <x-conversation-turn :prompt="$turn['prompt']" :answer="$turn['answer']" :followup="$turn['followup']" />
            @endforeach
        </div>
    @endif

    @if (! $this->inFinalStage)
        <div class="rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
            <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Round {{ $roundIndex + 1 }} of {{ count($this->rounds) }}</p>
            <div class="mt-1 flex items-start justify-between gap-2">
                <p class="font-display text-lg font-bold text-ink dark:text-ink-dark">{{ $this->currentRoundPrompt }}</p>
                @unless ($readOnly)
                    <x-speak-button :text="$this->currentRoundPrompt" />
                @endunless
            </div>

            <div class="mt-2">
                <x-practice-with-friend :text="$this->currentRoundPrompt" />
            </div>

            <div class="mt-3" wire:key="recorder-round-{{ $roundIndex }}" wire:loading.remove wire:target="submitRoundAnswer">
                <x-voice-recorder
                    field="audioFile"
                    :file="$audioFile"
                    on-recorded="submitRoundAnswer"
                    file-name="answer.webm"
                />
            </div>

            <p wire:loading wire:target="submitRoundAnswer" class="mt-3 text-sm text-ink-faint dark:text-ink-faint-dark">Transcribing…</p>

            @if ($exampleAnswer[$roundIndex] ?? null)
                <div class="mt-2 rounded-xl border border-accent-soft bg-accent-soft/60 px-3 py-2 dark:border-accent-soft-dark dark:bg-accent-soft-dark/60">
                    <p class="text-xs font-semibold text-accent-ink uppercase dark:text-accent-ink-dark">Something like this…</p>
                    <p class="mt-1 text-sm text-ink dark:text-ink-dark">{{ $exampleAnswer[$roundIndex] }}</p>
                </div>
            @elseif ($offTopicHint[$roundIndex] ?? null)
                <x-severity-feedback :feedback="['severity' => 'major', 'hint' => $offTopicHint[$roundIndex]]" />
            @endif

            @unless ($readOnly)
                <x-almost-reveal-notice
                    :show="($checkAttempts[$roundIndex] ?? 0) === 2"
                    label="One more try — after that I can suggest an example to help you get started."
                />
                <x-reveal-offer
                    :show="$offerReveal[$roundIndex] ?? false"
                    reveal-method="revealExample"
                    decline-method="declineExample"
                    :index="$roundIndex"
                    wire-target="submitRoundAnswer,revealExample,declineExample"
                    label="Want an example to help you get started?"
                />
            @endunless
        </div>
    @elseif (! $checklist)
        <div class="rounded-2xl border border-line bg-surface-sunken p-4 dark:border-line-dark dark:bg-surface-sunken-dark">
            <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Final Challenge · Topic: My Daily Life</p>
            <div class="mt-1 flex items-start justify-between gap-2">
                <p class="font-display text-lg font-bold text-ink dark:text-ink-dark">{{ $this->finalPrompt }}</p>
                @unless ($readOnly)
                    <x-speak-button :text="$this->finalPrompt" />
                @endunless
            </div>

            <div class="mt-2">
                <x-practice-with-friend :text="$this->finalPrompt" />
            </div>

            <div class="mt-3" wire:loading.remove wire:target="submitFinalChallenge">
                <x-voice-recorder
                    field="audioFile"
                    :file="$audioFile"
                    on-recorded="submitFinalChallenge"
                    file-name="answer.webm"
                />
            </div>

            <p wire:loading wire:target="submitFinalChallenge" class="mt-3 text-sm text-ink-faint dark:text-ink-faint-dark">
                Checking your answer against the requirements…
            </p>

            @if ($exampleAnswer['final'] ?? null)
                <div class="mt-2 rounded-xl border border-accent-soft bg-accent-soft/60 px-3 py-2 dark:border-accent-soft-dark dark:bg-accent-soft-dark/60">
                    <p class="text-xs font-semibold text-accent-ink uppercase dark:text-accent-ink-dark">Something like this…</p>
                    <p class="mt-1 text-sm text-ink dark:text-ink-dark">{{ $exampleAnswer['final'] }}</p>
                </div>
            @elseif ($offTopicHint['final'] ?? null)
                <x-severity-feedback :feedback="['severity' => 'major', 'hint' => $offTopicHint['final']]" />
            @endif

            @unless ($readOnly)
                <x-almost-reveal-notice
                    :show="($checkAttempts['final'] ?? 0) === 2"
                    label="One more try — after that I can suggest an example to help you get started."
                />
                <x-reveal-offer
                    :show="$offerReveal['final'] ?? false"
                    reveal-method="revealExample"
                    decline-method="declineExample"
                    index="final"
                    wire-target="submitFinalChallenge,revealExample,declineExample"
                    label="Want an example to help you get started?"
                />
            @endunless
        </div>
    @else
        <div class="space-y-3">
            <p class="text-sm font-semibold text-ink dark:text-ink-dark">Requirements</p>
            <div class="grid gap-2 sm:grid-cols-2">
                @foreach ($this->requirements as $requirement)
                    <div class="flex items-center gap-2 text-sm text-ink dark:text-ink-dark">
                        @if ($checklist[$requirement] ?? false)
                            <span class="shrink-0 text-success dark:text-success-dark">@svg('heroicon-o-check-circle', 'h-4 w-4')</span>
                        @else
                            <span class="inline-block h-4 w-4 shrink-0 rounded-full border-2 border-line dark:border-line-dark"></span>
                        @endif
                        <span>{{ $requirement }}</span>
                    </div>
                @endforeach
            </div>
            <p class="text-sm text-ink-soft dark:text-ink-soft-dark">{{ $checklistNote }}</p>

            @if (count($this->rounds))
                <div>
                    <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Want more practice? Ask each other all of these questions for real.</p>
                    <div class="mt-1.5">
                        <x-practice-session-with-friend :mission="$run->mission" step-key="ai_conversation_2" />
                    </div>
                </div>
            @endif

            @unless ($readOnly)
                <button wire:click="finishConversation"
                    wire:loading.attr="disabled"
                    wire:target="finishConversation"
                    class="cursor-pointer rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 dark:bg-accent-dark">
                    <span wire:loading.remove wire:target="finishConversation">Continue</span>
                    <span wire:loading wire:target="finishConversation">Saving…</span>
                </button>
            @endunless
        </div>
    @endif

    @error('audioFile')
        <p class="text-sm text-red-600">{{ $message }}</p>
    @enderror
    @if ($error)
        <p class="text-sm text-red-600">{{ $error }}</p>
    @endif
</div>
