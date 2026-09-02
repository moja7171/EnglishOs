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

            $raw = app(GeminiClient::class)->chat(
                [['role' => 'user', 'text' => "Transcript: \"{$this->finalTranscript}\""]],
                systemPrompt: 'You are an English teacher checking the 3-minute speaking challenge transcript of '
                    .$this->run->learner->levelDescription()
                    ." against a requirements checklist.{$vocabularyContext} For each of these requirements: "
                    ."[{$requirementList}], decide if the transcript satisfies it. Reply with ONLY valid JSON, no "
                    .'markdown fences: {"requirements": {"<requirement label exactly as given>": true or false, '
                    .'...}, "note": "one short encouraging sentence about their overall performance"}'
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
            @unless ($readOnly)
                <x-speak-on-change :text="$this->currentRoundPrompt" :change-key="'round-'.$roundIndex" />
            @endunless
            <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Round {{ $roundIndex + 1 }} of {{ count($this->rounds) }}</p>
            <p class="mt-1 font-display text-lg font-bold text-ink dark:text-ink-dark">{{ $this->currentRoundPrompt }}</p>

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
        </div>
    @elseif (! $checklist)
        <div class="rounded-2xl border border-line bg-surface-sunken p-4 dark:border-line-dark dark:bg-surface-sunken-dark">
            @unless ($readOnly)
                <x-speak-on-change :text="$this->finalPrompt" change-key="final" />
            @endunless
            <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Final Challenge · Topic: My Daily Life</p>
            <p class="mt-1 font-display text-lg font-bold text-ink dark:text-ink-dark">{{ $this->finalPrompt }}</p>

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
                    class="cursor-pointer rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark">
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
