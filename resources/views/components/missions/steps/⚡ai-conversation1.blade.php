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

    /**
     * True once every question has been answered and Evidence is saved —
     * the step then shows a completion recap (including the final
     * follow-up, which would otherwise never be seen) before the learner
     * dismisses it with proceed() below, instead of navigating away the
     * instant the last answer lands.
     */
    public bool $completed = false;

    public function mount(): void
    {
        if (! $this->readOnly) {
            return;
        }

        $this->turns = json_decode($this->run->latestEvidence('ai_conversation_1')?->content_ref ?? '[]', true);
        $this->round = count($this->turns);
    }

    public function getQuestionsProperty(): array
    {
        return $this->run->mission->stepContent('ai_conversation_1')['interview_questions'] ?? [];
    }

    public function getCurrentQuestionProperty(): ?string
    {
        return $this->questions[$this->round] ?? null;
    }

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
                $this->finish();
            }
        } catch (Throwable $e) {
            $this->error = "Something went wrong talking to the AI Instructor: {$e->getMessage()}";
        } finally {
            $this->processing = false;
        }
    }

    /**
     * Offered only after 3 genuinely off-topic/empty attempts on the same
     * question — see TracksCheckAttempts. Shows an example, never fills
     * anything in for them: they still have to record their own answer.
     */
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

    private function finish(): void
    {
        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'ai_conversation_1',
            'type' => Evidence::TYPE_TRANSCRIPT,
            'content_ref' => json_encode($this->turns),
        ]);

        // Progress is already saved — this only decides what the learner
        // sees next: the recap (including the final follow-up), which they
        // dismiss with proceed() below.
        $this->completed = true;
    }

    public function proceed(): void
    {
        $this->redirect(route('missions.show', $this->run->mission), navigate: true);
    }
};
?>

@php $vocabularyWords = $run->selectedVocabularyWords(); @endphp

<div class="space-y-6">
    <x-hook :text="$run->mission->stepContent('ai_conversation_1')['hook'] ?? null" />

    <div>
        <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">AI Conversation #1</p>
        <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Answer each question out loud — tap "Read aloud" if you'd rather hear it than read it. It'll ask one follow-up after each answer.</p>
    </div>

    @if (! $readOnly && ! $completed)
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
    @endif

    @if (count($turns))
        <div class="space-y-3">
            @foreach ($turns as $turn)
                <x-conversation-turn :prompt="$turn['question']" :answer="$turn['answer']" :followup="$turn['followup']" />
            @endforeach
        </div>
    @endif

    @if ($completed)
        <div class="space-y-4 rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
            <div>
                <p class="inline-flex items-center gap-1 text-xs font-semibold tracking-wide text-success uppercase dark:text-success-dark">
                    @svg('heroicon-o-check-circle', 'h-4 w-4')
                    AI Conversation #1 complete
                </p>
                <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">Nicely done — take a look back at the conversation above before you move on.</p>
            </div>

            @if (count($this->questions))
                <div>
                    <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Want more practice? Ask each other all of these questions for real.</p>
                    <div class="mt-1.5">
                        <x-practice-session-with-friend :mission="$run->mission" step-key="ai_conversation_1" />
                    </div>
                </div>
            @endif

            <button
                wire:click="proceed"
                wire:loading.attr="disabled"
                wire:target="proceed"
                class="cursor-pointer rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 dark:bg-accent-dark"
            >
                <span wire:loading.remove wire:target="proceed">Continue</span>
                <span wire:loading wire:target="proceed">Saving…</span>
            </button>
        </div>
    @elseif ($this->currentQuestion)
        <div class="rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
            <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Question {{ $round + 1 }} of {{ count($this->questions) }}</p>
            <div class="mt-1 flex items-start justify-between gap-2">
                <p class="font-display text-lg font-bold text-ink dark:text-ink-dark">{{ $this->currentQuestion }}</p>
                @unless ($readOnly)
                    <x-speak-button :text="$this->currentQuestion" />
                @endunless
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

            @unless ($readOnly)
                <x-almost-reveal-notice
                    :show="($checkAttempts[$round] ?? 0) === 2"
                    label="One more try — after that I can suggest an example to help you get started."
                />
                <x-reveal-offer
                    :show="$offerReveal[$round] ?? false"
                    reveal-method="revealExample"
                    decline-method="declineExample"
                    :index="$round"
                    wire-target="submitAnswer,revealExample,declineExample"
                    label="Want an example to help you get started?"
                />
            @endunless

            @error('audioFile')
                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
            @enderror
            @if ($error)
                <p class="mt-2 text-sm text-red-600">{{ $error }}</p>
            @endif
        </div>
    @endif
</div>
