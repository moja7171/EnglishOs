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
                systemPrompt: 'You are a friendly English conversation partner interviewing a B1 learner about their daily '
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

        $this->redirect(route('missions.show', $this->run->mission), navigate: true);
    }
};
?>

<div
    class="space-y-6"
    x-data="{
        recording: false,
        seconds: 0,
        timer: null,
        mediaRecorder: null,
        chunks: [],
        async startRecording() {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            this.chunks = [];
            this.mediaRecorder = new MediaRecorder(stream);
            this.mediaRecorder.ondataavailable = (e) => { if (e.data.size > 0) this.chunks.push(e.data); };
            this.mediaRecorder.onstop = () => {
                stream.getTracks().forEach((t) => t.stop());
                const blob = new Blob(this.chunks, { type: 'audio/webm' });
                const file = new File([blob], 'answer.webm', { type: 'audio/webm' });
                this.$wire.upload('audioFile', file, () => this.$wire.call('submitAnswer'));
            };
            this.mediaRecorder.start();
            this.recording = true;
            this.seconds = 0;
            this.timer = setInterval(() => { this.seconds++; }, 1000);
        },
        stopRecording() {
            this.mediaRecorder.stop();
            this.recording = false;
            clearInterval(this.timer);
        },
    }"
>
    <x-hook :text="$run->mission->stepContent('ai_conversation_1')['hook'] ?? null" />

    <div>
        <p class="text-xs font-semibold tracking-wide text-neutral-500 uppercase">AI Conversation #1</p>
        <p class="text-xs text-neutral-500">Answer each question out loud. The AI Instructor will ask one follow-up.</p>
    </div>

    @if (count($turns))
        <div class="space-y-3">
            @foreach ($turns as $turn)
                <x-conversation-turn :prompt="$turn['question']" :answer="$turn['answer']" :followup="$turn['followup']" />
            @endforeach
        </div>
    @endif

    @if ($this->currentQuestion)
        <div class="rounded-lg border border-neutral-300 p-4 dark:border-neutral-700">
            <p class="text-xs text-neutral-500">Question {{ $round + 1 }} of {{ count($this->questions) }}</p>
            <p class="mt-1 text-lg font-bold">{{ $this->currentQuestion }}</p>

            <div class="mt-3 flex items-center gap-3" wire:loading.remove wire:target="submitAnswer">
                <button
                    type="button"
                    x-show="!recording"
                    x-on:click="startRecording"
                    class="rounded-full bg-red-600 px-4 py-2 text-sm font-semibold text-white"
                >● Record</button>

                <button
                    type="button"
                    x-show="recording"
                    x-on:click="stopRecording"
                    class="rounded-full bg-neutral-900 px-4 py-2 text-sm font-semibold text-white dark:bg-white dark:text-neutral-900"
                >■ Stop (<span x-text="seconds"></span>s)</button>
            </div>

            <p wire:loading wire:target="submitAnswer" class="mt-3 text-sm text-neutral-500">
                Transcribing and thinking of a follow-up…
            </p>

            @error('audioFile')
                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
            @enderror
            @if ($error)
                <p class="mt-2 text-sm text-red-600">{{ $error }}</p>
            @endif
        </div>
    @endif
</div>
