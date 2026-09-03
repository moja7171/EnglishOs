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
     * forever, not just this run). Loaded scoped to the whole RUN, not
     * just the current step — a Livewire full-page navigation (Next/
     * Previous between steps) tears down and remounts this component
     * from scratch either way, so scoping to just $stepKey used to make
     * an in-progress conversation visibly reset or jump to a different
     * (smaller) history the moment the learner navigated, even mid-chat.
     * step_key is still recorded per message (see recordAndRespond) —
     * only the *display* scope widened, for a continuous thread. See
     * systemPrompt() for how per-message step grounding still works
     * without that continuity constantly getting yanked around.
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
     * Voice is a dictation shortcut, not a separate "send" action —
     * record, auto-upload, transcribe, drop the transcript into the
     * question box so the learner can read it back and fix anything
     * before it actually goes anywhere. Nothing is sent, saved, or
     * shown to Sage here; a real send only ever happens through ask(),
     * same as if they'd typed it. The recording itself is discarded
     * either way (transcribed or not) — it was only ever a means to
     * fill the text box, never a message in its own right.
     */
    public function transcribeVoiceQuestion(): void
    {
        if (! $this->voiceQuestion) {
            return;
        }

        try {
            $question = trim(app(GroqClient::class)->transcribe($this->voiceQuestion->getRealPath()));
        } catch (Throwable) {
            $question = '';
        }

        $this->voiceQuestion = null;

        if ($question === '') {
            $this->error = "Couldn't hear that clearly — try again, or just type it.";

            return;
        }

        $this->error = null;
        $this->question = $question;
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

        // Real conversation memory — every prior turn (this run, any
        // step), not just the newest question in isolation. Capped so a
        // long-lived chat can't grow the prompt without bound; the most
        // recent turns matter far more than the first ones from days ago.
        $history = collect($this->messages)
            ->slice(-20)
            ->map(fn (array $m) => ['role' => $m['role'] === 'instructor' ? 'model' : 'user', 'text' => $m['text']])
            ->values()
            ->all();

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
                [...$history, ['role' => 'user', 'text' => $learnerText]],
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
            $this->error = "Couldn't reach Sage — please try again.";
        } catch (Throwable $e) {
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

        $prompt = 'Your name is Sage. You are a warm, genuinely fun AI English Instructor with real personality — '
            .'not a stiff textbook voice. Be playful, use a light joke or a vivid everyday example when it helps '
            .'something click, react like a real person would ("Ooh, good question!", "Ha, English is weird '
            .'about that one too"), and make the learner enjoy stopping by, not just tolerate it. Still concise '
            .'(a few short sentences, no long essays) and still substantive — charm never replaces a clear answer. '
            .'You are helping '.$this->run->learner->levelDescription().' who is in the middle of a lesson '
            .'(mission outcome: "'.$this->run->mission->outcome.'"'
            .($stepLabel ? ", currently viewing the \"{$stepLabel}\" step" : '').'). '
            .'Use that step as background for a NEW question, but never let it override an ongoing conversation: '
            .'if this question is clearly a continuation of what you were just discussing, stay on that thread — '
            .'the learner may have simply navigated to a different step mid-chat, which is not a signal to change '
            .'the subject on them. If they ask you to just give them the answer to the exercise they are '
            .'currently working on, politely decline and explain the underlying grammar or vocabulary rule in '
            .'general terms instead, so they can work out the specific answer themselves — never solve their '
            .'current exercise for them. If the question has nothing to do with English or this lesson, gently '
            .'steer them back to the topic — warmly, not like a scold. If they mention or attach a file, you '
            .'cannot see its contents — kindly ask them to describe it or paste the relevant text directly in '
            .'the chat.';

        return $prompt.' '.$this->run->aiToneGuidance();
    }
};
?>

