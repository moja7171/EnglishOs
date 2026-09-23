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

    /**
     * How many rounds one attempt actually asks — the seeded `round_pool`
     * can hold more than this (mission structure redesign, Epic E: real
     * variety across attempts instead of always the same fixed rounds in
     * the same order). A pool no bigger than this is used exactly as
     * seeded, unchanged — see getRoundsProperty().
     */
    public const ROUNDS_PER_ATTEMPT = 3;

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

    /**
     * Optional extra round (App\Services\PiPrompts): the learner can have
     * this same challenge as a real, live conversation with their
     * Language Partner chat in Pi, then paste the transcript here for
     * feedback — the one place a Pi transcript actually comes back into
     * the app. Saved as its own Evidence row, never touches $checklist or
     * finishConversation().
     */
    public string $piTranscriptInput = '';

    public ?string $piTranscript = null;

    /** @var array{highlight: string, tip: string}|null */
    public ?array $piFeedback = null;

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

        // The optional Pi transcript round (see submitPiTranscript()) saves
        // its own separate TEXT-type Evidence row after this phase's real
        // TRANSCRIPT row already exists — filtered explicitly by type here
        // so it never shadows the actual challenge data below, and only
        // reloaded in readOnly review (a fresh/retry attempt starts empty,
        // same as every other property here).
        $piEvidence = $this->run->evidence()->where('phase', 'ai_conversation_2')->where('type', Evidence::TYPE_TEXT)->latest()->first();

        if ($piEvidence) {
            $piData = json_decode($piEvidence->content_ref, true);
            $this->piTranscript = $piData['transcript'] ?? null;
            $this->piFeedback = $piData['feedback'] ?? null;
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
    }

    /**
     * The rounds actually asked THIS attempt. A `round_pool` bigger than
     * ROUNDS_PER_ATTEMPT is shuffled and trimmed — deterministically per
     * run (seeded by the run's own id), so re-rendering mid-attempt never
     * reshuffles which prompts were already answered, but a different
     * run/learner genuinely sees a different subset. A pool no bigger
     * than ROUNDS_PER_ATTEMPT is returned exactly as seeded, unchanged —
     * every existing fixture/mission with 1-3 `rounds` keeps behaving
     * exactly as before.
     */
    public function getRoundsProperty(): array
    {
        $content = $this->run->mission->stepContent('ai_conversation_2');
        $pool = $content['round_pool'] ?? $content['rounds'] ?? [];

        if (count($pool) <= self::ROUNDS_PER_ATTEMPT) {
            return $pool;
        }

        $randomizer = new \Random\Randomizer(new \Random\Engine\Mt19937($this->run->id));

        return array_slice($randomizer->shuffleArray($pool), 0, self::ROUNDS_PER_ATTEMPT);
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
     * The role-reversal round: the learner records a genuine question
     * about the mission topic (instead of an answer), and the AI answers
     * it — closing the loop on a whole mission of being the one
     * answering questions.
     */
    public function submitLearnerQuestion(): void
    {
        $this->error = null;
        $this->processing = true;

        $this->validate(['audioFile' => ['required', 'file', 'extensions:webm,ogg,mp3,wav,m4a', 'max:20480']]);

        try {
            $question = trim(app(GroqClient::class)->transcribe($this->audioFile->getRealPath()));
            $this->recordGroqCall();
            $this->audioFile = null;

            $check = app(SpokenAnswerChecker::class)->checkGenuineQuestion(
                $this->roleReversalTopic,
                $question,
                $this->run->learner->levelDescription(),
            );
            $this->recordGeminiCall();

            $this->trackCheckAttempt('role_reversal', $check['severity']);

            if ($check['severity'] === 'major') {
                $this->offTopicHint['role_reversal'] = $check['hint'];

                return;
            }

            unset($this->offTopicHint['role_reversal'], $this->exampleAnswer['role_reversal']);
            $this->learnerQuestion = $question;

            $this->aiAnswerToLearner = trim(app(GeminiClient::class)->chat(
                [['role' => 'user', 'text' => "The learner just asked you: \"{$question}\""]],
                systemPrompt: 'You are a friendly English conversation partner having a real back-and-forth '
                    .'with '.$this->run->learner->levelDescription().'. They just asked YOU a genuine question '
                    .'about '.$this->roleReversalTopic.'. Answer it naturally and warmly, like a real person '
                    .'would — 1 to 3 short sentences, no preamble.'
                    .$this->run->aiToneGuidance()
            ));
            $this->recordGeminiCall();

            $this->roleReversalDone = true;
        } catch (\Throwable $e) {
            $this->error = "Something went wrong talking to the AI Instructor: {$e->getMessage()}";
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

    /**
     * The optional Pi round's own feedback — same lenient, one-correction,
     * Persian pattern as ai_conversation_1's transcribeAndReflect()
     * (never a grade). Never blocks finishConversation(); a failure here
     * just leaves $piFeedback null.
     */
    public function submitPiTranscript(): void
    {
        $transcript = trim($this->piTranscriptInput);

        if ($transcript === '') {
            return;
        }

        $this->piTranscript = $transcript;

        try {
            $raw = app(GeminiClient::class)->chat(
                [['role' => 'user', 'text' => "Transcript: \"{$transcript}\""]],
                systemPrompt: 'You are a supportive English speaking coach. '.ucfirst($this->run->learner->levelDescription())
                    .' just had a live spoken conversation practice session with a voice AI assistant, covering '
                    .'the same topic as their Final Challenge — this is extra practice, not a graded test. Given '
                    .'the transcript, write a short, warm, simple reflection in PERSIAN (Farsi) — never English, '
                    .'and never grade it or use severity labels. Reply with ONLY valid JSON, no markdown fences: '
                    .'{"highlight": "...", "tip": "..."} — "highlight" is one short encouraging sentence in '
                    .'Persian about something they did well; "tip" is one short, gentle, actionable suggestion in '
                    .'Persian for next time.'
            );
            $this->recordGeminiCall();

            $data = json_decode(trim($raw), true);

            if (is_array($data) && isset($data['highlight'], $data['tip'])) {
                $this->piFeedback = ['highlight' => $data['highlight'], 'tip' => $data['tip']];
            }
        } catch (Throwable) {
            // Silent by design, same as transcribeAndReflect() — the
            // learner already did the real practice outside the app; a
            // missing reflection is a shame, not a reason to show an error.
        }

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'ai_conversation_2',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode(['transcript' => $this->piTranscript, 'feedback' => $this->piFeedback]),
        ]);
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
                'role_reversal' => [
                    'question' => $this->learnerQuestion,
                    'answer' => $this->aiAnswerToLearner,
                ],
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

<div class="space-y-6" x-data="{ noPrepChallenge: false }">
    <x-hook :text="$run->mission->stepContent('ai_conversation_2')['hook'] ?? null" />

    <div>
        <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Final Talk</p>
        <p class="text-xs text-ink-soft dark:text-ink-soft-dark">Tougher this time — no starter words, so think it through before you speak.</p>
    </div>

    @if (count($turns))
        <div class="space-y-3">
            @foreach ($turns as $turn)
                <x-conversation-turn :prompt="$turn['prompt']" :answer="$turn['answer']" :followup="$turn['followup']" />
            @endforeach
        </div>
    @endif

    @if ($roleReversalDone && $learnerQuestion)
        <div class="rounded-xl border border-line p-3 text-sm dark:border-line-dark">
            <p class="font-semibold text-ink dark:text-ink-dark">You asked: {{ $learnerQuestion }}</p>
            <p class="mt-1 text-ink-soft dark:text-ink-soft-dark">AI Instructor: {{ $aiAnswerToLearner }}</p>
        </div>
    @endif

    @if (! $this->qaRoundsDone)
        {{-- wire:key on the WHOLE card, not just the recorder inside it:
             T6.2's per-round "revealed" gate below must reset fresh every
             round, and the only reliable way to force Alpine to
             reinitialize (rather than morph the existing node in place)
             is a key that changes with $roundIndex. See
             [[project_livewire_morph_xcloak_scroll_jump]] /
             [[project_xdata_interpolation_resets_alpine_state]] for why a
             literal x-data string plus a changing wire:key, not an
             interpolated value INSIDE x-data, is the safe pattern here. --}}
        <div wire:key="round-card-{{ $roundIndex }}" class="rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark" x-data="{ revealed: false }">
            <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Round {{ $roundIndex + 1 }} of {{ count($this->rounds) }}</p>

            @if ($this->run->mission->scaffoldLevel() === App\Models\Mission::SCAFFOLD_MINIMAL && ! $readOnly)
                {{-- T6.2: opt-in only in the last third — see <x-optional-challenge>. --}}
                <div class="mt-2" x-show="! noPrepChallenge">
                    <x-optional-challenge
                        model="noPrepChallenge"
                        label="Want to try this round with no time to prepare?"
                    />
                </div>
            @endif

            <div x-show="! noPrepChallenge || revealed">
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
            </div>

            {{-- The challenge's actual effect: question and recorder stay
                 hidden together behind one tap, removing the free,
                 unlimited silent-reading window that exists by default —
                 never a hard block (Article 3, Evidence Before Progress:
                 nothing here can gate anything), just no head start. --}}
            <div x-show="noPrepChallenge && ! revealed" class="mt-1">
                <button
                    type="button"
                    x-on:click="revealed = true"
                    class="w-full cursor-pointer rounded-xl border border-dashed border-line py-3 text-sm font-semibold text-ink-soft transition-colors hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                >Ready? Tap to see the question and answer</button>
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
                    :struggling="$this->run->isStruggling()"
                    reveal-method="revealExample"
                    decline-method="declineExample"
                    :index="$roundIndex"
                    wire-target="submitRoundAnswer,revealExample,declineExample"
                    label="Want an example to help you get started?"
                />
            @endunless
        </div>
    @elseif (! $roleReversalDone)
        {{-- Role-reversal round (Epic E): the learner asks a question
             instead of answering one — the same recorder mechanics, a
             different check (checkGenuineQuestion, not checkRelevance). --}}
        <div class="rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
            <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Your turn to ask</p>
            <p class="mt-1 font-display text-lg font-bold text-ink dark:text-ink-dark">Ask the AI Instructor a real question about {{ $this->roleReversalTopic }}.</p>

            <div class="mt-3" wire:loading.remove wire:target="submitLearnerQuestion">
                <x-voice-recorder
                    field="audioFile"
                    :file="$audioFile"
                    on-recorded="submitLearnerQuestion"
                    file-name="question.webm"
                />
            </div>

            <p wire:loading wire:target="submitLearnerQuestion" class="mt-3 text-sm text-ink-faint dark:text-ink-faint-dark">Transcribing…</p>

            @if ($exampleAnswer['role_reversal'] ?? null)
                <div class="mt-2 rounded-xl border border-accent-soft bg-accent-soft/60 px-3 py-2 dark:border-accent-soft-dark dark:bg-accent-soft-dark/60">
                    <p class="text-xs font-semibold text-accent-ink uppercase dark:text-accent-ink-dark">Something like this…</p>
                    <p class="mt-1 text-sm text-ink dark:text-ink-dark">{{ $exampleAnswer['role_reversal'] }}</p>
                </div>
            @elseif ($offTopicHint['role_reversal'] ?? null)
                <x-severity-feedback :feedback="['severity' => 'major', 'hint' => $offTopicHint['role_reversal']]" />
            @endif

            @unless ($readOnly)
                <x-almost-reveal-notice
                    :show="($checkAttempts['role_reversal'] ?? 0) === 2"
                    label="One more try — after that I can suggest a question to help you get started."
                />
                <x-reveal-offer
                    :show="$offerReveal['role_reversal'] ?? false"
                    :struggling="$this->run->isStruggling()"
                    reveal-method="revealExample"
                    decline-method="declineExample"
                    index="role_reversal"
                    wire-target="submitLearnerQuestion,revealExample,declineExample"
                    label="Want a question to help you get started?"
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
                    :struggling="$this->run->isStruggling()"
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

            <div class="rounded-xl border border-dashed border-line bg-surface-sunken p-3 dark:border-line-dark dark:bg-surface-sunken-dark">
                <p class="inline-flex items-center gap-1.5 text-sm font-semibold text-ink dark:text-ink-dark">
                    @svg('heroicon-o-chat-bubble-left-right', 'h-4 w-4 text-ink-faint dark:text-ink-faint-dark')
                    Want more practice? Try this live with Pi
                </p>
                <p class="mt-1 text-xs text-ink-soft dark:text-ink-soft-dark">Have this same challenge as a real, live conversation with your Language Partner chat in Pi, then paste the full transcript below for feedback.</p>

                @if ($piFeedback)
                    <div class="mt-2 space-y-2 rounded-xl border border-line bg-surface p-3 dark:border-line-dark dark:bg-surface-dark" dir="rtl">
                        <p class="text-sm text-ink dark:text-ink-dark">{{ $piFeedback['highlight'] }}</p>
                        <p class="flex items-start gap-1.5 text-sm text-ink-soft dark:text-ink-soft-dark">
                            @svg('heroicon-o-light-bulb', 'h-4 w-4 shrink-0 mt-0.5')
                            {{ $piFeedback['tip'] }}
                        </p>
                    </div>
                @elseif (! $readOnly)
                    <textarea
                        wire:model="piTranscriptInput"
                        rows="3"
                        placeholder="Paste your Pi conversation transcript here…"
                        class="mt-2 w-full rounded-lg border border-line bg-surface px-2 py-1.5 text-sm text-ink dark:border-line-dark dark:bg-surface-dark dark:text-ink-dark"
                    ></textarea>
                    <button
                        type="button"
                        wire:click="submitPiTranscript"
                        wire:loading.attr="disabled"
                        wire:target="submitPiTranscript"
                        class="mt-2 cursor-pointer rounded-full border border-line px-3 py-1 text-xs font-semibold text-ink-soft transition-colors hover:bg-surface dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-dark disabled:pointer-events-none disabled:opacity-50"
                    >
                        <span wire:loading.remove wire:target="submitPiTranscript">Get feedback</span>
                        <span wire:loading wire:target="submitPiTranscript">Reading it…</span>
                    </button>
                @endif
            </div>

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
