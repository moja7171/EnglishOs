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

    public int $roundIndex = 0;

    /** @var array<int, array{prompt: string, answer: string, followup: string}> */
    public array $turns = [];

    public ?string $finalTranscript = null;

    /** @var array<string, bool>|null */
    public ?array $checklist = null;

    public ?string $checklistNote = null;

    public ?UploadedFile $audioFile = null;

    public bool $processing = false;

    public ?string $error = null;

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

        $this->validate(['audioFile' => ['required', 'file', 'extensions:webm,ogg,mp3,wav,m4a', 'max:20480']]);

        $this->processing = true;

        try {
            $answer = trim(app(GroqClient::class)->transcribe($this->audioFile->getRealPath()));

            $followup = trim(app(GeminiClient::class)->chat(
                [['role' => 'user', 'text' => "Prompt: \"{$this->currentRoundPrompt}\"\nLearner's spoken response: \"{$answer}\""]],
                systemPrompt: 'You are a friendly English conversation partner. Given the prompt and the '
                    .'learner\'s transcribed spoken response, reply with exactly ONE short, natural reaction or '
                    .'follow-up question (max 15 words) that shows you listened — no preamble, no quotation marks.'
                    .$this->run->aiToneGuidance()
            ));

            $this->turns[] = ['prompt' => $this->currentRoundPrompt, 'answer' => $answer, 'followup' => $followup];
            $this->roundIndex++;
            $this->audioFile = null;
        } catch (\Throwable $e) {
            $this->error = "Something went wrong talking to the AI Instructor: {$e->getMessage()}";
        } finally {
            $this->processing = false;
        }
    }

    public function submitFinalChallenge(): void
    {
        $this->error = null;

        $this->validate(['audioFile' => ['required', 'file', 'extensions:webm,ogg,mp3,wav,m4a', 'max:20480']]);

        $this->processing = true;

        try {
            $this->finalTranscript = trim(app(GroqClient::class)->transcribe($this->audioFile->getRealPath()));

            $requirementList = collect($this->requirements)->map(fn ($r) => "\"{$r}\"")->implode(', ');

            $raw = app(GeminiClient::class)->chat(
                [['role' => 'user', 'text' => "Transcript: \"{$this->finalTranscript}\""]],
                systemPrompt: 'You are an English teacher checking a B1 learner\'s 3-minute speaking challenge transcript '
                    ."against a requirements checklist. For each of these requirements: [{$requirementList}], decide if the "
                    .'transcript satisfies it. Reply with ONLY valid JSON, no markdown fences: {"requirements": '
                    .'{"<requirement label exactly as given>": true or false, ...}, "note": "one short encouraging '
                    .'sentence about their overall performance"}'
            );

            $data = json_decode(trim($raw), true);

            if (! is_array($data) || ! isset($data['requirements'], $data['note'])) {
                throw new RuntimeException('Unexpected AI response format.');
            }

            $this->checklist = $data['requirements'];
            $this->checklistNote = $data['note'];
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
            ]),
        ]);

        $this->redirect(route('missions.show', $this->run->mission));
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
        async startRecording(action) {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            this.chunks = [];
            this.mediaRecorder = new MediaRecorder(stream);
            this.mediaRecorder.ondataavailable = (e) => { if (e.data.size > 0) this.chunks.push(e.data); };
            this.mediaRecorder.onstop = () => {
                stream.getTracks().forEach((t) => t.stop());
                const blob = new Blob(this.chunks, { type: 'audio/webm' });
                const file = new File([blob], 'answer.webm', { type: 'audio/webm' });
                this.$wire.upload('audioFile', file, () => this.$wire.call(action));
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
    <x-hook :text="$run->mission->stepContent('ai_conversation_2')['hook'] ?? null" />

    <div>
        <p class="text-xs font-semibold tracking-wide text-neutral-500 uppercase">AI Conversation #2 — Final Challenge</p>
        <p class="text-xs text-neutral-500">This session should be harder than the first one.</p>
    </div>

    @if (count($turns))
        <div class="space-y-3">
            @foreach ($turns as $turn)
                <div class="rounded border border-neutral-300 p-3 text-sm dark:border-neutral-700">
                    <p class="font-semibold">{{ $turn['prompt'] }}</p>
                    <p class="mt-1 text-neutral-600 dark:text-neutral-400">You: {{ $turn['answer'] }}</p>
                    <p class="mt-1 text-neutral-500 italic">AI Instructor: {{ $turn['followup'] }}</p>
                </div>
            @endforeach
        </div>
    @endif

    @if (! $this->inFinalStage)
        <div class="rounded-lg border border-neutral-300 p-4 dark:border-neutral-700">
            <p class="text-xs text-neutral-500">Round {{ $roundIndex + 1 }} of {{ count($this->rounds) }}</p>
            <p class="mt-1 text-lg font-bold">{{ $this->currentRoundPrompt }}</p>

            <div class="mt-3 flex items-center gap-3" wire:loading.remove wire:target="submitRoundAnswer">
                <button type="button" x-show="!recording" x-on:click="startRecording('submitRoundAnswer')"
                    class="rounded-full bg-red-600 px-4 py-2 text-sm font-semibold text-white">● Record</button>
                <button type="button" x-show="recording" x-on:click="stopRecording"
                    class="rounded-full bg-neutral-900 px-4 py-2 text-sm font-semibold text-white dark:bg-white dark:text-neutral-900">■ Stop (<span x-text="seconds"></span>s)</button>
            </div>

            <p wire:loading wire:target="submitRoundAnswer" class="mt-3 text-sm text-neutral-500">Transcribing…</p>
        </div>
    @elseif (! $checklist)
        <div class="rounded-lg border-2 border-neutral-900 bg-neutral-50 p-4 dark:border-white dark:bg-neutral-900">
            <p class="text-xs text-neutral-500">Final Challenge · Topic: My Daily Life</p>
            <p class="mt-1 text-lg font-bold">{{ $this->finalPrompt }}</p>

            <div class="mt-3 flex items-center gap-3" wire:loading.remove wire:target="submitFinalChallenge">
                <button type="button" x-show="!recording" x-on:click="startRecording('submitFinalChallenge')"
                    class="rounded-full bg-red-600 px-4 py-2 text-sm font-semibold text-white">● Record</button>
                <button type="button" x-show="recording" x-on:click="stopRecording"
                    class="rounded-full bg-neutral-900 px-4 py-2 text-sm font-semibold text-white dark:bg-white dark:text-neutral-900">■ Stop (<span x-text="seconds"></span>s)</button>
            </div>

            <p wire:loading wire:target="submitFinalChallenge" class="mt-3 text-sm text-neutral-500">
                Checking your answer against the requirements…
            </p>
        </div>
    @else
        <div class="space-y-3">
            <p class="text-sm font-semibold">Requirements</p>
            <div class="grid gap-2 sm:grid-cols-2">
                @foreach ($this->requirements as $requirement)
                    <div class="flex items-center gap-2 text-sm">
                        <span>{{ ($checklist[$requirement] ?? false) ? '✅' : '⬜️' }}</span>
                        <span>{{ $requirement }}</span>
                    </div>
                @endforeach
            </div>
            <p class="text-sm text-neutral-600 dark:text-neutral-400">{{ $checklistNote }}</p>

            @unless ($readOnly)
                <button wire:click="finishConversation"
                    class="rounded bg-neutral-900 px-4 py-2 text-sm font-semibold text-white dark:bg-white dark:text-neutral-900">
                    Continue
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
