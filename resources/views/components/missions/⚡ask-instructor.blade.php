<?php

use App\Models\MissionRun;
use App\Services\GeminiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Livewire\Component;

new class extends Component
{
    public MissionRun $run;

    public ?string $stepKey = null;

    public string $question = '';

    /**
     * In-memory only for this component instance — never written to the
     * DB, never touches Evidence. Every step navigation (wire:navigate to
     * a new URL) fully remounts this component, so the conversation is
     * naturally scoped to "however long the learner stays on one step" —
     * a deliberate v1 choice (see EOS-009's "AI as a real teacher"
     * backlog, Story 7): a persisted cross-step thread is a bigger
     * feature than a quick side-question deserves right now.
     *
     * @var array<int, array{role: string, text: string}>
     */
    public array $messages = [];

    public bool $loading = false;

    public ?string $error = null;

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

        $this->error = null;
        $this->loading = true;
        $this->messages[] = ['role' => 'learner', 'text' => $question];
        $this->question = '';

        try {
            $answer = app(GeminiClient::class)->chat(
                [['role' => 'user', 'text' => $question]],
                systemPrompt: $this->systemPrompt(),
            );

            $this->messages[] = ['role' => 'instructor', 'text' => trim($answer)];
        } catch (ConnectionException|RequestException) {
            $this->error = "Couldn't reach the AI Instructor — please try again.";
        } catch (\Throwable $e) {
            $this->error = "Couldn't get an answer: {$e->getMessage()}";
        } finally {
            $this->loading = false;
        }
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
            .'them back to the topic.';

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
            Ask about a word, a grammar rule, or anything else about English — I won't give you the answer to this exercise, but I'm happy to explain the rule behind it.
        </p>

        @if (count($messages))
            <div class="space-y-2">
                @foreach ($messages as $message)
                    <div class="{{ $message['role'] === 'learner' ? 'ml-6' : 'mr-6' }}">
                        <p class="rounded-xl px-3 py-2 text-sm {{ $message['role'] === 'learner'
                            ? 'bg-surface-sunken text-ink dark:bg-surface-sunken-dark dark:text-ink-dark'
                            : 'bg-accent-soft text-ink dark:bg-accent-soft-dark dark:text-ink-dark' }}">
                            {{ $message['text'] }}
                        </p>
                    </div>
                @endforeach
            </div>
        @endif

        <div wire:loading.delay wire:target="ask">
            <x-ai-thinking label="The AI Instructor is answering…" />
        </div>

        @if ($error)
            <p class="text-sm text-red-600">{{ $error }}</p>
        @endif

        <form wire:submit="ask" class="flex items-center gap-2">
            <input
                type="text"
                wire:model="question"
                placeholder="Ask a question…"
                wire:loading.attr="disabled"
                wire:target="ask"
                class="w-full rounded-lg border border-line bg-transparent px-2 py-1.5 text-sm text-ink disabled:opacity-50 dark:border-line-dark dark:text-ink-dark"
            >
            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="ask"
                class="shrink-0 cursor-pointer rounded-full bg-accent px-3 py-1.5 text-xs font-semibold text-white transition-colors hover:opacity-90 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 dark:bg-accent-dark"
            >Ask</button>
        </form>
    </div>
</div>