{{--
    A floating chat widget rather than an inline accordion at the bottom of
    the step — the accordion was easy to scroll past and lose track of.
    The trigger is a fixed round icon in the corner, reachable from
    anywhere on the step; opening it reveals the full persisted history
    for this step as a real chat thread (same bubble/wallpaper structure
    as the Friends conversation page), not just the latest exchange.
--}}
<div
    x-data="{
        open: false,
        init() {
            this.observer = new MutationObserver(() => this.scrollToBottom());
            this.observer.observe(this.$refs.messages, { childList: true, subtree: true });
        },
        scrollToBottom() {
            this.$nextTick(() => {
                if (this.$refs.messages) this.$refs.messages.scrollTop = this.$refs.messages.scrollHeight;
            });
        },
    }"
>
    <button
        type="button"
        x-on:click="open = !open; if (open) $nextTick(() => scrollToBottom())"
        title="Sage — your AI Instructor"
        class="fixed right-5 bottom-5 z-40 inline-flex h-14 w-14 cursor-pointer items-center justify-center rounded-full bg-accent text-white shadow-lg transition-transform hover:scale-105 active:scale-95 dark:bg-accent-dark"
    >
        <span x-show="!open">@svg('heroicon-o-sparkles', 'h-6 w-6')</span>
        <span x-show="open" x-cloak>@svg('heroicon-o-x-mark', 'h-6 w-6')</span>
    </button>

    <div
        x-show="open"
        x-cloak
        x-transition.opacity.duration.150ms
        x-on:click.outside="open = false"
        class="fixed right-5 bottom-24 z-40 flex h-[28rem] max-h-[calc(100vh-8rem)] w-[23rem] max-w-[calc(100vw-2.5rem)] flex-col overflow-hidden rounded-2xl border border-line bg-surface shadow-2xl dark:border-line-dark dark:bg-surface-dark"
    >
        <div class="flex shrink-0 items-center gap-2.5 border-b border-line px-4 py-3 dark:border-line-dark">
            <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">
                @svg('heroicon-o-sparkles', 'h-4 w-4')
            </span>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-ink dark:text-ink-dark">Sage</p>
                <p class="truncate text-[11px] text-ink-faint dark:text-ink-faint-dark">Ask me anything about English — I'll explain, never just solve it for you.</p>
            </div>
            <button
                type="button"
                x-on:click="open = false"
                title="Close"
                class="inline-flex h-7 w-7 shrink-0 cursor-pointer items-center justify-center rounded-full text-ink-faint transition-colors hover:bg-surface-sunken hover:text-ink dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark dark:hover:text-ink-dark"
            >@svg('heroicon-o-x-mark', 'h-4 w-4')</button>
        </div>

        <div
            x-ref="messages"
            class="flex-1 space-y-2 overflow-y-auto p-3"
            style="background-color: var(--color-surface-sunken); background-image: radial-gradient(color-mix(in srgb, var(--color-ink) 10%, transparent) 1px, transparent 1px); background-size: 18px 18px;"
        >
            @forelse ($messages as $message)
                @php $mine = $message['role'] === 'learner'; @endphp
                <div class="flex {{ $mine ? 'justify-end' : 'justify-start' }}">
                    <div class="max-w-[85%] rounded-2xl px-3 py-2 text-sm shadow-sm
                        {{ $mine
                            ? 'rounded-br-sm bg-accent text-white dark:bg-accent-dark'
                            : 'rounded-bl-sm border border-line bg-surface text-ink dark:border-line-dark dark:bg-surface-dark dark:text-ink-dark' }}">
                        @if ($message['type'] === 'voice')
                            <x-audio-player-compact :url="route('instructor.attachment', $message['id'])" :mine="$mine" />
                            @if ($message['text'] !== "Couldn't transcribe this recording.")
                                <p class="mt-1.5 break-words {{ $mine ? 'text-white' : 'text-ink dark:text-ink-dark' }}">{{ $message['text'] }}</p>
                            @endif
                        @else
                            @if ($message['type'] === 'file')
                                <p class="mb-1 inline-flex items-center gap-1 text-xs {{ $mine ? 'text-white/75' : 'text-ink-faint dark:text-ink-faint-dark' }}">
                                    @svg('heroicon-o-paper-clip', 'h-3 w-3')
                                    <a href="{{ route('instructor.attachment', $message['id']) }}" class="underline decoration-dotted underline-offset-2">{{ $message['attachmentName'] }}</a>
                                </p>
                            @endif
                            <span class="break-words">{{ $message['text'] }}</span>
                        @endif
                    </div>
                </div>
            @empty
                <div class="flex h-full flex-col items-center justify-center gap-2 px-4 text-center">
                    @svg('heroicon-o-sparkles', 'h-6 w-6 text-ink-faint/50 dark:text-ink-faint-dark/50')
                    <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Ask about a word, a grammar rule, or anything else about English — by typing, by voice, or with a file attached.</p>
                </div>
            @endforelse

            <div wire:loading.delay wire:target="ask,transcribeVoiceQuestion,sendFile">
                <x-ai-thinking label="Sage is answering…" class="bg-surface dark:bg-surface-dark" />
            </div>
        </div>

        <div class="shrink-0 space-y-2 border-t border-line p-2 dark:border-line-dark">
            @if ($error)
                <p class="px-1 text-xs text-red-600">{{ $error }}</p>
            @endif
            @error('fileAttachment')
                <p class="px-1 text-xs text-red-600">{{ $message }}</p>
            @enderror

            @if ($fileAttachment)
                <div class="flex items-center gap-2 px-1">
                    <span class="truncate text-xs text-ink-faint dark:text-ink-faint-dark">{{ $fileAttachment->getClientOriginalName() }}</span>
                    <button
                        type="button"
                        wire:click="sendFile"
                        wire:loading.attr="disabled"
                        wire:target="ask,transcribeVoiceQuestion,sendFile"
                        class="shrink-0 cursor-pointer rounded-full bg-accent px-3 py-1 text-xs font-semibold text-white transition-colors hover:opacity-90 disabled:pointer-events-none disabled:opacity-50 dark:bg-accent-dark"
                    >Send file</button>
                </div>
            @endif

            <div class="flex items-center gap-1">
                <label title="Attach a file" class="inline-flex h-9 w-9 shrink-0 cursor-pointer items-center justify-center rounded-full text-ink-faint transition-colors hover:bg-surface-sunken hover:text-ink dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark dark:hover:text-ink-dark">
                    @svg('heroicon-o-paper-clip', 'h-4 w-4')
                    <input type="file" wire:model="fileAttachment" class="hidden">
                </label>

                <form wire:submit="ask" class="flex flex-1 items-center gap-1.5">
                    <input
                        type="text"
                        wire:model="question"
                        placeholder="Ask a question…"
                        wire:loading.attr="disabled"
                        wire:target="ask,transcribeVoiceQuestion,sendFile"
                        class="w-full rounded-full border border-line bg-transparent px-3 py-1.5 text-sm text-ink disabled:opacity-50 dark:border-line-dark dark:text-ink-dark"
                    >
                    <button
                        type="submit"
                        title="Send"
                        wire:loading.attr="disabled"
                        wire:target="ask,transcribeVoiceQuestion,sendFile"
                        class="inline-flex h-9 w-9 shrink-0 cursor-pointer items-center justify-center rounded-full bg-accent text-white transition-colors hover:opacity-90 disabled:pointer-events-none disabled:opacity-50 dark:bg-accent-dark"
                    >@svg('heroicon-s-paper-airplane', 'h-4 w-4')</button>
                </form>

                <div wire:key="ask-voice-recorder-{{ count($messages) }}" class="shrink-0">
                    <x-voice-recorder field="voiceQuestion" :file="$voiceQuestion" on-recorded="transcribeVoiceQuestion" file-name="question.webm" :compact="true" />
                </div>
            </div>
        </div>
    </div>
</div>
