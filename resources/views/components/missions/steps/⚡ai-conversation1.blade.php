<?php

use App\Models\Evidence;
use App\Models\MissionRun;
use App\Services\GeminiClient;
use App\Services\GroqClient;
use Illuminate\Http\UploadedFile;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public MissionRun $run;

    public bool $readOnly = false;

    public int $round = 0;

    /** @var array<int, array{question: string, answer: string, followup: string}> */
    public array $turns = [];

    public ?UploadedFile $audioFile = null;

    public bool $processing = false;

    public ?string $error = null;

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

        $this->validate([
            'audioFile' => ['required', 'file', 'extensions:webm,ogg,mp3,wav,m4a', 'max:20480'],
        ]);

        $this->processing = true;

        try {
            $answer = trim(app(GroqClient::class)->transcribe($this->audioFile->getRealPath()));

            $followup = trim(app(GeminiClient::class)->chat(
                [['role' => 'user', 'text' => "Interview question: \"{$this->currentQuestion}\"\nLearner's answer: \"{$answer}\""]],
                systemPrompt: 'You are a friendly English conversation partner interviewing '
                    .$this->run->learner->levelDescription().' about their daily '
                    ."life. Given the question you asked and the learner's transcribed spoken answer, reply with exactly "
                    .'ONE short, natural follow-up question (max 15 words) that shows you listened — no preamble, no '
                    .'quotation marks, just the question.'
                    .$this->run->aiToneGuidance()
            ));

            $this->turns[] = [
                'question' => $this->currentQuestion,
                'answer' => $answer,
                'followup' => $followup,
            ];

            $this->round++;
            $this->audioFile = null;

            if ($this->round >= count($this->questions)) {
                $this->finish();
            }
        } catch (Throwable $e) {
            $this->error = "Something went wrong talking to the AI Instructor: {$e->getMessage()}";
        } finally {
            $this->processing = false;
        }
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
        <p class="text-xs text-ink-faint dark:text-ink-faint-dark">The AI Instructor will ask each question out loud — answer out loud too. It'll ask one follow-up after each answer.</p>
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
            <button
                wire:click="proceed"
                class="cursor-pointer rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
            >
                Continue
            </button>
        </div>
    @elseif ($this->currentQuestion)
        <div class="rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
            @unless ($readOnly)
                <x-speak-on-change :text="$this->currentQuestion" :change-key="$round" />
            @endunless
            <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Question {{ $round + 1 }} of {{ count($this->questions) }}</p>
            <p class="mt-1 font-display text-lg font-bold text-ink dark:text-ink-dark">{{ $this->currentQuestion }}</p>

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

            @error('audioFile')
                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
            @enderror
            @if ($error)
                <p class="mt-2 text-sm text-red-600">{{ $error }}</p>
            @endif
        </div>
    @endif
</div>
