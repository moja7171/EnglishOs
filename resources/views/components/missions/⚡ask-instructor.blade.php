<?php

use App\Models\InstructorMessage;
use App\Models\MissionRun;
use App\Services\GeminiClient;
use App\Services\GroqClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public MissionRun $run;

    public ?string $stepKey = null;

    public string $question = '';

    public ?UploadedFile $voiceQuestion = null;

    public ?UploadedFile $fileAttachment = null;

    /**
     * Persisted (see InstructorMessage — scoped to the learner, kept
     * forever, not just this run) so a future feature can mine everything
     * a learner has ever asked, not just what this one panel shows right
     * now. Reloaded on mount() scoped to THIS run+step, so returning to a
     * step already asked about shows that history again — but the wider,
     * cross-step/cross-mission record lives in the table regardless of
     * what any one visit displays.
     *
     * @var array<int, array{id: int, role: string, text: string, type: string, attachmentName: ?string}>
     */
    public array $messages = [];

    public bool $loading = false;

    public ?string $error = null;

    public function mount(): void
    {
        $this->messages = InstructorMessage::query()
            ->where('learner_id', auth()->id())
            ->where('mission_run_id', $this->run->id)
            ->where('step_key', $this->stepKey)
            ->orderBy('created_at')
            ->get()
            ->map(fn (InstructorMessage $m) => $this->toDisplay($m))
            ->all();
    }

    /**
     * Free-form Q&A, deliberately kept OUTSIDE Evidence Before Progress
     * (Article 12, Independence): this never grades anything, never
     * blocks or advances the mission, and the AI is explicitly told not
     * to just hand over the answer to whatever exercise the learner is
     * currently on — it can explain the underlying rule, never solve the
     * specific step for them.
     */
    public function ask(): void
    {
        $question = trim($this->question);

        if ($question === '') {
            return;
        }

        $this->question = '';

        $this->recordAndRespond($question, InstructorMessage::TYPE_TEXT);
    }

    /**
     * Same idea as every other step's voice input: record, auto-upload,
     * transcribe — the transcript becomes the question text. Non-blocking
     * on a transcription failure, same as everywhere else, but here there
     * is nothing useful to fall back to, so it just asks the learner to
     * try again rather than silently asking an empty question.
     */
    public function askWithVoice(): void
    {
        if (! $this->voiceQuestion) {
            return;
        }

        $path = $this->voiceQuestion->store('instructor-messages/'.auth()->id(), 'local');

        try {
            $question = trim(app(GroqClient::class)->transcribe($this->voiceQuestion->getRealPath()));
        } catch (\Throwable) {
            $question = '';
        }

        $this->voiceQuestion = null;

        if ($question === '') {
            $this->error = "Couldn't hear that clearly — please try again.";

            return;
        }

        $this->recordAndRespond($question, InstructorMessage::TYPE_VOICE, $path, 'question.webm', 'audio/webm');
    }

    public function sendFile(): void
    {
        $this->error = null;

        $this->validate([
            'fileAttachment' => ['required', 'file', 'max:15360', 'extensions:pdf,doc,docx,txt,jpg,jpeg,png,webp,gif,mp3,wav,m4a,webm'],
        ]);

        $path = $this->fileAttachment->store('instructor-messages/'.auth()->id(), 'local');
        $name = $this->fileAttachment->getClientOriginalName();
        $mime = $this->fileAttachment->getMimeType();
        $this->fileAttachment = null;

        $this->recordAndRespond("Attached a file: {$name}", InstructorMessage::TYPE_FILE, $path, $name, $mime);
    }

    private function recordAndRespond(
        string $learnerText,
        string $type,
        ?string $attachmentPath = null,
        ?string $attachmentName = null,
        ?string $attachmentMime = null,
    ): void {
        $this->error = null;
        $this->loading = true;

        $learnerMessage = InstructorMessage::create([
            'learner_id' => auth()->id(),
            'mission_run_id' => $this->run->id,
            'step_key' => $this->stepKey,
            'role' => InstructorMessage::ROLE_LEARNER,
            'body' => $learnerText,
            'type' => $type,
            'attachment_path' => $attachmentPath,
            'attachment_name' => $attachmentName,
            'attachment_mime' => $attachmentMime,
        ]);

        $this->messages[] = $this->toDisplay($learnerMessage);

        try {
            $answer = trim(app(GeminiClient::class)->chat(
                [['role' => 'user', 'text' => $learnerText]],
                systemPrompt: $this->systemPrompt(),
            ));

            $instructorMessage = InstructorMessage::create([
                'learner_id' => auth()->id(),
                'mission_run_id' => $this->run->id,
                'step_key' => $this->stepKey,
                'role' => InstructorMessage::ROLE_INSTRUCTOR,
                'body' => $answer,
                'type' => InstructorMessage::TYPE_TEXT,
            ]);

            $this->messages[] = $this->toDisplay($instructorMessage);
        } catch (ConnectionException|RequestException) {
            $this->error = "Couldn't reach the AI Instructor — please try again.";
        } catch (\Throwable $e) {
            $this->error = "Couldn't get an answer: {$e->getMessage()}";
        } finally {
            $this->loading = false;
        }
    }

    /**
     * @return array{id: int, role: string, text: string, type: string, attachmentName: ?string}
     */
    private function toDisplay(InstructorMessage $message): array
    {
        return [
            'id' => $message->id,
            'role' => $message->role,
            'text' => $message->body,
            'type' => $message->type,
            'attachmentName' => $message->attachment_name,
        ];
    }

    private function systemPrompt(): string
    {
        $stepLabel = $this->stepKey ? $this->run->mission->stepLabel($this->stepKey) : null;

        $prompt = 'You are a friendly, encouraging AI English Instructor helping '.$this->run->learner->levelDescription()
            .' who is in the middle of a lesson (mission outcome: "'.$this->run->mission->outcome.'"'
            .($stepLabel ? ", currently on the \"{$stepLabel}\" step" : '').'). '
            .'Answer their English-related question clearly, simply, and briefly (a few short sentences, no '
            .'long essays). If they ask you to just give them the answer to the exercise they are currently '
            .'working on, politely decline and explain the underlying grammar or vocabulary rule in general '
            .'terms instead, so they can work out the specific answer themselves — never solve their current '
            .'exercise for them. If the question has nothing to do with English or this lesson, gently steer '
            .'them back to the topic. If they mention or attach a file, you cannot see its contents — kindly '
            .'ask them to describe it or paste the relevant text directly in the chat.';

        return $prompt.' '.$this->run->aiToneGuidance();
    }
};
?>

