<?php

use App\Livewire\Concerns\TracksAiUsage;
use App\Models\AIFeedback;
use App\Models\Evidence;
use App\Models\MissionRun;
use App\Services\GeminiClient;
use Livewire\Component;

new class extends Component
{
    use TracksAiUsage;

    public MissionRun $run;

    public bool $readOnly = false;

    public ?string $strength = null;

    public ?string $expression = null;

    public ?string $correctionOriginal = null;

    public ?string $correctionCorrected = null;

    public ?string $correctionWhy = null;

    public ?string $correctionSuggestion = null;

    public ?string $severity = null;

    public bool $generated = false;

    public ?string $error = null;

    /**
     * mount() used to call generate() unconditionally, so every single page
     * visit — including just revisiting an incomplete step — fired a real
     * Gemini call. It now only loads already-saved feedback in read-only
     * mode; a fresh attempt waits for the learner to press the "get my
     * feedback" button below, which calls generate() explicitly.
     */
    public function mount(): void
    {
        if ($this->readOnly) {
            $data = json_decode($this->run->latestEvidence('ai_feedback_1')?->content_ref ?? '{}', true);
            $this->applyData($data ?? []);
            $this->generated = true;
        }
    }

    public function generate(): void
    {
        $this->error = null;

        try {
            $turns = json_decode($this->conversationEvidence()?->content_ref ?? '[]', true) ?? [];

            $transcript = collect($turns)
                ->map(fn ($t) => "Q: {$t['question']}\nA: {$t['answer']}")
                ->implode("\n\n");

            $raw = app(GeminiClient::class)->chat(
                [['role' => 'user', 'text' => $transcript]],
                systemPrompt: 'You are an encouraging English teacher reviewing the spoken interview answers of '
                    .$this->run->learner->levelDescription().'. '
                    .'Reply with ONLY valid JSON, no markdown fences, no extra text, in exactly this shape: '
                    .'{"strength": "one full sentence, in PERSIAN (Farsi), about one specific thing they did well", '
                    .'"expression": "one full sentence, in PERSIAN (Farsi), pointing out one good English word or '
                    .'phrase they actually used — you can quote the English word/phrase itself inside the Persian '
                    .'sentence", '
                    .'"correction": {'
                    .'"original": "the learner\'s own flawed sentence, quoted exactly as they said it, in ENGLISH", '
                    .'"corrected": "the corrected version of that same sentence, in ENGLISH", '
                    .'"why": "one short sentence, in PERSIAN (Farsi), explaining the underlying grammar or '
                    .'vocabulary rule behind the mistake", '
                    .'"suggestion": "one short, concrete, actionable next step, in PERSIAN (Farsi), e.g. what to '
                    .'practice to strengthen this"'
                    .'}, '
                    .'"severity": "minor or major — how serious this grammar/vocabulary issue is"}. '
                    .'Only the "original" and "corrected" fields are in English (quoting the learner\'s own words); '
                    .'every other field must be written in plain Persian, no English words mixed in unless quoting '
                    .'a specific English word or phrase.'
            );
            $this->recordGeminiCall();

            $data = json_decode(trim($raw), true);

            if (
                ! is_array($data)
                || ! isset($data['strength'], $data['expression'], $data['severity'])
                || ! is_array($data['correction'] ?? null)
                || ! isset($data['correction']['original'], $data['correction']['corrected'], $data['correction']['why'], $data['correction']['suggestion'])
            ) {
                throw new RuntimeException('Unexpected AI response format.');
            }

            $this->applyData($data);
            $this->generated = true;

            if ($this->severity === 'major') {
                $this->run->recordStruggleSignal();
            }
        } catch (Throwable $e) {
            $this->error = "Couldn't get feedback from the AI Instructor: {$e->getMessage()}";
        }
    }

    /** @param array<string, mixed> $data */
    private function applyData(array $data): void
    {
        $this->strength = $data['strength'] ?? null;
        $this->expression = $data['expression'] ?? null;
        $this->severity = $data['severity'] ?? null;

        $correction = $data['correction'] ?? [];
        $this->correctionOriginal = $correction['original'] ?? null;
        $this->correctionCorrected = $correction['corrected'] ?? null;
        $this->correctionWhy = $correction['why'] ?? null;
        $this->correctionSuggestion = $correction['suggestion'] ?? null;
    }

    public function continueMission(): void
    {
        if (! $this->strength) {
            return;
        }

        $correction = [
            'original' => $this->correctionOriginal,
            'corrected' => $this->correctionCorrected,
            'why' => $this->correctionWhy,
            'suggestion' => $this->correctionSuggestion,
        ];

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'ai_feedback_1',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode([
                'strength' => $this->strength,
                'expression' => $this->expression,
                'correction' => $correction,
                'severity' => $this->severity,
            ]),
        ]);

        if ($conversationEvidence = $this->conversationEvidence()) {
            AIFeedback::create([
                'evidence_id' => $conversationEvidence->id,
                'strength' => $this->strength,
                'correction' => json_encode($correction),
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

    @if ($error)
        <div class="rounded-xl border border-red-300 p-3 text-sm text-red-600">
            {{ $error }}
            <button
                wire:click="generate"
                wire:loading.attr="disabled"
                wire:target="generate"
                class="mt-2 inline-flex cursor-pointer items-center gap-1 rounded-full border border-red-300 px-3 py-1 text-xs font-semibold text-red-600 transition-colors hover:bg-red-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-red-800 dark:hover:bg-red-950"
            >
                <span wire:loading.remove wire:target="generate">Try again</span>
                <span wire:loading wire:target="generate">Trying again…</span>
            </button>
        </div>
    @elseif (! $generated)
        <div class="rounded-xl border border-dashed border-line p-6 text-center dark:border-line-dark">
            <div wire:loading.remove wire:target="generate">
                @svg('heroicon-o-light-bulb', 'mx-auto h-6 w-6 text-ink-faint dark:text-ink-faint-dark')
                <p class="mt-2 text-sm text-ink-soft dark:text-ink-soft-dark">Your feedback hasn't been generated yet.</p>
                <button
                    wire:click="generate"
                    class="mt-3 cursor-pointer rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
                >Get my feedback</button>
            </div>
            <div wire:loading wire:target="generate">
                <x-ai-thinking label="Reading your answers…" class="mx-auto max-w-xs" />
            </div>
        </div>
    @else
        <div class="space-y-3">
            <div class="rounded-xl border-l-4 border-success bg-success/5 p-3 dark:border-success-dark dark:bg-success-dark/10">
                <p class="flex items-center gap-1.5 text-xs font-semibold text-success uppercase dark:text-success-dark">
                    @svg('heroicon-o-check-circle', 'h-4 w-4')
                    One thing you did well
                </p>
                <p class="font-fa mt-1 text-sm text-ink dark:text-ink-dark" dir="rtl">{{ $strength }}</p>
            </div>

            <div class="rounded-xl border-l-4 border-accent bg-accent/5 p-3 dark:border-accent-dark dark:bg-accent-dark/10">
                <p class="flex items-center gap-1.5 text-xs font-semibold text-accent uppercase dark:text-accent-dark">
                    @svg('heroicon-o-book-open', 'h-4 w-4')
                    A good expression you used
                </p>
                <p class="font-fa mt-1 text-sm text-ink dark:text-ink-dark" dir="rtl">{{ $expression }}</p>
            </div>

            {{-- Evidence saved under the old flat-string `correction` shape
                 resolves all four sub-fields to null — omit the card
                 entirely rather than showing an alarming red/amber box
                 with no body text. --}}
            @if (collect([$correctionOriginal, $correctionCorrected, $correctionWhy, $correctionSuggestion])->filter(fn ($v) => filled($v))->isNotEmpty())
                @if ($severity === 'major')
                    <div class="rounded-xl border-l-4 border-red-500 bg-red-50 p-3 dark:border-red-800 dark:bg-red-950/30">
                        <p class="flex items-center gap-1.5 text-xs font-semibold text-red-600 uppercase dark:text-red-400">
                            @svg('heroicon-o-exclamation-triangle', 'h-4 w-4')
                            Something to fix
                        </p>
                @else
                    <div class="rounded-xl border-l-4 border-amber-500 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-950/30">
                        <p class="flex items-center gap-1.5 text-xs font-semibold text-amber-600 uppercase dark:text-amber-400">
                            @svg('heroicon-o-exclamation-triangle', 'h-4 w-4')
                            Something to fix
                        </p>
                @endif
                    <p class="mt-2 text-sm text-red-600 line-through decoration-red-500">{{ $correctionOriginal }}</p>
                    <p class="mt-1 text-sm text-success dark:text-success-dark">{{ $correctionCorrected }}</p>
                    <p class="font-fa mt-2 text-sm text-ink dark:text-ink-dark" dir="rtl">{{ $correctionWhy }}</p>
                    <p class="font-fa mt-1 flex items-start gap-1.5 text-sm text-ink-soft dark:text-ink-soft-dark" dir="rtl">
                        @svg('heroicon-o-arrow-trending-up', 'h-4 w-4 shrink-0 mt-0.5')
                        {{ $correctionSuggestion }}
                    </p>
                </div>
            @endif
        </div>

        @unless ($readOnly)
            <x-sticky-bar>
                <button
                    wire:click="continueMission"
                    class="cursor-pointer rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
                >
                    Continue
                </button>
            </x-sticky-bar>
        @endunless
    @endif
</div>
