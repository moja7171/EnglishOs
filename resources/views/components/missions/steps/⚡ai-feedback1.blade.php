<?php

use App\Models\AIFeedback;
use App\Models\Evidence;
use App\Models\MissionRun;
use App\Services\GeminiClient;
use Livewire\Component;

new class extends Component
{
    public MissionRun $run;

    public bool $readOnly = false;

    public ?string $strength = null;

    public ?string $expression = null;

    public ?string $correction = null;

    public ?string $error = null;

    public bool $loading = false;

    public function mount(): void
    {
        if ($this->readOnly) {
            $data = json_decode($this->run->latestEvidence('ai_feedback_1')?->content_ref ?? '{}', true);
            $this->strength = $data['strength'] ?? null;
            $this->expression = $data['expression'] ?? null;
            $this->correction = $data['correction'] ?? null;

            return;
        }

        $this->generate();
    }

    public function generate(): void
    {
        $this->error = null;
        $this->loading = true;

        try {
            $turns = json_decode($this->conversationEvidence()?->content_ref ?? '[]', true) ?? [];

            $transcript = collect($turns)
                ->map(fn ($t) => "Q: {$t['question']}\nA: {$t['answer']}")
                ->implode("\n\n");

            $raw = app(GeminiClient::class)->chat(
                [['role' => 'user', 'text' => $transcript]],
                systemPrompt: "You are an encouraging English teacher reviewing a B1 learner's spoken interview answers. "
                    .'Reply with ONLY valid JSON, no markdown fences, no extra text, in exactly this shape: '
                    .'{"strength": "one specific thing they did well, one sentence", '
                    .'"expression": "one good word or phrase they actually used", '
                    .'"correction": "one grammar or vocabulary mistake to fix, one sentence, phrased kindly"}'
            );

            $data = json_decode(trim($raw), true);

            if (! is_array($data) || ! isset($data['strength'], $data['expression'], $data['correction'])) {
                throw new RuntimeException('Unexpected AI response format.');
            }

            $this->strength = $data['strength'];
            $this->expression = $data['expression'];
            $this->correction = $data['correction'];
        } catch (Throwable $e) {
            $this->error = "Couldn't get feedback from the AI Instructor: {$e->getMessage()}";
        } finally {
            $this->loading = false;
        }
    }

    public function continueMission(): void
    {
        if (! $this->strength) {
            return;
        }

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'ai_feedback_1',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode([
                'strength' => $this->strength,
                'expression' => $this->expression,
                'correction' => $this->correction,
            ]),
        ]);

        if ($conversationEvidence = $this->conversationEvidence()) {
            AIFeedback::create([
                'evidence_id' => $conversationEvidence->id,
                'strength' => $this->strength,
                'correction' => $this->correction,
                'tone' => 'encouraging',
            ]);
        }

        $this->redirect(route('missions.show', $this->run->mission), navigate: true);
    }

    private function conversationEvidence(): ?Evidence
    {
        return $this->run->evidence()->where('phase', 'ai_conversation_1')->latest()->first();
    }
};
?>

<div class="space-y-6">
    <x-hook :text="$run->mission->stepContent('ai_feedback_1')['hook'] ?? null" />

    <div>
        <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">AI Feedback #1</p>
        <p class="text-xs text-ink-faint dark:text-ink-faint-dark">A quick review of your conversation from the AI Instructor.</p>
    </div>

    @if ($loading)
        <p class="text-sm text-ink-faint dark:text-ink-faint-dark">Reading your answers…</p>
    @elseif ($error)
        <div class="rounded-xl border border-red-300 p-3 text-sm text-red-600">
            {{ $error }}
            <button wire:click="generate" class="mt-2 block underline">Try again</button>
        </div>
    @else
        <div class="space-y-3">
            <div class="rounded-xl border border-line p-3 dark:border-line-dark">
                <p class="text-xs font-semibold text-success uppercase dark:text-success-dark">One thing you did well</p>
                <p class="mt-1 text-sm text-ink dark:text-ink-dark">{{ $strength }}</p>
            </div>
            <div class="rounded-xl border border-line p-3 dark:border-line-dark">
                <p class="text-xs font-semibold text-ink-faint uppercase dark:text-ink-faint-dark">A good expression you used</p>
                <p class="mt-1 text-sm text-ink dark:text-ink-dark">{{ $expression }}</p>
            </div>
            <div class="rounded-xl border border-line p-3 dark:border-line-dark">
                <p class="text-xs font-semibold text-amber-600 uppercase">One thing to improve</p>
                <p class="mt-1 text-sm text-ink dark:text-ink-dark">{{ $correction }}</p>
            </div>
        </div>

        @unless ($readOnly)
            <button
                wire:click="continueMission"
                class="cursor-pointer rounded-full bg-ink px-5 py-2.5 text-sm font-semibold text-ground transition-colors hover:opacity-85 dark:bg-ink-dark dark:text-ground-dark"
            >
                Continue
            </button>
        @endunless
    @endif
</div>