<div x-data="{ open: false }" class="rounded-2xl border border-line dark:border-line-dark">
    <button
        type="button"
        x-on:click="open = !open"
        class="flex w-full cursor-pointer items-center justify-between gap-2 px-4 py-3 text-left text-sm font-semibold text-ink-soft transition-colors hover:text-ink dark:text-ink-soft-dark dark:hover:text-ink-dark"
    >
        <span class="inline-flex items-center gap-2">
            @svg('heroicon-o-question-mark-circle', 'h-4 w-4')
            Ask the AI Instructor
        </span>
        <span x-text="open ? '−' : '+'" class="text-ink-faint dark:text-ink-faint-dark"></span>
    </button>

    <div x-show="open" x-cloak x-transition.opacity.duration.200ms class="space-y-3 border-t border-line px-4 py-3 dark:border-line-dark">
        <p class="text-xs text-ink-faint dark:text-ink-faint-dark">
            Ask about a word, a grammar rule, or anything else about English — by typing, by voice, or with a file attached. I won't give you the answer to this exercise, but I'm happy to explain the rule behind it.
        </p>

        @if (count($messages))
            <div class="space-y-2">
                @foreach ($messages as $message)
                    <div class="{{ $message['role'] === 'learner' ? 'ml-6' : 'mr-6' }}">
                        <div class="rounded-xl px-3 py-2 text-sm {{ $message['role'] === 'learner'
                            ? 'bg-surface-sunken text-ink dark:bg-surface-sunken-dark dark:text-ink-dark'
                            : 'bg-accent-soft text-ink dark:bg-accent-soft-dark dark:text-ink-dark' }}">
                            @if ($message['type'] === 'voice')
                                <p class="mb-1 inline-flex items-center gap-1 text-xs text-ink-faint dark:text-ink-faint-dark">
                                    @svg('heroicon-o-microphone', 'h-3.5 w-3.5') Sent by voice
                                </p>
                            @elseif ($message['type'] === 'file')
                                <p class="mb-1 inline-flex items-center gap-1 text-xs text-ink-faint dark:text-ink-faint-dark">
                                    @svg('heroicon-o-paper-clip', 'h-3.5 w-3.5')
                                    <a href="{{ route('instructor.attachment', $message['id']) }}" class="underline decoration-dotted underline-offset-2">{{ $message['attachmentName'] }}</a>
                                </p>
                            @endif
                            {{ $message['text'] }}
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        <div wire:loading.delay wire:target="ask,askWithVoice,sendFile">
            <x-ai-thinking label="The AI Instructor is answering…" />
        </div>

        @if ($error)
            <p class="text-sm text-red-600">{{ $error }}</p>
        @endif
        @error('fileAttachment')
            <p class="text-sm text-red-600">{{ $message }}</p>
        @enderror

        <form wire:submit="ask" class="flex items-center gap-2">
            <input
                type="text"
                wire:model="question"
                placeholder="Ask a question…"
                wire:loading.attr="disabled"
                wire:target="ask,askWithVoice,sendFile"
                class="w-full rounded-lg border border-line bg-transparent px-2 py-1.5 text-sm text-ink disabled:opacity-50 dark:border-line-dark dark:text-ink-dark"
            >
            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="ask,askWithVoice,sendFile"
                class="shrink-0 cursor-pointer rounded-full bg-accent px-3 py-1.5 text-xs font-semibold text-white transition-colors hover:opacity-90 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 dark:bg-accent-dark"
            >Ask</button>
        </form>

        <div class="flex flex-wrap items-center gap-2">
            <div wire:key="ask-voice-recorder-{{ count($messages) }}">
                <x-voice-recorder field="voiceQuestion" :file="$voiceQuestion" on-recorded="askWithVoice" file-name="question.webm" />
            </div>

            <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-line px-3 py-1.5 text-xs font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">
                @svg('heroicon-o-paper-clip', 'h-3.5 w-3.5')
                <span wire:loading.remove wire:target="fileAttachment">Attach a file</span>
                <span wire:loading wire:target="fileAttachment">Uploading…</span>
                <input type="file" wire:model="fileAttachment" class="hidden">
            </label>

            @if ($fileAttachment)
                <span class="text-xs text-ink-faint dark:text-ink-faint-dark">{{ $fileAttachment->getClientOriginalName() }}</span>
                <button
                    type="button"
                    wire:click="sendFile"
                    wire:loading.attr="disabled"
                    wire:target="ask,askWithVoice,sendFile"
                    class="cursor-pointer rounded-full bg-accent px-3 py-1.5 text-xs font-semibold text-white transition-colors hover:opacity-90 disabled:pointer-events-none disabled:opacity-50 dark:bg-accent-dark"
                >Send file</button>
            @endif
        </div>
    </div>
</div>
